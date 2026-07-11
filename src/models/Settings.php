<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\models;

use craft\base\Model;

/**
 * Settings holds Warp's passwordless tunables. Auth Kit owns the token store
 * and exposes these as service properties; Warp is the product that owns the
 * configuration UX and pushes the values into Auth Kit at plugin init — see
 * `PluginTrait::_configureAuthKit()`.
 *
 * The model is empty in the scaffold; the tunables (token TTL, OTP digits,
 * per-email throttle, registration, passkey nudge) are added as their feature
 * phases land.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class Settings extends Model
{
}
