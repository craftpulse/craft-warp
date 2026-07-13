<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Validation matrix for the Settings model — the passwordless tunables and
 * their secure ranges. Out-of-range values must fail closed so a saved
 * configuration can never weaken a token lifetime or OTP cap past its bounds,
 * and the login-method set must always resolve to at least one usable channel.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\helpers\StringHelper;
use craft\models\UserGroup;
use craftpulse\warp\models\Settings;

it('defaults to secure passwordless tunables', function() {
    $settings = new Settings();

    expect($settings->tokenTtl)->toBe(900)
        ->and($settings->recentAuthDuration)->toBe(300)
        ->and($settings->otpDigits)->toBe(6)
        ->and($settings->otpMaxAttempts)->toBe(5)
        ->and($settings->perEmailLimit)->toBe(5)
        ->and($settings->perEmailWindow)->toBe(300)
        ->and($settings->enableRegistration)->toBeTrue()
        ->and($settings->enablePasskeyNudge)->toBeTrue()
        ->and($settings->anonymizeIp)->toBeFalse()
        ->and($settings->registrationGroupUid)->toBeNull()
        ->and($settings->loginMethods)->toBe([Settings::CHANNEL_MAGIC_LINK, Settings::CHANNEL_OTP]);
});

it('validates a sane settings set', function() {
    $settings = new Settings([
        'tokenTtl' => 600,
        'recentAuthDuration' => 120,
        'otpDigits' => 8,
        'otpMaxAttempts' => 3,
        'loginMethods' => [Settings::CHANNEL_OTP],
    ]);

    expect($settings->validate())->toBeTrue();
});

it('accepts each single login method and the full set', function() {
    foreach ([[Settings::CHANNEL_MAGIC_LINK], [Settings::CHANNEL_OTP], [Settings::CHANNEL_MAGIC_LINK, Settings::CHANNEL_OTP]] as $set) {
        expect((new Settings(['loginMethods' => $set]))->validate())->toBeTrue();
    }
});

it('rejects an empty login-method set', function() {
    $settings = new Settings(['loginMethods' => []]);

    expect($settings->validate())->toBeFalse()
        ->and($settings->hasErrors('loginMethods'))->toBeTrue();
});

it('rejects an unknown login method', function() {
    $settings = new Settings(['loginMethods' => [Settings::CHANNEL_MAGIC_LINK, 'sms']]);

    expect($settings->validate())->toBeFalse()
        ->and($settings->hasErrors('loginMethods'))->toBeTrue();
});

it('rejects a token lifetime outside its bounds', function() {
    expect((new Settings(['tokenTtl' => 30]))->validate())->toBeFalse()
        ->and((new Settings(['tokenTtl' => 999999]))->validate())->toBeFalse();
});

it('rejects a recent-auth window below the minimum', function() {
    $settings = new Settings(['recentAuthDuration' => 10]);

    expect($settings->validate())->toBeFalse()
        ->and($settings->hasErrors('recentAuthDuration'))->toBeTrue();
});

it('rejects an out-of-range OTP digit count', function() {
    expect((new Settings(['otpDigits' => 2]))->validate())->toBeFalse()
        ->and((new Settings(['otpDigits' => 12]))->validate())->toBeFalse();
});

it('rejects an OTP attempt cap outside its bounds', function() {
    expect((new Settings(['otpMaxAttempts' => 0]))->validate())->toBeFalse()
        ->and((new Settings(['otpMaxAttempts' => 99]))->validate())->toBeFalse();
});

it('rejects a per-email limit outside its bounds', function() {
    expect((new Settings(['perEmailLimit' => 0]))->validate())->toBeFalse()
        ->and((new Settings(['perEmailLimit' => 500]))->validate())->toBeFalse();
});

it('accepts a null registration group UID', function() {
    $settings = new Settings(['registrationGroupUid' => null]);

    expect($settings->validate())->toBeTrue()
        ->and($settings->hasErrors('registrationGroupUid'))->toBeFalse();
});

it('accepts a registration group UID that references an existing group', function() {
    $unique = strtolower(str_replace('-', '', StringHelper::UUID()));
    $group = new UserGroup(['name' => "WP {$unique}", 'handle' => "wp{$unique}"]);

    if (!Craft::$app->getUserGroups()->saveGroup($group)) {
        throw new RuntimeException('Could not save settings test user group.');
    }

    $settings = new Settings(['registrationGroupUid' => $group->uid]);

    expect($settings->validate())->toBeTrue()
        ->and($settings->hasErrors('registrationGroupUid'))->toBeFalse();

    Craft::$app->getUserGroups()->deleteGroupById((int)$group->id);
});

it('rejects a registration group UID with no matching group', function() {
    $settings = new Settings(['registrationGroupUid' => StringHelper::UUID()]);

    expect($settings->validate())->toBeFalse()
        ->and($settings->hasErrors('registrationGroupUid'))->toBeTrue();
});

it('resolves a literal integer through the typed getters', function() {
    $settings = new Settings([
        'tokenTtl' => 600,
        'otpDigits' => 8,
        'otpMaxAttempts' => 3,
        'perEmailLimit' => 9,
        'perEmailWindow' => 120,
        'recentAuthDuration' => 240,
    ]);

    expect($settings->getTokenTtl())->toBe(600)
        ->and($settings->getOtpDigits())->toBe(8)
        ->and($settings->getOtpMaxAttempts())->toBe(3)
        ->and($settings->getPerEmailLimit())->toBe(9)
        ->and($settings->getPerEmailWindow())->toBe(120)
        ->and($settings->getRecentAuthDuration())->toBe(240)
        ->and($settings->validate())->toBeTrue();
});

it('resolves an environment variable through the typed getters and validates the resolved value', function() {
    putenv('WARP_TEST_TTL=1200');
    $_SERVER['WARP_TEST_TTL'] = '1200';
    putenv('WARP_TEST_DIGITS=7');
    $_SERVER['WARP_TEST_DIGITS'] = '7';

    try {
        $settings = new Settings([
            'tokenTtl' => '$WARP_TEST_TTL',
            'otpDigits' => '$WARP_TEST_DIGITS',
        ]);

        expect($settings->getTokenTtl())->toBe(1200)
            ->and($settings->getOtpDigits())->toBe(7)
            ->and($settings->validate())->toBeTrue();
    } finally {
        putenv('WARP_TEST_TTL');
        putenv('WARP_TEST_DIGITS');
        unset($_SERVER['WARP_TEST_TTL'], $_SERVER['WARP_TEST_DIGITS']);
    }
});

it('rejects an environment variable whose resolved value is out of range', function() {
    putenv('WARP_TEST_BAD_TTL=5');
    $_SERVER['WARP_TEST_BAD_TTL'] = '5';

    try {
        $settings = new Settings(['tokenTtl' => '$WARP_TEST_BAD_TTL']);

        expect($settings->validate())->toBeFalse()
            ->and($settings->hasErrors('tokenTtl'))->toBeTrue();
    } finally {
        putenv('WARP_TEST_BAD_TTL');
        unset($_SERVER['WARP_TEST_BAD_TTL']);
    }
});

it('rejects an environment variable that does not resolve to a number', function() {
    $settings = new Settings(['tokenTtl' => '$WARP_UNDEFINED_TTL_VAR']);

    expect($settings->validate())->toBeFalse()
        ->and($settings->hasErrors('tokenTtl'))->toBeTrue();
});
