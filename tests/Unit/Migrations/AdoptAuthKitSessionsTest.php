<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Covers the handover of the device registry, the location baseline, and the
 * geo database to the shared Auth Kit module. What matters here is that a live
 * install loses nothing: every registry row is carried across with its uid
 * intact (so no member is signed out and no revoke handle goes stale), the
 * places already in the login log seed the shared history, and an installed geo
 * database is moved rather than abandoned.
 *
 * Idempotency is asserted throughout, because Warden ships the same handover:
 * whichever runs first does the work and the second must find nothing to do.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craftpulse\authkit\db\Table as AuthKitTable;
use craftpulse\authkit\services\Geo as AuthKitGeo;
use craftpulse\warp\db\Table;
use craftpulse\warp\migrations\m260807_000001_adopt_auth_kit_sessions;
use craftpulse\warp\models\Login;

const WARP_LEGACY_SESSIONS_TABLE = '{{%warp_sessions}}';

function handoverUser(): User
{
    $unique = str_replace('-', '', StringHelper::UUID());
    $user = new User();
    $user->username = "handover-{$unique}@warp-test.example";
    $user->email = "handover-{$unique}@warp-test.example";

    if (!Craft::$app->getElements()->saveElement($user)) {
        throw new RuntimeException('Could not save handover test user.');
    }

    Craft::$app->getUsers()->activateUser($user);

    return Craft::$app->getUsers()->getUserById((int)$user->id);
}

/**
 * Recreates the `warp_sessions` table exactly as Warp 5.0.0 shipped it.
 */
function fixtureLegacyRegistry(): void
{
    // craft\db\Migration is abstract, so the schema builders it exposes are
    // reached through a throwaway concrete subclass.
    $migration = new class() extends craft\db\Migration {
    };
    $migration->compact = true;
    $migration->dropTableIfExists(WARP_LEGACY_SESSIONS_TABLE);
    $migration->createTable(WARP_LEGACY_SESSIONS_TABLE, [
        'id' => $migration->primaryKey(),
        'userId' => $migration->integer()->notNull(),
        'tokenHash' => $migration->char(64)->notNull(),
        'userAgent' => $migration->string(255),
        'ip' => $migration->string(45),
        'city' => $migration->string(255),
        'country' => $migration->char(2),
        'dateCreated' => $migration->dateTime()->notNull(),
        'dateUpdated' => $migration->dateTime()->notNull(),
        'uid' => $migration->uid(),
    ]);

    Craft::$app->getDb()->getSchema()->refreshTableSchema(WARP_LEGACY_SESSIONS_TABLE);
}

/**
 * Returns the migration under test, with its SQL echo suppressed so a suite run
 * stays readable.
 */
function handoverMigration(): m260807_000001_adopt_auth_kit_sessions
{
    $migration = new m260807_000001_adopt_auth_kit_sessions();
    $migration->compact = true;

    return $migration;
}

/**
 * Returns whether the legacy table exists, reading past Craft's memoized schema
 * so a create or drop earlier in the same process is visible.
 */
function legacyRegistryExists(): bool
{
    Craft::$app->getDb()->getSchema()->refreshTableSchema(WARP_LEGACY_SESSIONS_TABLE);

    return Craft::$app->getDb()->tableExists(WARP_LEGACY_SESSIONS_TABLE);
}

/**
 * Inserts one legacy registry row and returns its uid.
 */
function insertLegacyRegistryRow(int $userId, string $tokenHash, ?string $city = null, ?string $country = null): string
{
    $uid = StringHelper::UUID();
    $now = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));

    Db::insert(WARP_LEGACY_SESSIONS_TABLE, [
        'userId' => $userId,
        'tokenHash' => $tokenHash,
        'userAgent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/120.0.0.0 Safari/537.36',
        'ip' => '203.0.113.4',
        'city' => $city,
        'country' => $country,
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => $uid,
    ]);

    return $uid;
}

function sharedRegistryRow(string $tokenHash): ?array
{
    /** @var array<string, mixed>|null $row */
    $row = (new Query())->from(AuthKitTable::SESSIONS)->where(['tokenHash' => $tokenHash])->one() ?: null;

    return $row;
}

function knownPlaceExists(int $userId, string $country, ?string $city): bool
{
    return (new Query())
        ->from(AuthKitTable::LOCATIONS)
        ->where(['userId' => $userId, 'country' => $country, 'city' => $city])
        ->exists();
}

