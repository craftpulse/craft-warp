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
use craftpulse\warp\db\Table;

/**
 * Install sets up Warp's database schema on a fresh install and tears it down
 * on uninstall.
 *
 * Warp owns the append-only login log (`warp_logins`, Phase 6) that backs the
 * overview screen — the passwordless token store lives in the shared
 * `craftpulse/craft-auth-kit` plugin. The session registry (`warp_sessions`,
 * Phase 7) is created here as that feature phase lands. Warp is unreleased
 * throughout, so this migration is edited freely per phase (reinstall in the
 * playground) until 5.0.0 tags.
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
     */
    public function safeUp(): bool
    {
        $this->_createTables();
        $this->_createIndexes();
        $this->_addForeignKeys();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::LOGINS);

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Creates Warp's tables, skipping any that already exist.
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _createTables(): void
    {
        if (!$this->db->tableExists(Table::LOGINS)) {
            $this->createTable(Table::LOGINS, [
                'id' => $this->primaryKey(),
                'userId' => $this->integer()->notNull(),
                'method' => $this->string()->notNull(),
                'userAgent' => $this->string(255),
                'ip' => $this->string(45),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }
    }

    /**
     * Creates the indexes backing Warp's query patterns.
     *
     * The overview lists the most recent rows across all users, so
     * `dateCreated` is indexed for the ordered scan; `userId` backs the FK and
     * the per-user lookups a future account screen may want.
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _createIndexes(): void
    {
        $this->createIndex(null, Table::LOGINS, ['dateCreated']);
        $this->createIndex(null, Table::LOGINS, ['userId']);
    }

    /**
     * Adds the foreign keys tying Warp's login log to Craft's users.
     *
     * A login row is owned by its user — CASCADE, so deleting a user clears
     * their audit trail with them.
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _addForeignKeys(): void
    {
        $this->addForeignKey(null, Table::LOGINS, ['userId'], CraftTable::USERS, ['id'], 'CASCADE', null);
    }
}
