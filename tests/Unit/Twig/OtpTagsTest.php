<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Tests for the fluent OTP render builders: otpInput renders a single
 * enhanceable input sized to the otpDigits setting, otpForm renders the
 * complete verify form (action, email prefill or visible input, hint,
 * submit), every emitted element and string is overridable, classes merge
 * rather than replace, Warp's stylesheet is suppressible while its script
 * always loads, unknown builder options fail loud, and both resolve through
 * the craft.warp Twig variable.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\helpers\Json;
use craftpulse\warp\assetbundles\warpforms\WarpFormsStyleAsset;
use craftpulse\warp\assetbundles\warpotp\WarpOtpAsset;
use craftpulse\warp\controllers\AuthController;
use craftpulse\warp\twig\tags\OtpFormTag;
use craftpulse\warp\twig\tags\OtpInputTag;
use craftpulse\warp\Warp;

beforeEach(function() {
    // craft-pest hands the suite a web request with no cookie validation key,
    // so the CSRF field `Html::beginForm()` emits would throw on cookie access.
    // Turning validation off is a harness precondition, not a Warp behavior.
    $request = Craft::$app->getRequest();

    if ($request instanceof craft\web\Request) {
        $request->enableCookieValidation = false;
    }
});

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

it('merges an inputAttrs class onto its own rather than replacing it', function() {
    $html = (string)(new OtpInputTag(['inputAttrs' => ['class' => 'input input--code']]));

    expect($html)->toContain('class="warp-otp input input--code"');
});

it('replaces its own input class when resetClass is passed', function() {
    $html = (string)(new OtpInputTag(['inputAttrs' => [
        'class' => 'input--code',
        'resetClass' => true,
    ]]));

    expect($html)->toContain('class="input--code"')
        ->and($html)->not->toContain('resetClass');
});

it('keeps the segmented widget alive when inputAttrs adds a data attribute', function() {
    $html = (string)(new OtpInputTag(['inputAttrs' => ['data' => ['mine' => 'yes']]]));

    expect($html)->toContain('data-mine="yes"')
        ->and($html)->toContain('data-warp-otp="6"');
});

it('hands the box and group attributes to the client script', function() {
    $html = (string)(new OtpInputTag([
        'boxAttrs' => ['class' => 'digit'],
        'boxesAttrs' => ['class' => 'digits', 'data' => ['group' => 'yes']],
        'enhancedClass' => 'is-hidden',
        'digitLabel' => 'Box {n}/{count}',
    ]));

    expect($html)->toContain(htmlspecialchars(Json::encode([
        'type' => 'text',
        'class' => 'warp-otp__box digit',
        'inputmode' => 'numeric',
    ]), ENT_QUOTES))
        ->and($html)->toContain(htmlspecialchars(Json::encode([
            'class' => 'warp-otp__boxes digits',
            'role' => 'group',
            'data-group' => 'yes',
        ]), ENT_QUOTES))
        ->and($html)->toContain('data-warp-otp-enhanced-class="is-hidden"')
        ->and($html)->toContain('Box {n}/{count}');
});

it('always emits the three attributes the client script has no fallback for', function() {
    // The script carries no class name of its own, so builder output must name
    // every one it depends on, even when the render was given no options at all.
    $html = (string)(new OtpInputTag());

    expect($html)->toContain('data-warp-otp-enhanced-class="warp-otp--enhanced"')
        ->and($html)->toContain('data-warp-otp-boxes="')
        ->and($html)->toContain('data-warp-otp-box="')
        ->and($html)->toContain('warp-otp__boxes')
        ->and($html)->toContain('warp-otp__box');
});

it('leaves every class name to the markup rather than falling back to one', function() {
    $js = (string)file_get_contents(dirname(__DIR__, 3) . '/src/web/assets/warpotp/warp-otp.js');

    expect($js)->not->toContain('warp-otp__boxes')
        ->and($js)->not->toContain('warp-otp__box')
        ->and($js)->not->toContain('warp-otp--enhanced')
        // And it does not add an empty class either, which throws.
        ->and($js)->toContain('if (enhancedClass) {');
});

it('registers the stylesheet and the script by default', function() {
    $bundles = warpRegisteredBundles(fn() => (string)(new OtpInputTag()));

    expect($bundles)->toContain(WarpFormsStyleAsset::class)
        ->and($bundles)->toContain(WarpOtpAsset::class);
});

