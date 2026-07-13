<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Schema tests for the install migration: the tables Warp owns exist with the
 * columns its services query. The registry that backs session management
 * (`warp_sessions`) pins a unique token hash to its device metadata, joined
 * against core's `{{%sessions}}` at read time.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\warp\db\Table;

it('creates the session registry table with its device columns', function() {
    $schema = Craft::$app->getDb()->getTableSchema(Table::SESSIONS);

    expect($schema)->not->toBeNull();
    expect(array_keys($schema->columns))->toContain(
        'id',
        'userId',
        'tokenHash',
        'userAgent',
        'ip',
        'city',
        'country',
        'dateCreated',
        'dateUpdated',
        'uid',
    );
});

it('stores the token hash as a fixed 64-character column', function() {
    $schema = Craft::$app->getDb()->getTableSchema(Table::SESSIONS);
    $tokenHash = $schema->getColumn('tokenHash');

    expect($tokenHash)->not->toBeNull()
        ->and($tokenHash->size)->toBe(64)
        ->and($tokenHash->allowNull)->toBeFalse();
});

it('keeps the login log table alongside the session registry with its geo columns', function() {
    $schema = Craft::$app->getDb()->getTableSchema(Table::LOGINS);

    expect($schema)->not->toBeNull();
    expect(array_keys($schema->columns))->toContain(
        'id',
        'userId',
        'method',
        'userAgent',
        'ip',
        'city',
        'country',
        'isNewLocation',
        'dateCreated',
        'dateUpdated',
        'uid',
    );
});
