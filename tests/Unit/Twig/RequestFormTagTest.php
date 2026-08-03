<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Tests for the request-form render builder: it posts to warp/auth/request with
 * CSRF and a labelled email input, offers the enabled channels as a choice or a
 * fixed hidden field, hashes each channel's own redirect for the client script,
 * registers that script only when it is needed, exposes every element and
 * string it renders, and resolves through the craft.warp Twig variable.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\warp\assetbundles\warpforms\WarpFormsStyleAsset;
use craftpulse\warp\assetbundles\warprequest\WarpRequestAsset;
use craftpulse\warp\models\Settings;
use craftpulse\warp\twig\tags\RequestFormTag;
use craftpulse\warp\Warp;

beforeEach(function() {
    // craft-pest hands the suite a web request with no cookie validation key,
    // so the CSRF field `Html::beginForm()` emits would throw on cookie access.
    $request = Craft::$app->getRequest();

    if ($request instanceof craft\web\Request) {
        $request->enableCookieValidation = false;
    }
});

/**
 * Runs the given callback with `loginMethods` narrowed to the given channels.
 *
 * @param array<int, string> $channels
 * @param callable $callback
 * @return string
 */
function warpWithLoginMethods(array $channels, callable $callback): string
{
    $settings = Warp::$plugin->getSettings();
    $original = $settings->loginMethods;
    $settings->loginMethods = $channels;

    try {
        return $callback();
    } finally {
        $settings->loginMethods = $original;
    }
}

it('posts the email address to the request endpoint', function() {
    $html = (string)(new RequestFormTag());

    expect($html)->toContain('warp/auth/request')
        ->and($html)->toContain('method="post"')
        ->and($html)->toContain('name="email"')
        ->and($html)->toContain('type="email"')
        ->and($html)->toContain('autocomplete="email"')
        ->and($html)->toContain('Email address')
        ->and($html)->toContain('type="submit"');
});

it('offers both enabled channels as a labelled choice', function() {
    $html = warpWithLoginMethods(
        [Settings::CHANNEL_MAGIC_LINK, Settings::CHANNEL_OTP],
        fn() => (string)(new RequestFormTag()),
    );

    expect($html)->toContain('<fieldset')
        ->and($html)->toContain('How would you like to sign in?')
        ->and($html)->toContain('value="magic-link"')
        ->and($html)->toContain('value="otp"')
        ->and($html)->toContain('Email me a sign-in link')
        ->and($html)->toContain('Email me a sign-in code')
        ->and($html)->toContain('Continue');
});

it('posts a fixed channel when the site enables only one', function() {
    $html = warpWithLoginMethods(
        [Settings::CHANNEL_OTP],
        fn() => (string)(new RequestFormTag()),
    );

    expect($html)->toContain('<input type="hidden" name="channel" value="otp">')
        ->and($html)->not->toContain('<fieldset')
        // With one channel the submit button says what it will do.
        ->and($html)->toContain('Email me a sign-in code');
});

it('forces a single channel when one is named', function() {
    $html = warpWithLoginMethods(
        [Settings::CHANNEL_MAGIC_LINK, Settings::CHANNEL_OTP],
        fn() => (string)(new RequestFormTag(['channel' => Settings::CHANNEL_MAGIC_LINK])),
    );

    expect($html)->toContain('name="channel" value="magic-link"')
        ->and($html)->not->toContain('<fieldset');
});

it('hashes the redirect of each channel for the client script', function() {
    $html = warpWithLoginMethods(
        [Settings::CHANNEL_MAGIC_LINK, Settings::CHANNEL_OTP],
        fn() => (string)(new RequestFormTag([
            'linkSentUrl' => 'members/link-sent',
            'otpVerifyUrl' => 'members/otp-verify',
        ])),
    );

    $security = Craft::$app->getSecurity();

    expect($html)->toContain('name="redirect" value="' . htmlspecialchars($security->hashData('members/link-sent'), ENT_QUOTES) . '"')
        ->and($html)->toContain('data-warp-redirect="' . htmlspecialchars($security->hashData('members/otp-verify'), ENT_QUOTES) . '"')
        ->and($html)->toContain('data-warp-request');
});

it('uses the one page it was given for both channels', function() {
    $html = warpWithLoginMethods(
        [Settings::CHANNEL_MAGIC_LINK, Settings::CHANNEL_OTP],
        fn() => (string)(new RequestFormTag(['otpVerifyUrl' => 'members/otp-verify'])),
    );

    $hash = htmlspecialchars(Craft::$app->getSecurity()->hashData('members/otp-verify'), ENT_QUOTES);

    expect(substr_count($html, $hash))->toBe(3);
});

it('posts no redirect when it was given no page', function() {
    $html = (string)(new RequestFormTag());

    expect($html)->not->toContain('name="redirect"')
        ->and($html)->not->toContain('data-warp-redirect');
});

