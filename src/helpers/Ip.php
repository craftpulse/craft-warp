<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\helpers;

use craftpulse\authkit\helpers\Ip as AuthKitIp;

/**
 * Ip coarsens a client address before it is stored, for installations that
 * enable the `anonymizeIp` setting.
 *
 * The implementation moved to [[\craftpulse\authkit\helpers\Ip]] so Warp and
 * every other consumer of the shared session registry coarsen addresses the
 * same way. This subclass is the name Warp published, kept working: every
 * inherited `Ip::anonymize()` call site resolves unchanged, and the behaviour
 * is byte-for-byte the same because there is only one implementation of it.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
abstract class Ip extends AuthKitIp
{
}
