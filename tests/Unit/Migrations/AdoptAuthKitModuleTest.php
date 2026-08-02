<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Covers the Auth Kit module adoption migration: the one-time upgrade that
 * converts a plugin-era Auth Kit install (an `auth-kit` row in the `plugins`
 * table, a `plugins.auth-kit` project-config entry, and a migration history on
 * the `plugin:auth-kit` track) into the module-era layout Auth Kit 1.7.0 ships
 * (no plugin registration at all, history on the `module:auth-kit` track).
 *
 * The conversion itself belongs to Auth Kit's `Adoption` class, which owns
 * every fact about its own layout and is covered by its own suite. What is
 * asserted here is the part Warp is responsible for: that shipping the
 * migration actually lands a plugin-era install in the module-era state, that
 * the token store survives it (an upgrade must never cost a member their
 * magic-link or passkey state), that it is a no-op on the fresh installs the
 * rest of this suite runs against, and that a second run finds nothing to do,
 * which is what makes it safe for Warden to ship the same migration alongside.
 *
 * Warp reaches the adoption from two places, and both are covered here.
 * The dated migration is the upgrade path, for a site that already has Warp
 * installed. `Install` is the fresh-install path, and it needs the adoption
 * just as much: Craft stamps dated migrations as applied *without running
 * them* on a fresh install, so installing Warp fresh onto a database that once
 * carried the Auth Kit plugin would otherwise leave the stale registration
 * behind with nothing left to ever clear it.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craftpulse\authkit\AuthKit;
use craftpulse\authkit\db\Table as AuthKitTable;
use craftpulse\authkit\migrations\Adoption;
use craftpulse\warp\migrations\Install;
use craftpulse\warp\migrations\m260802_100000_adopt_auth_kit_module;

/**
 * The migration names the plugin era recorded on the `plugin:auth-kit` track.
 * The base schema was recorded under the bare name `Install` (a plugin track's
 * privileged slot), which the module track discovers as the dated wrapper
 * `m260617_000000_Install` instead.
 *
 * @var string[]
 */
const WARP_PLUGIN_ERA_MIGRATIONS = [
    'Install',
    'm260711_000001_MakeTokenUserIdNullable',
    'm260716_000001_AddTokenOrigin',
    'm260718_000001_AddTokenSubject',
];

/**
 * Returns the migration names recorded on the given track.
 *
 * @param string $track
 * @return string[]
 */
function warpMigrationNamesOnTrack(string $track): array
{
    /** @var string[] $names */
    $names = (new Query())
        ->select(['name'])
        ->from([CraftTable::MIGRATIONS])
        ->where(['track' => $track])
        ->column();

    sort($names);

    return $names;
}

/**
 * Rewinds this install to the plugin-era layout the adoption migration exists
 * to convert: history on the plugin track instead of the module track, and an
 * `auth-kit` row in the `plugins` table.
 *
 * Auth Kit's tables are deliberately left in place. That is the whole point of
 * the upgrade path: the schema is already correct, only its registration and
 * its migration bookkeeping move.
 *
 * @param bool $withProjectConfig Whether to also fixture the `plugins.auth-kit` project-config entry.
 * @return void
 */
function warpFixturePluginEraAuthKit(bool $withProjectConfig = false): void
{
    Db::delete(CraftTable::MIGRATIONS, ['track' => Adoption::MODULE_TRACK]);

    $now = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));

    foreach (WARP_PLUGIN_ERA_MIGRATIONS as $name) {
        Db::insert(CraftTable::MIGRATIONS, [
            'track' => Adoption::PLUGIN_TRACK,
            'name' => $name,
            'applyTime' => $now,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ]);
    }

    Db::insert(CraftTable::PLUGINS, [
        'handle' => AuthKit::ID,
        'version' => '1.6.2',
        'schemaVersion' => '1.3.0',
        'installDate' => $now,
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ]);

    if ($withProjectConfig) {
        Craft::$app->getProjectConfig()->set('plugins.' . AuthKit::ID, [
            'edition' => 'standard',
            'enabled' => true,
            'schemaVersion' => '1.3.0',
        ]);
    }
}

