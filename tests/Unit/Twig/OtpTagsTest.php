<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Tests for the fluent OTP render builders: otpInput renders a single
 * enhanceable input sized to the otpDigits setting, otpForm renders the
 * complete verify form (action, email prefill or visible input, hint,
 * submit), unknown builder options fail loud, and both resolve through the
 * craft.warp Twig variable.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\warp\controllers\AuthController;
use craftpulse\warp\twig\tags\OtpFormTag;
use craftpulse\warp\twig\tags\OtpInputTag;
use craftpulse\warp\Warp;

afterEach(function() {
    Craft::$app->getSession()->remove(AuthController::SESSION_REQUESTED_EMAIL);
});

it('renders the segmented code input sized to the otpDigits setting', function() {
    $original = Warp::$plugin->getSettings()->otpDigits;
    Warp::$plugin->getSettings()->otpDigits = 8;

    try {
        $html = (string)(new OtpInputTag());
    } finally {
        Warp::$plugin->getSettings()->otpDigits = $original;
    }

    expect($html)->toContain('data-warp-otp="8"')
        ->and($html)->toContain('name="code"')
        ->and($html)->toContain('maxlength="8"')
        ->and($html)->toContain('autocomplete="one-time-code"')
        ->and($html)->toContain('inputmode="numeric"');
});

it('honours a digits override and merged input attributes', function() {
    $html = (string)(new OtpInputTag([
        'digits' => 4,
        'inputAttrs' => ['data-extra' => 'yes'],
    ]));

    expect($html)->toContain('data-warp-otp="4"')
        ->and($html)->toContain('data-extra="yes"');
});

it('throws on an unknown builder option so typos fail loud', function() {
    new OtpInputTag(['digitz' => 6]);
})->throws(InvalidArgumentException::class);

it('renders the full verify form with a visible email input on a direct visit', function() {
    Craft::$app->getSession()->remove(AuthController::SESSION_REQUESTED_EMAIL);

    $html = (string)(new OtpFormTag(['returnUrl' => '/members/account']));

    expect($html)->toContain('warp/auth/verify-code')
        ->and($html)->toContain('name="returnUrl"')
        ->and($html)->toContain('value="/members/account"')
        ->and($html)->toContain('type="email"')
        ->and($html)->toContain('name="code"')
        ->and($html)->toContain('type="submit"');
});

it('prefills the carried address as a hidden field with a change link', function() {
    Craft::$app->getSession()->set(AuthController::SESSION_REQUESTED_EMAIL, 'member@example.com');

    $html = (string)(new OtpFormTag(['requestUrl' => '/members/login']));

    expect($html)->toContain('type="hidden"')
        ->and($html)->toContain('value="member@example.com"')
        ->and($html)->toContain('member@example.com')
        ->and($html)->toContain('/members/login')
        ->and($html)->not->toContain('type="email"');
});

it('resolves both builders through the craft.warp variable in Twig', function() {
    $out = Craft::$app->getView()->renderString(
        '{{ craft.warp.otpInput({ digits: 5 }).render() }}||{{ craft.warp.otpForm().render() }}',
    );

    expect($out)->toContain('data-warp-otp="5"')
        ->and($out)->toContain('warp/auth/verify-code');
});
