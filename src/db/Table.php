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

use craftpulse\authkit\db\Table as AuthKitTable;

/**
 * Table defines the database table names Warp reads and writes.
 *
 * Warp owns exactly one of them: `warp_logins`, the passwordless login log
 * backing the overview screen. The device registry behind the front-end
 * session-management screen moved to the shared `craftpulse/craft-auth-kit`
 * module, alongside the token store — two security plugins each keeping their
 * own registry meant two device lists and two new-location emails for one
 * sign-in.
 *
 * [[SESSIONS]] stays as the name for that registry so every existing call site
 * keeps resolving, but it now points at Auth Kit's shared table rather than a
 * `warp_sessions` of Warp's own; the rows were carried over by
 * [[\craftpulse\warp\migrations\m260807_000001_adopt_auth_kit_sessions]].
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
     * The shared device registry, owned by Auth Kit since Warp 5.0.1.
     *
     * @since 5.0.0
     */
    public const SESSIONS = AuthKitTable::SESSIONS;
}
