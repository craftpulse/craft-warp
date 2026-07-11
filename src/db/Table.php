<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\db;

/**
 * Table defines the database table names owned by Warp.
 *
 * Warp owns `warp_logins` (Phase 6, the passwordless login log backing the
 * overview screen) and `warp_sessions` (Phase 7, the device registry joined
 * against core's `{{%sessions}}` for the session-management screen). The token
 * store lives in the shared `craftpulse/craft-auth-kit` plugin.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
abstract class Table
{
    // Const Properties
    // =========================================================================

    /**
     * @since 5.0.0
     */
    public const LOGINS = '{{%warp_logins}}';

    /**
     * @since 5.0.0
     */
    public const SESSIONS = '{{%warp_sessions}}';
}
