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
 * No tables exist in the scaffold. Warp adds `warp_logins` (Phase 6) and
 * `warp_sessions` (Phase 7) as those feature phases land; the token store lives
 * in the shared `craftpulse/craft-auth-kit` plugin.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
abstract class Table
{
}