it('omits the stylesheet when renderCss is off for the render', function() {
    $bundles = warpRegisteredBundles(fn() => (string)(new OtpInputTag(['renderCss' => false])));

    expect($bundles)->not->toContain(WarpFormsStyleAsset::class)
        ->and($bundles)->toContain(WarpOtpAsset::class);
});

it('omits the stylesheet when renderCss is off in the settings', function() {
    $settings = Warp::$plugin->getSettings();
    $settings->renderCss = false;

    try {
        $bundles = warpRegisteredBundles(fn() => (string)(new OtpFormTag()));
    } finally {
        $settings->renderCss = true;
    }

    expect($bundles)->not->toContain(WarpFormsStyleAsset::class)
        ->and($bundles)->toContain(WarpOtpAsset::class);
});

it('lets the form omit the stylesheet for itself and its nested input', function() {
    $bundles = warpRegisteredBundles(fn() => (string)(new OtpFormTag(['renderCss' => false])));

    expect($bundles)->not->toContain(WarpFormsStyleAsset::class);
});

it('keeps its cosmetic rules in a cascade layer a site rule beats', function() {
    $css = file_get_contents(dirname(__DIR__, 3) . '/src/web/assets/warpforms/warp-forms.css');

    expect($css)->toContain('@layer warp {')
        // The one behavioral rule stays out of the layer, so the boxes and the
        // raw input are never both visible.
        ->and($css)->toMatch('/^\.warp-otp--enhanced \{$/m');
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

it('merges a form class rather than replacing it', function() {
    $html = (string)(new OtpFormTag(['attrs' => ['class' => 'card']]));

    expect($html)->toContain('class="warp-otp-form card"');
});

it('addresses every element the verify form emits', function() {
    Craft::$app->getSession()->remove(AuthController::SESSION_REQUESTED_EMAIL);

    $html = (string)(new OtpFormTag([
        'emailAttrs' => ['class' => 'field__input'],
        'fieldAttrs' => ['class' => 'field'],
        'hintAttrs' => ['class' => 'field__hint'],
        'inputAttrs' => ['class' => 'field__input'],
        'labelAttrs' => ['class' => 'field__label'],
        'submitAttrs' => ['class' => 'button'],
    ]));

    expect($html)->toContain('class="warp-otp-form__field field"')
        ->and($html)->toContain('class="warp-otp-form__label field__label"')
        ->and($html)->toContain('class="warp-otp-form__email field__input"')
        ->and($html)->toContain('class="warp-otp-form__hint field__hint"')
        ->and($html)->toContain('class="warp-otp field__input"')
        ->and($html)->toContain('class="warp-otp-form__submit button"');
});

it('overrides every string the verify form renders', function() {
    Craft::$app->getSession()->set(AuthController::SESSION_REQUESTED_EMAIL, 'member@example.com');

    $html = (string)(new OtpFormTag([
        'changeLabel' => 'Wrong address?',
        'hint' => 'Type the {digits} numbers we mailed you.',
        'label' => 'Security code',
        'requestUrl' => '/members/login',
        'sentText' => 'We mailed {email}.',
        'submitLabel' => 'Continue',
    ]));

    expect($html)->toContain('Wrong address?')
        ->and($html)->toContain('Type the 6 numbers we mailed you.')
        ->and($html)->toContain('Security code')
        ->and($html)->toContain('We mailed member@example.com.')
        ->and($html)->toContain('Continue')
        ->and($html)->not->toContain('Sign-in code')
        ->and($html)->not->toContain('Use a different address');
});

it('overrides the email label on a direct visit', function() {
    Craft::$app->getSession()->remove(AuthController::SESSION_REQUESTED_EMAIL);

    $html = (string)(new OtpFormTag(['emailLabel' => 'Your address']));

    expect($html)->toContain('Your address')
        ->and($html)->not->toContain('Email address');
});

it('points the code input at the hint it renders', function() {
    $html = (string)(new OtpFormTag());

    preg_match('/id="([^"]+)-hint"/', $html, $matches);

    expect($matches[1] ?? null)->not->toBeNull()
        ->and($html)->toContain('aria-describedby="' . $matches[1] . '-hint"');
});

it('resolves both builders through the craft.warp variable in Twig', function() {
    $out = Craft::$app->getView()->renderString(
        '{{ craft.warp.otpInput({ digits: 5 }).render() }}||{{ craft.warp.otpForm().render() }}',
    );

    expect($out)->toContain('data-warp-otp="5"')
        ->and($out)->toContain('warp/auth/verify-code');
});
