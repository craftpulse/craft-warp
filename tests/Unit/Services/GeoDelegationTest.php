<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Tests for what stayed behind when geo enrichment moved into Auth Kit: Warp's
 * service is still the one `Warp::$plugin->getGeo()` hands out, it still reads
 * the shared database, and the refresh URL still comes from Warp's own
 * `geoDatabaseUrl` setting rather than Auth Kit's component property — so an
 * install that pointed `config/warp.php` at a particular licence-appropriate
 * database keeps downloading exactly that.
 *
 * The lookup itself, the record-shape extractors, and the download are covered
 * in Auth Kit, where they now live.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\authkit\AuthKit;
use craftpulse\authkit\services\Geo as AuthKitGeo;
use craftpulse\warp\models\Settings;
use craftpulse\warp\services\Geo;
use craftpulse\warp\Warp;

it('is the service the plugin hands out, and an Auth Kit geo service', function() {
    expect(Warp::$plugin->getGeo())->toBeInstanceOf(Geo::class)
        ->toBeInstanceOf(AuthKitGeo::class);
});

it('reads the one database shared with every other consumer', function() {
    expect(Warp::$plugin->getGeo()->path())->toBe(AuthKit::getInstance()->getGeo()->path());
});

it('takes its refresh URL from Warp settings, not the Auth Kit property', function() {
    $settings = Warp::$plugin->getSettings();
    $original = $settings->geoDatabaseUrl;
    $settings->geoDatabaseUrl = 'https://example.test/warp-own.mmdb';

    try {
        expect(Warp::$plugin->getGeo()->getDatabaseUrl())->toBe('https://example.test/warp-own.mmdb');
    } finally {
        $settings->geoDatabaseUrl = $original;
    }
});

it('defaults the refresh URL to a database that actually exists', function() {
    // The 5.0.0 default named an npm package that had been withdrawn, so the
    // refresh command could never have worked on a stock install.
    expect((new Settings())->geoDatabaseUrl)->toBe(AuthKitGeo::DEFAULT_DATABASE_URL)
        ->not->toContain('ip-location-db');
});

it('resolves the withdrawn 5.0.0 default to the current one', function() {
    // An install that saved its settings before this release still carries the
    // dead URL in project config. Coerced on read rather than rewritten by a
    // migration, so a developer's tracked YAML is left alone.
    $settings = new Settings();
    $settings->geoDatabaseUrl = Settings::DEAD_GEO_DATABASE_URL;

    expect($settings->getGeoDatabaseUrl())->toBe(AuthKitGeo::DEFAULT_DATABASE_URL);
});

it('leaves a deliberately chosen refresh URL alone', function() {
    $settings = new Settings();
    $settings->geoDatabaseUrl = 'https://example.test/our-licensed-city.mmdb';

    expect($settings->getGeoDatabaseUrl())->toBe('https://example.test/our-licensed-city.mmdb');
});
