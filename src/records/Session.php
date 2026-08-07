<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\records;

use craftpulse\authkit\records\Session as AuthKitSession;

/**
 * Session record maps the shared device registry Warp keeps alongside core's
 * `{{%sessions}}` table, which stores only a token and its timestamps and so
 * carries no device information of its own.
 *
 * The table moved out of Warp and into the shared
 * `craftpulse/craft-auth-kit` module — see
 * [[\craftpulse\authkit\records\Session]] for the row's shape and the reasoning.
 * This subclass is the name Warp published, kept working and pointed at the
 * shared table, so every `Session::find()` call site reads the same rows it
 * always did.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class Session extends AuthKitSession
{
}
