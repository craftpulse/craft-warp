<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Covers the camelCase-to-kebab-case permission rename migration. Craft
 * lowercases a permission name both when it stores it and when it checks it, so a
 * code-only rename would silently stop matching every existing grant (and fail
 * invisibly, since admins hold everything implicitly). These tests fixture a
 * pre-rename install (the lowercased camelCase name granted to a user, to a
 * group, and listed in a group's project config), run the migration, and assert
 * the grants land on the kebab name, the old row is gone, the two grant sets are
 * unioned when the kebab name already carries grants, a UID-suffixed handle keeps
 * its suffix, Warp's own camelCase session key namespace is left alone, Craft-owned
 * and third-party handles are untouched, the migration is idempotent, and it
 * reverses cleanly on the way down.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\User;
use craft\helpers\StringHelper;
use craft\records\UserGroup as UserGroupRecord;
use craftpulse\warp\controllers\OverviewController;
use craftpulse\warp\controllers\SettingsController;
use craftpulse\warp\migrations\m260729_200000_kebab_case_permissions;

/**
 * Grants a permission by its raw stored name, bypassing the UserPermissions
 * service so a pre-rename (camelCase, lowercased) name can be fixtured. The
 * service filters orphaned handles, which is exactly what makes it unusable here.
 *
 * @param string $name
 * @param int|null $userId
 * @param int|null $groupId
 * @return void
 */
function warpGrantRawPermission(string $name, ?int $userId = null, ?int $groupId = null): void
{
    $db = Craft::$app->getDb();
    $db->createCommand()->insert(CraftTable::USERPERMISSIONS, ['name' => $name])->execute();
    $permissionId = (int)$db->getLastInsertID(CraftTable::USERPERMISSIONS);

    if ($userId !== null) {
        $db->createCommand()
            ->insert(CraftTable::USERPERMISSIONS_USERS, ['permissionId' => $permissionId, 'userId' => $userId])
            ->execute();
    }

    if ($groupId !== null) {
        $db->createCommand()
            ->insert(CraftTable::USERPERMISSIONS_USERGROUPS, ['permissionId' => $permissionId, 'groupId' => $groupId])
            ->execute();
    }
}

/**
 * Returns the permission names granted directly to a user.
 *
 * @param int $userId
 * @return string[]
 */
function warpUserPermissionNames(int $userId): array
{
    return (new Query())
        ->select(['p.name'])
        ->from(['p' => CraftTable::USERPERMISSIONS])
        ->innerJoin(['pu' => CraftTable::USERPERMISSIONS_USERS], '[[pu.permissionId]] = [[p.id]]')
        ->where(['pu.userId' => $userId])
        ->column();
}

/**
 * Returns the permission names granted to a user group.
 *
 * @param int $groupId
 * @return string[]
 */
function warpGroupPermissionNames(int $groupId): array
{
    return (new Query())
        ->select(['p.name'])
        ->from(['p' => CraftTable::USERPERMISSIONS])
        ->innerJoin(['pg' => CraftTable::USERPERMISSIONS_USERGROUPS], '[[pg.permissionId]] = [[p.id]]')
        ->where(['pg.groupId' => $groupId])
        ->column();
}

/**
 * Returns every stored permission name carrying Warp's prefix, whether or not
 * this rename covers it.
 *
 * @return string[]
 */
function warpStoredPermissionNames(): array
{
    return (new Query())
        ->select(['name'])
        ->from([CraftTable::USERPERMISSIONS])
        ->where(['like', 'name', 'warp:'])
        ->column();
}

/**
 * Creates a bare user for a grant fixture.
 *
 * @return User
 * @throws Throwable
 * @throws \craft\errors\ElementNotFoundException
 * @throws \yii\base\Exception
 */
function warpPermissionUser(): User
{
    $suffix = bin2hex(random_bytes(4));

    $user = new User();
    $user->username = "warp_perm_{$suffix}@warp-test.example";
    $user->email = $user->username;

    if (!Craft::$app->getElements()->saveElement($user)) {
        throw new RuntimeException('Could not save permission test user.');
    }

    return $user;
}

/**
 * Creates a throwaway user group record. Written straight to the record so the
 * fixture needs no particular Craft edition, and so the group's permission list
 * stays under this test's control.
 *
 * @return UserGroupRecord
 */
function warpPermissionGroup(): UserGroupRecord
{
    $handle = 'warpPerm' . bin2hex(random_bytes(4));

    $group = new UserGroupRecord();
    $group->name = $handle;
    $group->handle = $handle;
    $group->uid = StringHelper::UUID();
    $group->save(false);

    return $group;
}

it('moves a user grant from the camelCase name to the kebab name', function() {
    $user = warpPermissionUser();
    warpGrantRawPermission('warp:managesettings', userId: (int)$user->id);

    expect(warpUserPermissionNames((int)$user->id))->toContain('warp:managesettings');

    (new m260729_200000_kebab_case_permissions())->safeUp();

    expect(warpUserPermissionNames((int)$user->id))
        ->toContain(SettingsController::PERMISSION_MANAGE_SETTINGS)
        ->not->toContain('warp:managesettings');
});

it('moves a group grant onto the kebab name and drops the old row', function() {
    $group = warpPermissionGroup();
    warpGrantRawPermission('warp:viewoverview', groupId: (int)$group->id);

    (new m260729_200000_kebab_case_permissions())->safeUp();

    expect(warpGroupPermissionNames((int)$group->id))
        ->toContain(OverviewController::PERMISSION_VIEW_OVERVIEW)
        ->not->toContain('warp:viewoverview')
        ->and(warpStoredPermissionNames())->not->toContain('warp:viewoverview');
});

it('unions the grant sets when the kebab name already carries grants of its own', function() {
    $oldGrantee = warpPermissionUser();
    $newGrantee = warpPermissionUser();

    warpGrantRawPermission('warp:managesettings', userId: (int)$oldGrantee->id);
    warpGrantRawPermission(SettingsController::PERMISSION_MANAGE_SETTINGS, userId: (int)$newGrantee->id);

    (new m260729_200000_kebab_case_permissions())->safeUp();

    expect(warpUserPermissionNames((int)$oldGrantee->id))->toBe([SettingsController::PERMISSION_MANAGE_SETTINGS])
        ->and(warpUserPermissionNames((int)$newGrantee->id))->toBe([SettingsController::PERMISSION_MANAGE_SETTINGS]);
});

it('preserves a UID suffix on a parameterized handle', function() {
    // Warp stores both handles bare today. The matcher still renames only the base
    // segment and keeps whatever follows it, the way Craft stores
    // `saveEntries:<sectionUid>`, so introducing a per-entity handle later cannot
    // silently drop its grants.
    $user = warpPermissionUser();
    $uid = StringHelper::UUID();
    warpGrantRawPermission("warp:viewoverview:{$uid}", userId: (int)$user->id);

    (new m260729_200000_kebab_case_permissions())->safeUp();

    expect(warpUserPermissionNames((int)$user->id))
        ->toContain(OverviewController::PERMISSION_VIEW_OVERVIEW . ':' . $uid)
        ->not->toContain("warp:viewoverview:{$uid}");
});

it('leaves Warp\'s camelCase session key namespace untouched', function() {
    // These are session keys, never permissions, and both are camelCase. A
    // case-insensitive sweep over the whole `warp:` prefix would corrupt them, so
    // the migration's scan is composed from the rename map's own keys instead.
    // Fixtured here as permission rows precisely because that is the one table the
    // rename touches.
    $user = warpPermissionUser();
    warpGrantRawPermission('warp:requestedEmail', userId: (int)$user->id);
    warpGrantRawPermission('warp:showPasskeyNudge', userId: (int)$user->id);

    (new m260729_200000_kebab_case_permissions())->safeUp();

    expect(warpUserPermissionNames((int)$user->id))
        ->toContain('warp:requestedEmail')
        ->toContain('warp:showPasskeyNudge');
});

it('rewrites a group project-config permission list, leaving its other handles alone', function() {
    $projectConfig = Craft::$app->getProjectConfig();
    $path = sprintf('users.groups.%s.permissions', StringHelper::UUID());

    // Muted: the fixture writes a group permission list directly, with no
    // corresponding user group, so Craft's own group handler has nothing to
    // reconcile. The migration mutes its own writes for the same reason.
    $muteEvents = $projectConfig->muteEvents;
    $projectConfig->muteEvents = true;
    $projectConfig->set($path, ['accesscp', 'warp:managesettings']);

    try {
        (new m260729_200000_kebab_case_permissions())->safeUp();

        expect($projectConfig->get($path))
            ->toContain(SettingsController::PERMISSION_MANAGE_SETTINGS)
            ->toContain('accesscp')
            ->not->toContain('warp:managesettings');
    } finally {
        $projectConfig->remove($path);
        $projectConfig->muteEvents = $muteEvents;
    }
});

it('is idempotent: running twice does not duplicate or drop grants', function() {
    $user = warpPermissionUser();
    $group = warpPermissionGroup();
    warpGrantRawPermission('warp:viewoverview', userId: (int)$user->id, groupId: (int)$group->id);

    $migration = new m260729_200000_kebab_case_permissions();
    $migration->safeUp();
    $migration->safeUp();

    expect(warpUserPermissionNames((int)$user->id))->toBe([OverviewController::PERMISSION_VIEW_OVERVIEW])
        ->and(warpGroupPermissionNames((int)$group->id))->toBe([OverviewController::PERMISSION_VIEW_OVERVIEW]);
});

it('leaves an install with no camelCase Warp grants untouched', function() {
    $before = warpStoredPermissionNames();
    sort($before);

    (new m260729_200000_kebab_case_permissions())->safeUp();

    $after = warpStoredPermissionNames();
    sort($after);

    expect($after)->toBe($before);
});

it('reverses the rename on the way down', function() {
    $user = warpPermissionUser();
    warpGrantRawPermission('warp:managesettings', userId: (int)$user->id);

    $migration = new m260729_200000_kebab_case_permissions();
    $migration->safeUp();

    expect(warpUserPermissionNames((int)$user->id))->toContain(SettingsController::PERMISSION_MANAGE_SETTINGS);

    $migration->safeDown();

    expect(warpUserPermissionNames((int)$user->id))
        ->toContain('warp:managesettings')
        ->not->toContain(SettingsController::PERMISSION_MANAGE_SETTINGS);
});

it('does not touch permissions owned by Craft or other plugins', function() {
    $user = warpPermissionUser();
    warpGrantRawPermission('accesscp', userId: (int)$user->id);
    warpGrantRawPermission('accessplugin-warp', userId: (int)$user->id);
    warpGrantRawPermission('utility:warp-geo', userId: (int)$user->id);

    (new m260729_200000_kebab_case_permissions())->safeUp();

    expect(warpUserPermissionNames((int)$user->id))
        ->toContain('accesscp')
        ->toContain('accessplugin-warp')
        ->toContain('utility:warp-geo');
});
