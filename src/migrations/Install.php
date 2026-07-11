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

/**
 * Install sets up Warp's database schema on a fresh install and tears it down
 * on uninstall.
 *
 * Warp owns no tables in the scaffold — the passwordless token store lives in
 * the shared `craftpulse/craft-auth-kit` plugin. The login log (`warp_logins`,
 * Phase 6) and session registry (`warp_sessions`, Phase 7) are created here as
 * those feature phases land. Warp is unreleased throughout, so this migration
 * is edited freely per phase (reinstall in the playground) until 5.0.0 tags.
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
        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        return true;
    }
}
