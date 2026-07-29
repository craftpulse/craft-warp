<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table;
use craft\services\ProjectConfig as ProjectConfigService;
use craftpulse\warp\controllers\OverviewController;
use craftpulse\warp\controllers\SettingsController;

/**
 * Renames both Warp permission handles from camelCase to kebab-case
 * (`warp:manageSettings` becomes `warp:manage-settings`), carrying existing
 * grants over so nobody loses access.
 *
 * Craft lowercases a permission name both when it stores it and when it checks it
 * (see [[\craft\services\UserPermissions]]), so the database and project config
 * hold `warp:managesettings` while the new code checks `warp:manage-settings`. A
 * code-only rename would therefore stop matching silently, and it would fail
 * invisibly: an admin holds every permission implicitly and would notice nothing,
 * while every non-admin grantee would lose the screen.
 *
 * For each renamed handle the migration moves the user grants
 * ([[Table::USERPERMISSIONS_USERS]]), the group grants
 * ([[Table::USERPERMISSIONS_USERGROUPS]]), and the project-config group lists
 * (`users.groups.<uid>.permissions`) onto the new name, then drops the old
 * permission row so no dead handle is left behind. Where a row already carries
 * the new name, the two grant sets are unioned rather than replaced, so a grant
 * that somehow already sits on the new name cannot be dropped.
 *
 * The stored-name scan is composed from the rename map's own keys — never a
 * blanket `warp:%` sweep. Warp namespaces two session keys under the same prefix
 * (`warp:requestedEmail`, `warp:showPasskeyNudge`), both camelCase, and a
 * case-insensitive prefix sweep would rewrite them if they ever appeared in this
 * table. A map key matches either the whole name or the segment before a `:`
 * suffix, so a per-entity handle (the way Craft stores `saveEntries:<uid>`) would
 * keep its suffix if Warp ever grew one. Neither of Warp's handles is stored with
 * a suffix today.
 *
 * The project config is written with events muted and the read-only flag
 * temporarily lifted (the same pairing [[\craft\services\ProjectConfig::rebuild()]]
 * uses): the grant rows are rewritten here directly, so the group-permission
 * change handler has nothing left to reconcile, and the rename must land even on
 * an install running with `allowAdminChanges` disabled.
 *
 * The migration is idempotent. A handle is only touched when a row still carries
 * its old name, and a project-config list is only rewritten when it still carries
 * an old name.
 *
 * @author    CraftPulse
 * @package   Warp
 * @since     5.0.0
 */
