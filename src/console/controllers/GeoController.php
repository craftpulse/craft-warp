<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\console\controllers;

use craftpulse\warp\Warp;
use Throwable;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Installs and refreshes the optional city geo database Warp reads locations from.
 *
 * Run warp/geo/refresh on deploy or on a schedule. The database is shared with
 * every other CraftPulse security plugin on the install, so refreshing it here
 * refreshes it for all of them; auth-kit/geo/refresh does exactly the same
 * thing, differing only in which plugin's configured URL it downloads from.
 *
 * The default download URL is a keyless mirror of MaxMind's GeoLite2 City
 * database. GeoLite2 is free but not public domain: crediting MaxMind and
 * refreshing at least every 30 days (destroying the copy you replace) are
 * conditions of using it. Refreshing overwrites in place, so a scheduled run
 * satisfies both halves. Set geoDatabaseUrl in config/warp.php to use a
 * differently licensed database.
 *
 * With no database installed everything degrades silently (no location, no
 * new-location detection, no alerts), so running this is entirely optional.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class GeoController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Downloads a fresh city database from the configured URL and installs it, replacing any current database.
     *
     * @return int a `yii\console\ExitCode` value
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function actionRefresh(): int
    {
        try {
            Warp::$plugin->getGeo()->refresh();
        } catch (Throwable $e) {
            $this->stderr('Could not refresh the geo database: ' . $e->getMessage() . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout('Geo database refreshed.' . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }
}
