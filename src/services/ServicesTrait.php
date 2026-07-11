<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\services;

/**
 * ServicesTrait owns Warp's service component registration and typed accessors.
 *
 * Components are declared in [[config()]], which Craft merges into the plugin's
 * Yii config during construction. Each service gains a typed `getX(): X`
 * accessor that narrows Yii's `?object` return for static analysis, and a
 * `@property-read` tag on this trait's docblock for property-style access —
 * never duplicated on the main plugin class.
 *
 * The component list is empty in the scaffold; services (Passwordless,
 * Registration, Logins, Sessions) are registered as their feature phases land.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
trait ServicesTrait
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the component config Craft merges into the plugin's application config.
     *
     * @return array<string, mixed>
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public static function config(): array
    {
        return [
            'components' => [],
        ];
    }
}
