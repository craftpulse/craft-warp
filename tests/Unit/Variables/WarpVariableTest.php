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

use craftpulse\warp\controllers\AuthController;
use craftpulse\warp\models\Settings;
use craftpulse\warp\services\Passwordless;
use craftpulse\warp\variables\WarpVariable;
use craftpulse\warp\Warp;

beforeEach(function() {
    // The playground is a shared install, so the registration switch must be
    // restored to whatever it was, not to Craft's default.
    $this->originalAllowPublicRegistration = (bool)Craft::$app->getProjectConfig()->get('users.allowPublicRegistration');
    Craft::$app->getUser()->setIdentity(null);
    Craft::$app->getSession()->remove(Passwordless::SESSION_PASSKEY_NUDGE_KEY);
    // Other files' request-flow tests leave the session-carried prefill behind.
    Craft::$app->getSession()->remove(AuthController::SESSION_REQUESTED_EMAIL);
    Warp::$plugin->getSettings()->loginMethods = [Settings::CHANNEL_MAGIC_LINK, Settings::CHANNEL_OTP];
    Warp::$plugin->getSettings()->enableRegistration = true;
});

afterEach(function() {
    Craft::$app->getUser()->setIdentity(null);
    Craft::$app->getSession()->remove(Passwordless::SESSION_PASSKEY_NUDGE_KEY);
    Craft::$app->getSession()->remove(AuthController::SESSION_REQUESTED_EMAIL);
    Warp::$plugin->getSettings()->loginMethods = [Settings::CHANNEL_MAGIC_LINK, Settings::CHANNEL_OTP];
    Warp::$plugin->getSettings()->enableRegistration = true;
    setAllowPublicRegistration($this->originalAllowPublicRegistration);
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

it('reports the configured otp digit length from settings', function() {
    $original = Warp::$plugin->getSettings()->otpDigits;
    Warp::$plugin->getSettings()->otpDigits = 8;

    try {
        expect((new WarpVariable())->getOtpDigits())->toBe(8)
            ->and(trim(Craft::$app->getView()->renderString('{{ craft.warp.otpDigits }}')))->toBe('8');
    } finally {
        Warp::$plugin->getSettings()->otpDigits = $original;
    }
});

it('reports the enabled login methods from settings', function() {
    Warp::$plugin->getSettings()->loginMethods = [Settings::CHANNEL_OTP];

    expect((new WarpVariable())->getLoginMethods())->toBe([Settings::CHANNEL_OTP]);
});

it('reports registration open only when both flags are on', function() {
    setAllowPublicRegistration(false);
    expect((new WarpVariable())->getRegistrationEnabled())->toBeFalse();

    setAllowPublicRegistration(true);
    expect((new WarpVariable())->getRegistrationEnabled())->toBeTrue();

    Warp::$plugin->getSettings()->enableRegistration = false;
    expect((new WarpVariable())->getRegistrationEnabled())->toBeFalse();
});

it('exposes the session-carried requested email and null when none is held', function() {
    $session = Craft::$app->getSession();
    $session->remove(AuthController::SESSION_REQUESTED_EMAIL);

    try {
        expect((new WarpVariable())->getRequestedEmail())->toBeNull();

        $session->set(AuthController::SESSION_REQUESTED_EMAIL, 'member@example.com');

        // Reading does not clear: the prefill must survive a wrong-code reload.
        expect((new WarpVariable())->getRequestedEmail())->toBe('member@example.com')
            ->and((new WarpVariable())->getRequestedEmail())->toBe('member@example.com');
    } finally {
        $session->remove(AuthController::SESSION_REQUESTED_EMAIL);
    }
});

it('does not flag the nudge for a fresh guest render', function() {
    expect((new WarpVariable())->getShowPasskeyNudge())->toBeFalse();
});

it('returns no sessions for a guest', function() {
    expect((new WarpVariable())->getSessions())->toBe([]);
});

it('resolves every craft.warp accessor the example templates call through Twig', function() {
    // Mirrors the exact `craft.warp.*` handles login.twig, otp-verify.twig,
    // account/passkeys.twig, account/sessions.twig, and the passkey-nudge partial
    // read. Rendering them through the View (not the PHP getters) guards against a
    // getter rename or variable de-registration silently breaking the shipped
    // templates.
    $template = <<<'TWIG'
        methods:{{ craft.warp.loginMethods|join(',') }}
        webauthn:{{ craft.warp.webauthnJsUrl }}
        passkeys:{{ craft.warp.passkeys|length }}
        haspasskeys:{{ craft.warp.hasPasskeys ? 'yes' : 'no' }}
        registration:{{ craft.warp.registrationEnabled ? 'yes' : 'no' }}
        nudge:{{ craft.warp.showPasskeyNudge ? 'yes' : 'no' }}
        otpdigits:{{ craft.warp.otpDigits }}
        sessions:{{ craft.warp.sessions|length }}
        requested:{{ craft.warp.requestedEmail ?? 'none' }}
        TWIG;

    $out = Craft::$app->getView()->renderString($template);

    expect($out)
        ->toContain('methods:magic-link,otp')
        ->toContain('authkit-webauthn.js')
        ->toContain('passkeys:0')
        ->toContain('haspasskeys:no')
        ->toContain('registration:')
        ->toContain('nudge:no')
        ->toContain('otpdigits:6')
        ->toContain('sessions:0')
        ->toContain('requested:none');
});