/**
 * Returns whether the `plugins` table still carries an `auth-kit` row.
 *
 * @return bool
 */
function warpAuthKitPluginRowExists(): bool
{
    return (new Query())
        ->from([CraftTable::PLUGINS])
        ->where(['handle' => AuthKit::ID])
        ->exists();
}

// =============================================================================
// Fresh installs — the state every other test in this suite runs against
// =============================================================================

it('leaves a fresh install on the module track with no plugin registration', function() {
    // The suite's own bootstrap installs Warp and nothing else, so this is
    // exactly what a site installing Warp at this release gets.
    expect(Craft::$app->getDb()->tableExists(AuthKitTable::TOKENS))->toBeTrue()
        ->and(warpAuthKitPluginRowExists())->toBeFalse()
        ->and(warpMigrationNamesOnTrack(Adoption::PLUGIN_TRACK))->toBe([])
        ->and(warpMigrationNamesOnTrack(Adoption::MODULE_TRACK))
        ->toContain('m260617_000000_Install');
});

it('is a no-op on an install that never had the Auth Kit plugin', function() {
    $before = warpMigrationNamesOnTrack(Adoption::MODULE_TRACK);

    expect((new m260802_100000_adopt_auth_kit_module())->safeUp())->toBeTrue()
        ->and(warpMigrationNamesOnTrack(Adoption::MODULE_TRACK))->toBe($before)
        ->and(warpAuthKitPluginRowExists())->toBeFalse()
        ->and(Craft::$app->getDb()->tableExists(AuthKitTable::TOKENS))->toBeTrue();
});

// =============================================================================
// Upgrade — a plugin-era install converted in place
// =============================================================================

it('moves a plugin-era migration history onto the module track', function() {
    warpFixturePluginEraAuthKit();

    expect(warpMigrationNamesOnTrack(Adoption::MODULE_TRACK))->toBe([]);

    (new m260802_100000_adopt_auth_kit_module())->safeUp();

    // The plugin era's dated deltas carry over name-for-name, and the bare
    // `Install` it recorded is adopted as the module track's dated wrapper.
    expect(warpMigrationNamesOnTrack(Adoption::MODULE_TRACK))->toContain(
        'm260617_000000_Install',
        'm260711_000001_MakeTokenUserIdNullable',
        'm260716_000001_AddTokenOrigin',
        'm260718_000001_AddTokenSubject',
    );

    // The orphaned plugin-track rows are cleared out rather than left behind.
    expect(warpMigrationNamesOnTrack(Adoption::PLUGIN_TRACK))->toBe([]);
});

it('deregisters the Auth Kit plugin without dropping its tables', function() {
    warpFixturePluginEraAuthKit();

    expect(warpAuthKitPluginRowExists())->toBeTrue();

    (new m260802_100000_adopt_auth_kit_module())->safeUp();

    expect(warpAuthKitPluginRowExists())->toBeFalse()
        ->and(Craft::$app->getDb()->tableExists(AuthKitTable::TOKENS))->toBeTrue();
});

it('keeps every stored token across the upgrade', function() {
    // A token issued before the upgrade must still be there afterwards: this
    // is an in-place conversion, never an uninstall and reinstall.
    warpFixturePluginEraAuthKit();

    $now = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));
    $tokenHash = hash('sha256', 'warp-upgrade-fixture');

    Db::insert(AuthKitTable::TOKENS, [
        'userId' => null,
        'type' => 'magic-link',
        'origin' => 'warp',
        'subject' => hash('sha256', 'upgrade-fixture@example.test'),
        'tokenHash' => $tokenHash,
        'expiryDate' => Db::prepareDateForDb(new DateTime('+1 hour', new DateTimeZone('UTC'))),
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ]);

    (new m260802_100000_adopt_auth_kit_module())->safeUp();

    expect((new Query())
        ->from([AuthKitTable::TOKENS])
        ->where(['tokenHash' => $tokenHash])
        ->exists())->toBeTrue();
});

