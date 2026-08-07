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

use craftpulse\authkit\helpers\Device as AuthKitDevice;

/**
 * Device turns a raw user-agent string into a coarse, human-friendly label like
 * "Chrome on macOS" — the name a member sees against each active session.
 *
 * The implementation moved to [[\craftpulse\authkit\helpers\Device]] so every
 * consumer of the shared session registry labels a device the same way. This
 * subclass is the name Warp published, kept working: the inherited
 * `Device::label()`, `Device::type()`, and `TYPE_*` constants all resolve
 * unchanged.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
abstract class Device extends AuthKitDevice
{
}