afterEach(function() {
    Craft::$app->getDb()->createCommand()->dropTableIfExists(WARP_LEGACY_SESSIONS_TABLE)->execute();
    Craft::$app->getDb()->getSchema()->refreshTableSchema(WARP_LEGACY_SESSIONS_TABLE);

    foreach (User::find()->email('*@warp-test.example')->status(null)->all() as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
});

// =============================================================================
// the registry rows
// =============================================================================

it('carries every legacy registry row into the shared registry, uid intact', function() {
    $user = handoverUser();
    fixtureLegacyRegistry();
    $tokenHash = hash('sha256', 'handover-' . StringHelper::UUID());
    $uid = insertLegacyRegistryRow((int)$user->id, $tokenHash, 'Brussels', 'BE');

    handoverMigration()->safeUp();

    $row = sharedRegistryRow($tokenHash);

    // The uid is the handle the front end posts to revoke one device, so a page
    // rendered before the upgrade has to keep working after it.
    expect($row)->not->toBeNull()
        ->and($row['uid'])->toBe($uid)
        ->and((int)$row['userId'])->toBe((int)$user->id)
        ->and($row['city'])->toBe('Brussels')
        ->and($row['country'])->toBe('BE')
        ->and($row['ip'])->toBe('203.0.113.4');
});

it('drops the legacy table once its rows are safe', function() {
    fixtureLegacyRegistry();

    handoverMigration()->safeUp();

    expect(legacyRegistryExists())->toBeFalse();
});

it('leaves a row another consumer already registered alone', function() {
    $user = handoverUser();
    $tokenHash = hash('sha256', 'shared-' . StringHelper::UUID());

    // Warden got there first and registered this very session.
    Db::insert(AuthKitTable::SESSIONS, [
        'userId' => (int)$user->id,
        'tokenHash' => $tokenHash,
        'userAgent' => 'Warden capture',
        'dateCreated' => Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC'))),
        'dateUpdated' => Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC'))),
        'uid' => StringHelper::UUID(),
    ]);

    fixtureLegacyRegistry();
    insertLegacyRegistryRow((int)$user->id, $tokenHash);

    handoverMigration()->safeUp();

    $count = (new Query())->from(AuthKitTable::SESSIONS)->where(['tokenHash' => $tokenHash])->count();

    expect((int)$count)->toBe(1)
        ->and(sharedRegistryRow($tokenHash)['userAgent'])->toBe('Warden capture');
});

it('is a no-op when there is no legacy table to carry', function() {
    expect(legacyRegistryExists())->toBeFalse()
        ->and(handoverMigration()->safeUp())->toBeTrue();
});

// =============================================================================
// the location baseline
// =============================================================================

it('seeds the shared location history from the places already in the login log', function() {
    $user = handoverUser();
    $now = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));

    foreach ([['BE', 'Brussels'], ['BE', 'Brussels'], ['FR', 'Paris'], [null, null]] as [$country, $city]) {
        Db::insert(Table::LOGINS, [
            'userId' => (int)$user->id,
            'method' => Login::METHOD_OTP,
            'country' => $country,
            'city' => $city,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ]);
    }

    handoverMigration()->safeUp();

    $count = (new Query())->from(AuthKitTable::LOCATIONS)->where(['userId' => (int)$user->id])->count();

    // Distinct places, not rows, and never the unresolvable one.
    expect(knownPlaceExists((int)$user->id, 'BE', 'Brussels'))->toBeTrue()
        ->and(knownPlaceExists((int)$user->id, 'FR', 'Paris'))->toBeTrue()
        ->and((int)$count)->toBe(2);
});

it('seeds no duplicate places when it runs twice', function() {
    $user = handoverUser();
    $now = Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')));

    Db::insert(Table::LOGINS, [
        'userId' => (int)$user->id,
        'method' => Login::METHOD_OTP,
        'country' => 'JP',
        'city' => 'Tokyo',
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ]);

    handoverMigration()->safeUp();
    handoverMigration()->safeUp();

    $count = (new Query())
        ->from(AuthKitTable::LOCATIONS)
        ->where(['userId' => (int)$user->id, 'country' => 'JP', 'city' => 'Tokyo'])
        ->count();

    expect((int)$count)->toBe(1);
});

// =============================================================================
// the geo database file
// =============================================================================

it('moves an installed geo database to the shared path', function() {
    $legacyPath = Craft::$app->getPath()->getStoragePath()
        . DIRECTORY_SEPARATOR . 'warp' . DIRECTORY_SEPARATOR . 'geo' . DIRECTORY_SEPARATOR . 'city.mmdb';
    $sharedPath = (new AuthKitGeo())->path();

    FileHelper::createDirectory(dirname($legacyPath));
    file_put_contents($legacyPath, 'not-a-real-mmdb');

    try {
        handoverMigration()->safeUp();

        expect(is_file($legacyPath))->toBeFalse()
            ->and(is_file($sharedPath))->toBeTrue()
            ->and(file_get_contents($sharedPath))->toBe('not-a-real-mmdb');
    } finally {
        // The move is the point, so the legacy copy is normally already gone.
        foreach ([$sharedPath, $legacyPath] as $path) {
            if (is_file($path)) {
                FileHelper::unlink($path);
            }
        }
    }
});

// =============================================================================
// reversibility
// =============================================================================

it('refuses to revert, because the shared tables outlive Warp', function() {
    expect(handoverMigration()->safeDown())->toBeFalse()
        ->and(Craft::$app->getDb()->tableExists(AuthKitTable::SESSIONS))->toBeTrue();
})->expectOutputString("m260807_000001_adopt_auth_kit_sessions cannot be reverted.\n");
