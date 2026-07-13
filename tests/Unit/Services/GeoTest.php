<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Service tests for optional geo enrichment: the record-shape extractors handle
 * both the flat ip-location-db and the nested MaxMind layouts, and a lookup
 * degrades to nulls with no database present so it can never break a login.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\warp\services\Geo;

it('reads the flat ip-location-db record shape', function() {
    $record = ['country_code' => 'be', 'city_name' => 'Brussels'];

    expect(Geo::countryFromRecord($record))->toBe('BE')
        ->and(Geo::cityFromRecord($record))->toBe('Brussels');
});

it('reads the nested MaxMind record shape', function() {
    $record = [
        'country' => ['iso_code' => 'JP'],
        'city' => ['names' => ['en' => 'Tokyo']],
    ];

    expect(Geo::countryFromRecord($record))->toBe('JP')
        ->and(Geo::cityFromRecord($record))->toBe('Tokyo');
});

it('returns null from an empty or malformed record', function() {
    expect(Geo::countryFromRecord(null))->toBeNull()
        ->and(Geo::cityFromRecord(null))->toBeNull()
        ->and(Geo::countryFromRecord(['country_code' => 'toolong']))->toBeNull()
        ->and(Geo::cityFromRecord(['city_name' => '']))->toBeNull();
});

it('degrades to nulls when no database is present', function() {
    $geo = new Geo();

    expect($geo->isAvailable())->toBeFalse()
        ->and($geo->lookup('8.8.8.8'))->toBe(['city' => null, 'country' => null])
        ->and($geo->lookup(null))->toBe(['city' => null, 'country' => null]);
});

it('resolves its database path under the warp storage folder', function() {
    expect((new Geo())->path())->toEndWith('warp' . DIRECTORY_SEPARATOR . 'geo' . DIRECTORY_SEPARATOR . 'city.mmdb');
});
