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

use craft\db\Migration;
use craft\db\Table as CraftTable;
use craft\helpers\Db;
use craftpulse\authkit\migrations\Adoption;
use craftpulse\warp\db\Table;

/**
 * Install sets up Warp's database schema on a fresh install and tears it down
 * on uninstall.
 *
 * Warp owns one table: the append-only login log (`warp_logins`) that backs the
 * overview screen. The passwordless token store, the device registry behind the
 * front-end session-management screen, and the shared new-location history all
 * live in the `craftpulse/craft-auth-kit` package and are created by its
 * migrator in [[_adoptAuthKit()]].
 *
 * Auth Kit is a library-shipped Yii module rather than a Craft plugin, so
 * nothing installs it and Craft never runs its migrations: every consumer
 * brings the shared schema up itself from its own install migration. See
 * [[_adoptAuthKit()]].
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class Install extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @throws \Throwable if a pending Auth Kit migration fails to apply
     */
    public function safeUp(): bool
    {
        $this->_adoptAuthKit();
        $this->_createTables();
        $this->_createIndexes();
        $this->_addForeignKeys();

        return true;
    }

    /**
     * @inheritdoc
     *
     * Only Warp's own table is dropped. Auth Kit's schema is shared with every
     * other consumer on the install and outlives any one of them, so
     * uninstalling Warp must never take the token store, the device registry,
     * or the location history with it — which is also why [[Table::SESSIONS]],
     * a name that now points at Auth Kit's table, must not appear here.
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::LOGINS);

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Brings Auth Kit's shared schema up to date on its own `module:auth-kit`
     * migration track, and clears any plugin-era Auth Kit registration the
     * install still carries.
     *
     * A module has no plugin row for Craft to watch, so there is no
     * schemaVersion comparison and nothing applies Auth Kit's migrations on its
     * behalf — its consumers do, here on install and from a dated migration per
     * Auth Kit release that adds one. Applied migrations are recorded on the
     * module track and never re-run, so this is a no-op on an install where
     * another consumer (Warden) already brought the schema up, and safe to
     * reach from a reinstall.
     *
     * The call is [[Adoption::adoptFromPlugin()]] rather than a bare
     * `getMigrator()->up()` because a fresh install is not the same thing as a
     * clean database. A site that once ran the 1.6.x Auth Kit plugin still has
     * the `auth-kit` row in its `plugins` table and the `plugins.auth-kit`
     * project-config entry, and Craft stamps dated migrations as applied
     * *without running them* on a fresh install, so
     * [[m260802_100000_adopt_auth_kit_module]] never gets the chance to clear
     * them. Nothing else would, and the stale registration would keep Auth Kit
     * listed as a plugin indefinitely, which is exactly what the module model
     * exists to prevent.
     *
     * `adoptFromPlugin()` is a strict superset of the bare `up()`: it adopts
     * any plugin-era migration history onto the module track, removes the
     * stale registration (project config events muted, `authkit_*` tables
     * never touched), and then runs the module migrator's `up()` itself. Every
     * step is a no-op where its work is already done, so this is equally
     * correct on a database that never saw the plugin, where it degrades to
     * exactly the `up()` it replaces.
     *
     * The dated migration stays where it is: it remains the upgrade path for a
     * site that already has Warp installed and therefore never re-runs this
     * one. Warden resolves the same problem the same way in its own install
     * migration.
     *
     * @throws \Throwable if a pending Auth Kit migration fails to apply
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _adoptAuthKit(): void
    {
        Adoption::adoptFromPlugin();
    }

    /**
     * Creates Warp's login log, skipping it if it already exists.
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _createTables(): void
    {
        if ($this->db->tableExists(Table::LOGINS)) {
            return;
        }

        $this->createTable(Table::LOGINS, [
            'id' => $this->primaryKey(),
            'userId' => $this->integer()->notNull(),
            'method' => $this->string()->notNull(),
            'userAgent' => $this->string(255),
            'ip' => $this->string(45),
            // Coarse location resolved from the IP when a geo database is
            // present; both stay null with no database. ISO 3166-1 alpha-2.
            'city' => $this->string(255),
            'country' => $this->char(2),
            // Whether this login's location is one the user had never signed
            // in from before. A first-ever login is never "new" (no baseline).
            'isNewLocation' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    /**
     * Creates the indexes backing Warp's query patterns.
     *
     * The overview lists the most recent rows across all users, so
     * `dateCreated` is indexed for the ordered scan; `userId` backs the FK, the
     * per-user lookups a future account screen may want, and the new-location
     * rule's baseline reads.
     *
     * Every call goes through [[craft\db\Migration::createIndexIfMissing()]]
     * rather than a bare `createIndex()`, because this migration's `safeUp()`
     * can run against a database that already carries the `warp_*` tables and
     * their indexes. `createIndex()` given a null name always names the index
     * randomly, so it can never collide with an existing one and never errors:
     * a re-run silently accumulates functionally-identical duplicate indexes
     * until the table crosses MySQL's 64-key-per-table ceiling and every
     * subsequent install fails outright. `createIndexIfMissing()` checks for an
     * existing index over the same columns first, so a re-run is a true no-op.
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _createIndexes(): void
    {
        $this->createIndexIfMissing(Table::LOGINS, ['dateCreated']);
        $this->createIndexIfMissing(Table::LOGINS, ['userId']);
    }

    /**
     * Adds the foreign key tying Warp's login log to Craft's users.
     *
     * A login row is owned by its user — CASCADE, so deleting a user clears
     * their audit trail with them.
     *
     * The call is guarded by [[_addForeignKeyIfMissing()]] for the same reason
     * [[_createIndexes()]] guards every index it creates: this migration's
     * `safeUp()` can run against a database that already carries these tables
     * and their foreign keys, and `addForeignKey()` given a null name generates
     * a random constraint name that can never collide, so a re-run silently
     * duplicates the constraint rather than erroring, up to MySQL's
     * 64-key-per-table ceiling.
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _addForeignKeys(): void
    {
        $this->_addForeignKeyIfMissing(Table::LOGINS, ['userId'], CraftTable::USERS, ['id'], 'CASCADE');
    }

    /**
     * Adds a foreign key only when no constraint already covers the same
     * table and columns.
     *
     * @param string $table the table the constraint is added to
     * @param array<int, string> $columns the local columns
     * @param string $refTable the referenced table
     * @param array<int, string> $refColumns the referenced columns
     * @param string $delete the `ON DELETE` behavior
     *
     * @author CraftPulse
     * @since 5.0.1
     */
    private function _addForeignKeyIfMissing(string $table, array $columns, string $refTable, array $refColumns, string $delete): void
    {
        if (Db::findForeignKey($table, $columns, $this->db) !== null) {
            return;
        }

        $this->addForeignKey(null, $table, $columns, $refTable, $refColumns, $delete, null);
    }
}