it('registers the script only when a channel choice can change the redirect', function() {
    $withChoice = warpRegisteredBundles(fn() => warpWithLoginMethods(
        [Settings::CHANNEL_MAGIC_LINK, Settings::CHANNEL_OTP],
        fn() => (string)(new RequestFormTag(['otpVerifyUrl' => 'members/otp-verify'])),
    ));

    $singleChannel = warpRegisteredBundles(fn() => warpWithLoginMethods(
        [Settings::CHANNEL_OTP],
        fn() => (string)(new RequestFormTag(['otpVerifyUrl' => 'members/otp-verify'])),
    ));

    $noRedirect = warpRegisteredBundles(fn() => warpWithLoginMethods(
        [Settings::CHANNEL_MAGIC_LINK, Settings::CHANNEL_OTP],
        fn() => (string)(new RequestFormTag()),
    ));

    expect($withChoice)->toContain(WarpRequestAsset::class)
        ->and($singleChannel)->not->toContain(WarpRequestAsset::class)
        ->and($noRedirect)->not->toContain(WarpRequestAsset::class);
});

it('registers the stylesheet unless the render omits it', function() {
    $on = warpRegisteredBundles(fn() => (string)(new RequestFormTag()));
    $off = warpRegisteredBundles(fn() => (string)(new RequestFormTag(['renderCss' => false])));

    expect($on)->toContain(WarpFormsStyleAsset::class)
        ->and($off)->not->toContain(WarpFormsStyleAsset::class);
});

it('posts the returnUrl it was given', function() {
    $html = (string)(new RequestFormTag(['returnUrl' => '/members/account']));

    expect($html)->toContain('name="returnUrl" value="/members/account"');
});

it('prefills the email input', function() {
    $html = (string)(new RequestFormTag(['email' => 'member@example.com']));

    expect($html)->toContain('value="member@example.com"');
});

it('addresses every element it emits', function() {
    $html = warpWithLoginMethods(
        [Settings::CHANNEL_MAGIC_LINK, Settings::CHANNEL_OTP],
        fn() => (string)(new RequestFormTag([
            'attrs' => ['class' => 'card'],
            'channelsAttrs' => ['class' => 'choices'],
            'choiceAttrs' => ['class' => 'choice'],
            'emailAttrs' => ['class' => 'field__input'],
            'fieldAttrs' => ['class' => 'field'],
            'labelAttrs' => ['class' => 'field__label'],
            'legendAttrs' => ['class' => 'choices__legend'],
            'radioAttrs' => ['class' => 'radio'],
            'submitAttrs' => ['class' => 'button'],
        ])),
    );

    expect($html)->toContain('class="warp-request-form card"')
        ->and($html)->toContain('class="warp-request-form__field field"')
        ->and($html)->toContain('class="warp-request-form__label field__label"')
        ->and($html)->toContain('class="warp-request-form__email field__input"')
        ->and($html)->toContain('class="warp-request-form__channels choices"')
        ->and($html)->toContain('class="warp-request-form__legend choices__legend"')
        ->and($html)->toContain('class="warp-request-form__choice choice"')
        ->and($html)->toContain('class="radio"')
        ->and($html)->toContain('class="warp-request-form__submit button"');
});

it('owns the form class outright when resetClass is passed', function() {
    $html = (string)(new RequestFormTag(['attrs' => ['class' => 'card', 'resetClass' => true]]));

    expect($html)->toContain('class="card"')
        ->and($html)->not->toContain('warp-request-form"');
});

it('overrides every string it renders', function() {
    $html = warpWithLoginMethods(
        [Settings::CHANNEL_MAGIC_LINK, Settings::CHANNEL_OTP],
        fn() => (string)(new RequestFormTag([
            'emailLabel' => 'Your address',
            'legend' => 'Pick one',
            'magicLinkLabel' => 'Send a link',
            'otpLabel' => 'Send a code',
            'submitLabel' => 'Go',
        ])),
    );

    expect($html)->toContain('Your address')
        ->and($html)->toContain('Pick one')
        ->and($html)->toContain('Send a link')
        ->and($html)->toContain('Send a code')
        ->and($html)->toContain('Go')
        ->and($html)->not->toContain('Email address')
        ->and($html)->not->toContain('How would you like to sign in?');
});

it('throws on an unknown builder option so typos fail loud', function() {
    new RequestFormTag(['chanel' => 'otp']);
})->throws(InvalidArgumentException::class);

it('resolves through the craft.warp variable in Twig', function() {
    $out = Craft::$app->getView()->renderString(
        '{{ craft.warp.requestForm({ otpVerifyUrl: "members/otp-verify" }).render() }}',
    );

    expect($out)->toContain('warp/auth/request')
        ->and($out)->toContain('name="email"');
});
