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

use Craft;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craftpulse\warp\models\Settings;
use craftpulse\warp\Warp;
use MaxMind\Db\Reader;
use Throwable;
use yii\base\Component;
use yii\base\Exception;

/**
 * Geo resolves a client IP to a coarse city and ISO country using an optional
 * city-level MMDB stored under Craft's storage path. It mirrors Relay's
 * optional-database pattern: everything here is null-safe, so with no database
 * present the lookup returns nulls and a login's location columns simply stay
 * empty. No MMDB means no location, no new-location detection, and no alerts.
 *
 * The IP is read only to derive the location and is never persisted by this
 * service. The database is refreshed from a configurable URL by [[refresh()]]
 * (driven by the console command) which streams to a temp file and atomically
 * renames it into place, so a lookup never sees a partial download.
 *
 * An instance is available via `Warp::$plugin->getGeo()`.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class Geo extends Component
{
    // Private Properties
    // =========================================================================

    /**
     * @var Reader|null The memoized MMDB reader, or null when no database is loaded.
     */
    private ?Reader $_reader = null;

    /**
     * @var bool Whether a reader load has been attempted this request.
     */
    private bool $_readerLoaded = false;

    // Public Methods
    // =========================================================================

    /**
     * Extracts the city name from an MMDB record, or null.
     *
     * Handles the two common city-database shapes: the flat `city_name` used by
     * the sapics/ip-location-db databases, and the nested `city.names.en` used
     * by MaxMind GeoIP2/GeoLite2 databases, so either can be dropped in.
     *
     * @param array<string, mixed>|null $record the raw MMDB record
     * @return string|null the city name, or null
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public static function cityFromRecord(?array $record): ?string
    {
        if ($record === null) {
            return null;
        }

        $city = $record['city_name']
            ?? ($record['city']['names']['en'] ?? ($record['city'] ?? null));

        return is_string($city) && trim($city) !== '' ? $city : null;
    }

    /**
     * Extracts the ISO country code from an MMDB record, or null.
     *
     * Handles both common shapes: the flat `country_code` used by the
     * sapics/ip-location-db databases, and the nested `country.iso_code` used by
     * MaxMind GeoIP2/GeoLite2 databases.
     *
     * @param array<string, mixed>|null $record the raw MMDB record
     * @return string|null the uppercase ISO 3166-1 alpha-2 code, or null
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public static function countryFromRecord(?array $record): ?string
    {
        if ($record === null) {
            return null;
        }

        $code = $record['country_code'] ?? ($record['country']['iso_code'] ?? null);

        return is_string($code) && strlen($code) === 2 ? strtoupper($code) : null;
    }

    /**
     * Installs a city MMDB from a source file, replacing any current database.
     *
     * Copies to a temp file alongside the target and atomically renames it into
     * place, so a concurrent lookup never observes a half-written database. The
     * memoized reader is dropped so the next lookup opens the new database.
     *
     * @param string $sourcePath the readable MMDB to install
     * @throws Exception if the storage directory cannot be created
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function install(string $sourcePath): void
    {
        $path = $this->path();
        FileHelper::createDirectory(dirname($path));

        $temp = $path . '.' . StringHelper::UUID() . '.tmp';
        copy($sourcePath, $temp);
        rename($temp, $path);

        $this->_reader = null;
        $this->_readerLoaded = false;
    }

    /**
     * Returns whether a city database is present.
     *
     * @return bool
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function isAvailable(): bool
    {
        return is_file($this->path());
    }

    /**
     * Resolves an IP address to a coarse city and ISO country.
     *
     * Returns `['city' => null, 'country' => null]` for an empty IP, an absent or
     * unreadable database, an IP the database does not cover (private ranges,
     * unallocated space), or any reader error — so a lookup can never break the
     * login write.
     *
     * @param string|null $ip the client IP, read only to derive the location
     * @return array{city: string|null, country: string|null}
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function lookup(?string $ip): array
    {
        $empty = ['city' => null, 'country' => null];

        if ($ip === null || $ip === '') {
            return $empty;
        }

        $reader = $this->_reader();

        if ($reader === null) {
            return $empty;
        }

        try {
            $record = $reader->get($ip);
        } catch (Throwable) {
            return $empty;
        }

        $record = is_array($record) ? $record : null;

        return [
            'city' => self::cityFromRecord($record),
            'country' => self::countryFromRecord($record),
        ];
    }

    /**
     * Returns the storage path the city MMDB lives at.
     *
     * @return string
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function path(): string
    {
        return Craft::$app->getPath()->getStoragePath()
            . DIRECTORY_SEPARATOR . 'warp'
            . DIRECTORY_SEPARATOR . 'geo'
            . DIRECTORY_SEPARATOR . 'city.mmdb';
    }

    /**
     * Downloads a fresh city MMDB from the configured URL and installs it.
     *
     * The download streams to a temp file first; only a complete download is
     * atomically moved into place by [[install()]].
     *
     * @throws Exception if the URL is not configured or the download fails
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function refresh(): void
    {
        $settings = Warp::$plugin->getSettings();
        assert($settings instanceof Settings);

        $url = trim($settings->getGeoDatabaseUrl());

        if ($url === '') {
            throw new Exception('No geo database URL is configured.');
        }

        $temp = (string)tempnam(Craft::$app->getPath()->getTempPath(), 'warp-geo');

        try {
            Craft::createGuzzleClient()->get($url, ['sink' => $temp]);
            $this->install($temp);
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the memoized MMDB reader, loading it once per request.
     *
     * @return Reader|null the reader, or null when no readable database exists
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _reader(): ?Reader
    {
        if ($this->_readerLoaded) {
            return $this->_reader;
        }

        $this->_readerLoaded = true;

        if (is_file($this->path())) {
            try {
                $this->_reader = new Reader($this->path());
            } catch (Throwable) {
                $this->_reader = null;
            }
        }

        return $this->_reader;
    }
}