class m260729_200000_kebab_case_permissions extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return bool
     *
     * @throws \Throwable
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\NotSupportedException
     * @throws \yii\db\Exception
     * @throws \yii\web\ServerErrorHttpException
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function safeUp(): bool
    {
        $this->_renamePermissions($this->_map());

        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool
     *
     * @throws \Throwable
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\NotSupportedException
     * @throws \yii\db\Exception
     * @throws \yii\web\ServerErrorHttpException
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function safeDown(): bool
    {
        $this->_renamePermissions(array_flip($this->_map()));

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * The rename map, lowercased on both sides the way Craft stores permission
     * names. Keyed by the old camelCase handle, valued with the new kebab-case
     * handle taken from the controller constants that now own them.
     *
     * @return array<string, string>
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _map(): array
    {
        $map = [
            'warp:manageSettings' => SettingsController::PERMISSION_MANAGE_SETTINGS,
            'warp:viewOverview' => OverviewController::PERMISSION_VIEW_OVERVIEW,
        ];

        $lowercased = [];

        foreach ($map as $old => $new) {
            $lowercased[strtolower($old)] = strtolower($new);
        }

        return $lowercased;
    }

    /**
     * Moves every grant of each old permission name onto its new name, in the
     * grant tables and in the project-config group lists, then removes the old
     * permission rows.
     *
     * @param array<string, string> $map Old permission name to new permission name, both lowercased.
     * @return void
     *
     * @throws \Throwable
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\NotSupportedException
     * @throws \yii\db\Exception
     * @throws \yii\web\ServerErrorHttpException
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _renamePermissions(array $map): void
    {
        $this->_renameGrants($map);
        $this->_rewriteProjectConfig($map);
    }

    /**
     * Resolves a stored permission name to its renamed form, or null when the map
     * does not cover it.
     *
     * A map key matches either the whole name or the part before a `:` separator,
     * so a per-entity handle would keep its entity UID.
     *
     * @param string $storedName The permission name as stored (lowercased by Craft).
     * @param array<string, string> $map Old permission name to new permission name, both lowercased.
     * @return string|null
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _renamedName(string $storedName, array $map): ?string
    {
        $storedName = strtolower($storedName);

        if (isset($map[$storedName])) {
            return $map[$storedName];
        }

        foreach ($map as $oldName => $newName) {
            if (str_starts_with($storedName, $oldName . ':')) {
                return $newName . substr($storedName, strlen($oldName));
            }
        }

        return null;
    }

    /**
     * Repoints the user and group grants of every stored permission the map covers
     * at its new name, dropping the old rows. A no-op for a name that has already
     * been renamed, so the migration can run twice.
     *
     * The scan condition is built from the map's own keys rather than Warp's
     * `warp:` prefix, so the plugin's camelCase session key namespace cannot be
     * reached even in principle.
     *
     * @param array<string, string> $map Old permission name to new permission name, both lowercased.
     * @return void
     *
     * @throws \yii\db\Exception
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _renameGrants(array $map): void
    {
        $condition = ['or'];

        foreach (array_keys($map) as $oldName) {
            $condition[] = ['name' => $oldName];
            $condition[] = ['like', 'name', $oldName . ':%', false];
        }

        $rows = (new Query())
            ->select(['id', 'name'])
            ->from([Table::USERPERMISSIONS])
            ->where($condition)
            ->all($this->db);

        foreach ($rows as $row) {
            $newName = $this->_renamedName((string)$row['name'], $map);

            if ($newName === null) {
                continue;
            }

            $this->_moveGrants((int)$row['id'], $newName);
        }
    }

    /**
     * Moves one permission row's user and group grants onto the new name. Any row
     * already carrying the new name is folded in rather than overwritten: both
     * grant sets are unioned, so a pre-existing grant on the new name survives.
     *
     * @param int $oldPermissionId
     * @param string $newPermissionName
     * @return void
     *
     * @throws \yii\db\Exception
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _moveGrants(int $oldPermissionId, string $newPermissionName): void
    {
        $newPermissionId = (new Query())
            ->select(['id'])
            ->from([Table::USERPERMISSIONS])
            ->where(['name' => $newPermissionName])
            ->scalar($this->db);

        $permissionIds = [$oldPermissionId];

        if ($newPermissionId !== false && $newPermissionId !== null) {
            $permissionIds[] = (int)$newPermissionId;
        }

        $userIds = $this->_grantees(Table::USERPERMISSIONS_USERS, 'userId', $permissionIds);
        $groupIds = $this->_grantees(Table::USERPERMISSIONS_USERGROUPS, 'groupId', $permissionIds);

        // Drop both rows first (cascading their grants away), then reinsert the new
        // name, so the insert cannot collide with a half-applied run.
        $this->delete(Table::USERPERMISSIONS, ['id' => $permissionIds]);

        $this->insert(Table::USERPERMISSIONS, ['name' => $newPermissionName]);
        $permissionId = $this->db->getLastInsertID(Table::USERPERMISSIONS);

        if ($userIds !== []) {
            $this->batchInsert(
                Table::USERPERMISSIONS_USERS,
                ['permissionId', 'userId'],
                array_map(static fn(int $userId): array => [$permissionId, $userId], $userIds),
            );
        }

        if ($groupIds !== []) {
            $this->batchInsert(
                Table::USERPERMISSIONS_USERGROUPS,
                ['permissionId', 'groupId'],
                array_map(static fn(int $groupId): array => [$permissionId, $groupId], $groupIds),
            );
        }
    }

    /**
     * Returns the distinct grantee ids held against the given permission rows.
     *
     * @param string $table The grant join table.
     * @param string $column The grantee id column on that table.
     * @param array<int, int> $permissionIds
     * @return array<int, int>
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _grantees(string $table, string $column, array $permissionIds): array
    {
        $ids = (new Query())
            ->select([$column])
            ->from([$table])
            ->where(['permissionId' => $permissionIds])
            ->column($this->db);

        return array_values(array_unique(array_map(static fn(mixed $id): int => (int)$id, $ids)));
    }

    /**
     * Swaps the old permission names for the new ones in every user group's
     * project-config permission list, keeping Craft's stored shape (lowercased,
     * sorted ascending, sequentially keyed).
     *
     * @param array<string, string> $map Old permission name to new permission name, both lowercased.
     * @return void
     *
     * @throws \Throwable
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\NotSupportedException
     * @throws \yii\web\ServerErrorHttpException
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _rewriteProjectConfig(array $map): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $groups = $projectConfig->get(ProjectConfigService::PATH_USER_GROUPS) ?? [];

        if (!is_array($groups)) {
            return;
        }

        $muteEvents = $projectConfig->muteEvents;
        $readOnly = $projectConfig->readOnly;
        $projectConfig->muteEvents = true;
        $projectConfig->readOnly = false;

        try {
            foreach ($groups as $uid => $group) {
                if (!is_array($group)) {
                    continue;
                }

                $permissions = $group['permissions'] ?? [];

                if (!is_array($permissions) || $permissions === []) {
                    continue;
                }

                $renamed = array_map(
                    fn(mixed $permission): mixed => is_string($permission)
                        ? ($this->_renamedName($permission, $map) ?? $permission)
                        : $permission,
                    $permissions,
                );

                if ($renamed === $permissions) {
                    continue;
                }

                $renamed = array_values(array_unique($renamed));
                sort($renamed);

                $projectConfig->set(
                    sprintf('%s.%s.permissions', ProjectConfigService::PATH_USER_GROUPS, $uid),
                    $renamed,
                    'Rename Warp permission handles to kebab-case',
                );
            }
        } finally {
            $projectConfig->muteEvents = $muteEvents;
            $projectConfig->readOnly = $readOnly;
        }
    }
}
