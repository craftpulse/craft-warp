<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Surface tests for the `craft.warp` Twig variable: that it is registered and
 * renders, that its passkey delegates behave for a guest, that it exposes the
 * published WebAuthn client URL, the enabled login methods, and whether
 * registration is open. This surface is what Phase 5's templates consume, so the
 * test doubles as a guard against silent API drift.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\warp\models\Settings;
use craftpulse\warp\services\Passwordless;
use craftpulse\warp\variables\WarpVariable;
use craftpulse\warp\Warp;

beforeEach(function() {
    Craft::$app->getUser()->setIdentity(null);
    Craft::$app->getSession()->remove(Passwordless::SESSION_PASSKEY_NUDGE_KEY);
    Warp::$plugin->getSettings()->loginMethods = [Settings::CHANNEL_MAGIC_LINK, Settings::CHANNEL_OTP];
    Warp::$plugin->getSettings()->enableRegistration = true;
});

afterEach(function() {
    Craft::$app->getUser()->setIdentity(null);
    Craft::$app->getSession()->remove(Passwordless::SESSION_PASSKEY_NUDGE_KEY);
    Warp::$plugin->getSettings()->loginMethods = [Settings::CHANNEL_MAGIC_LINK, Settings::CHANNEL_OTP];
    Warp::$plugin->getSettings()->enableRegistration = true;
    Craft::$app->getProjectConfig()->set('users.allowPublicRegistration', false);
});

it('registers craft.warp and renders its passkey check for a guest', function() {
    $out = Craft::$app->getView()->renderString('{{ craft.warp.hasPasskeys ? "yes" : "no" }}');

    expect(trim($out))->toBe('no');
});

it('returns no passkeys for a guest', function() {
    expect((new WarpVariable())->getPasskeys())->toBe([])
        ->and((new WarpVariable())->getHasPasskeys())->toBeFalse();
});

it('exposes the published webauthn client url', function() {
    expect((new WarpVariable())->getWebauthnJsUrl())->toContain('authkit-webauthn.js');
});

it('reports the enabled login methods from settings', function() {
    Warp::$plugin->getSettings()->loginMethods = [Settings::CHANNEL_OTP];

    expect((new WarpVariable())->getLoginMethods())->toBe([Settings::CHANNEL_OTP]);
});

it('reports registration open only when both flags are on', function() {
    Craft::$app->getProjectConfig()->set('users.allowPublicRegistration', false);
    expect((new WarpVariable())->getRegistrationEnabled())->toBeFalse();

    Craft::$app->getProjectConfig()->set('users.allowPublicRegistration', true);
    expect((new WarpVariable())->getRegistrationEnabled())->toBeTrue();

    Warp::$plugin->getSettings()->enableRegistration = false;
    expect((new WarpVariable())->getRegistrationEnabled())->toBeFalse();
});

it('does not flag the nudge for a fresh guest render', function() {
    expect((new WarpVariable())->getShowPasskeyNudge())->toBeFalse();
});
