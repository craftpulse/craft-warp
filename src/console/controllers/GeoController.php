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
 * GeoController downloads and installs the optional city MMDB that backs Warp's
 * location awareness. It is the supported way to populate the database: run
 * `warp/geo/refresh` on deploy or on a schedule, from a licence-appropriate URL
 * configured via `config/warp.php`.
 *
 * With no database installed everything degrades silently — no location, no
 * new-location detection, no alerts — so running this is entirely optional.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class GeoController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Downloads a fresh city MMDB from the configured URL and installs it,
     * replacing any current database.
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
