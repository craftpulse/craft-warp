<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Verifies that Warp's settings are pushed into Auth Kit's headless services at
 * init — Warp owns the configuration, Auth Kit owns the behaviour — and that
 * the magic-link verify route is pinned to Warp's own endpoint.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\authkit\AuthKit;
use craftpulse\warp\Warp;

it('pushes Warp settings into Auth Kit services', function() {
    $tokens = AuthKit::$plugin->getTokens();
    $passkeys = AuthKit::$plugin->getPasskeys();

    $settings = Warp::$plugin->getSettings();
    $settings->tokenTtl = 1234;
    $settings->otpDigits = 8;
    $settings->otpMaxAttempts = 9;
    $settings->perEmailLimit = 7;
    $settings->perEmailWindow = 4321;
    $settings->recentAuthDuration = 1111;

    // _configureAuthKit() is private init-time wiring; invoke it directly.
    (new ReflectionMethod(Warp::class, '_configureAuthKit'))->invoke(Warp::$plugin);

    expect($tokens->tokenTtl)->toBe(1234)
        ->and($tokens->otpDigits)->toBe(8)
        ->and($tokens->otpMaxAttempts)->toBe(9)
        ->and($tokens->perEmailLimit)->toBe(7)
        ->and($tokens->perEmailWindow)->toBe(4321)
        ->and($tokens->magicLinkRoute)->toBe('warp/auth/verify-link')
        ->and($tokens->registrationRoute)->toBe('warp/auth/verify-registration')
        ->and($passkeys->recentAuthDuration)->toBe(1111);
});

afterEach(function() {
    // The services are shared singletons; restore defaults so the mutated
    // values above don't leak into other tests.
    $settings = Warp::$plugin->getSettings();
    $settings->tokenTtl = 900;
    $settings->otpDigits = 6;
    $settings->otpMaxAttempts = 5;
    $settings->perEmailLimit = 5;
    $settings->perEmailWindow = 300;
    $settings->recentAuthDuration = 300;

    (new ReflectionMethod(Warp::class, '_configureAuthKit'))->invoke(Warp::$plugin);
});
