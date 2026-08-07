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

use craftpulse\authkit\services\Geo as AuthKitGeo;
use craftpulse\warp\models\Settings;
use craftpulse\warp\Warp;

/**
 * Geo resolves a client IP to a coarse city and ISO country using an optional
 * city-level MMDB.
 *
 * The lookup, the reader, and the download all moved to
 * [[\craftpulse\authkit\services\Geo]], which reads one database file shared by
 * every consumer on the install rather than a copy per plugin. This subclass is
 * the service Warp published (`Warp::$plugin->getGeo()`), kept working, with one
 * override: the download URL still comes from Warp's own
 * [[Settings::$geoDatabaseUrl]], so an install that pointed `config/warp.php` at
 * a particular licence-appropriate database keeps downloading exactly that.
 *
 * An instance is available via `Warp::$plugin->getGeo()`.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class Geo extends AuthKitGeo
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the resolved URL a fresh city MMDB is downloaded from — Warp's
     * own `geoDatabaseUrl` setting, not the Auth Kit component property.
     *
     * @return string
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getDatabaseUrl(): string
    {
        $settings = Warp::$plugin->getSettings();
        assert($settings instanceof Settings);

        return $settings->getGeoDatabaseUrl();
    }
}
