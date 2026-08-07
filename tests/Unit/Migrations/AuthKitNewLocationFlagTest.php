<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Covers the dated migration that pumps Auth Kit's migrator for the 1.11.0
 * requirement. Auth Kit is a library-shipped module with no console migration
 * track of its own, so this file is the only thing standing between an existing
 * install and a shared registry that never grows its `isNewLocation` column.
 *
 * The fixture deliberately rewinds the shared schema — column dropped, Auth
 * Kit's history row for it removed — because a suite whose registry is already
 * current would pass this migration whether or not it did anything at all.
 * Every path restores the column before it returns.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\authkit\AuthKit;
use craftpulse\authkit\db\Table as AuthKitTable;
use craftpulse\warp\migrations\m260807_000002_auth_kit_new_location_flag;

const AUTH_KIT_NEW_LOCATION_MIGRATION = 'm260807_000002_AddSessionIsNewLocation';

function newLocationColumnExists(): bool
{
    Craft::$app->getDb()->getSchema()->refresh();

    return Craft::$app->getDb()->columnExists(AuthKitTable::SESSIONS, 'isNewLocation');
}

/**
 * Puts the shared registry back to how Auth Kit 1.10.0 left it.
 */
function rewindSharedRegistry(): void
{
    $migration = new class() extends craft\db\Migration {
    };
    $migration->compact = true;

    if (newLocationColumnExists()) {
        $migration->dropColumn(AuthKitTable::SESSIONS, 'isNewLocation');
    }

    $migrator = AuthKit::getInstance()->getMigrator();

    if (isset($migrator->getMigrationHistory()[AUTH_KIT_NEW_LOCATION_MIGRATION])) {
        $migrator->removeMigrationHistory(AUTH_KIT_NEW_LOCATION_MIGRATION);
    }

    Craft::$app->getDb()->getSchema()->refresh();
}

function runNewLocationMigration(): m260807_000002_auth_kit_new_location_flag
{
    $migration = new m260807_000002_auth_kit_new_location_flag();
    $migration->compact = true;

    return $migration;
}

// =============================================================================
// safeUp
// =============================================================================

it('adds the shared new-location column to an install that predates Auth Kit 1.11.0', function() {
    rewindSharedRegistry();

    expect(newLocationColumnExists())->toBeFalse();

    runNewLocationMigration()->safeUp();

    expect(newLocationColumnExists())->toBeTrue()
        ->and(AuthKit::getInstance()->getMigrator()->getMigrationHistory())
        ->toHaveKey(AUTH_KIT_NEW_LOCATION_MIGRATION);
});

it('does nothing on an install another consumer already brought up to date', function() {
    rewindSharedRegistry();
    runNewLocationMigration()->safeUp();

    // The second pass is what Warden's own dated migration looks like from
    // here: the module track already records the work, so there is none left.
    expect(runNewLocationMigration()->safeUp())->toBeTrue()
        ->and(newLocationColumnExists())->toBeTrue();
});

// =============================================================================
// safeDown
// =============================================================================

it('leaves the shared column alone when it is reverted', function() {
    rewindSharedRegistry();
    runNewLocationMigration()->safeUp();

    // Reverting Warp is not a licence to drop a column Warden and Auth Kit's
    // own service still read.
    expect(runNewLocationMigration()->safeDown())->toBeTrue()
        ->and(newLocationColumnExists())->toBeTrue();
});