it('removes the plugin entry from the project config', function() {
    warpFixturePluginEraAuthKit(withProjectConfig: true);

    expect(Craft::$app->getProjectConfig()->get('plugins.' . AuthKit::ID))->not->toBeNull();

    (new m260802_100000_adopt_auth_kit_module())->safeUp();

    expect(Craft::$app->getProjectConfig()->get('plugins.' . AuthKit::ID))->toBeNull();
});

it('finds nothing left to do on a second run, so co-shipping consumers are safe', function() {
    // Warden ships the same adoption migration. On an install running both,
    // whichever runs first does the work and the second must be a no-op.
    warpFixturePluginEraAuthKit();

    (new m260802_100000_adopt_auth_kit_module())->safeUp();
    $afterFirst = warpMigrationNamesOnTrack(Adoption::MODULE_TRACK);

    expect((new m260802_100000_adopt_auth_kit_module())->safeUp())->toBeTrue()
        ->and(warpMigrationNamesOnTrack(Adoption::MODULE_TRACK))->toBe($afterFirst)
        ->and(warpAuthKitPluginRowExists())->toBeFalse()
        ->and(Craft::$app->getDb()->tableExists(AuthKitTable::TOKENS))->toBeTrue();
});

// =============================================================================
// Fresh install onto a database that once carried the Auth Kit plugin
//
// The migration above never runs here — Craft stamps dated migrations as
// applied without running them on a fresh install — so `Install` has to carry
// the adoption itself. Without it the stale registration would survive
// indefinitely and Auth Kit would keep showing up in the plugins list.
// =============================================================================

it('clears a stale Auth Kit plugin registration when Warp is installed fresh', function() {
    warpFixturePluginEraAuthKit(withProjectConfig: true);

    expect(warpAuthKitPluginRowExists())->toBeTrue()
        ->and(Craft::$app->getProjectConfig()->get('plugins.' . AuthKit::ID))->not->toBeNull();

    expect((new Install())->safeUp())->toBeTrue()
        ->and(warpAuthKitPluginRowExists())->toBeFalse()
        ->and(Craft::$app->getProjectConfig()->get('plugins.' . AuthKit::ID))->toBeNull();
});

it('adopts the plugin-era migration history when Warp is installed fresh', function() {
    warpFixturePluginEraAuthKit();

    expect(warpMigrationNamesOnTrack(Adoption::MODULE_TRACK))->toBe([]);

    (new Install())->safeUp();

    expect(warpMigrationNamesOnTrack(Adoption::MODULE_TRACK))->toContain(
        'm260617_000000_Install',
        'm260711_000001_MakeTokenUserIdNullable',
        'm260716_000001_AddTokenOrigin',
        'm260718_000001_AddTokenSubject',
    )->and(warpMigrationNamesOnTrack(Adoption::PLUGIN_TRACK))->toBe([]);
});

it('keeps the token store when Warp is installed over a plugin-era Auth Kit', function() {
    // The stale registration goes; the data behind it never does.
    warpFixturePluginEraAuthKit(withProjectConfig: true);

    $now = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));
    $tokenHash = hash('sha256', 'warp-fresh-install-fixture');

    Db::insert(AuthKitTable::TOKENS, [
        'userId' => null,
        'type' => 'magic-link',
        'origin' => 'warp',
        'subject' => hash('sha256', 'fresh-install-fixture@example.test'),
        'tokenHash' => $tokenHash,
        'expiryDate' => Db::prepareDateForDb(new DateTime('+1 hour', new DateTimeZone('UTC'))),
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ]);

    (new Install())->safeUp();

    expect((new Query())
        ->from([AuthKitTable::TOKENS])
        ->where(['tokenHash' => $tokenHash])
        ->exists())->toBeTrue();
});

it('still brings the shared schema up when Warp is installed on a database that never had the plugin', function() {
    // The degrade-to-plain-`up()` case: no registration to clear, schema still
    // brought up, nothing invented.
    expect((new Install())->safeUp())->toBeTrue()
        ->and(Craft::$app->getDb()->tableExists(AuthKitTable::TOKENS))->toBeTrue()
        ->and(warpAuthKitPluginRowExists())->toBeFalse()
        ->and(warpMigrationNamesOnTrack(Adoption::MODULE_TRACK))
        ->toContain('m260617_000000_Install');
});
