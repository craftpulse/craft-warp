<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Tests for the passkey-button render builder: it emits the container the client
 * script binds to, the button, the fallback line, the status live region and
 * Craft's CSRF field, carries the two core endpoint URLs and both failure
 * messages as data attributes, validates its returnUrl the way the endpoints do,
 * exposes every element and string it renders, registers Warp's script and
 * stylesheet, fails loud on a typo, and resolves through the craft.warp Twig
 * variable.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\helpers\UrlHelper;
use craftpulse\warp\assetbundles\warpforms\WarpFormsStyleAsset;
use craftpulse\warp\assetbundles\warppasskey\WarpPasskeyAsset;
use craftpulse\warp\twig\tags\PasskeyButtonTag;

beforeEach(function() {
    // craft-pest hands the suite a web request with no cookie validation key, so
    // the CSRF field this builder emits would throw on cookie access. Turning
    // validation off is a harness precondition, not a Warp behavior.
    $request = Craft::$app->getRequest();

    if ($request instanceof craft\web\Request) {
        $request->enableCookieValidation = false;
    }
});

it('emits the container, button, fallback line and status region', function() {
    $html = (string)(new PasskeyButtonTag());

    expect($html)->toContain('class="warp-passkey"')
        ->and($html)->toMatch('/data-warp-passkey[\s>]/')
        ->and($html)->toContain('class="warp-passkey__button"')
        ->and($html)->toContain('type="button"')
        ->and($html)->toContain('data-warp-passkey-button')
        ->and($html)->toContain('class="warp-passkey__fallback"')
        ->and($html)->toContain('data-warp-passkey-fallback')
        ->and($html)->toContain('class="warp-passkey__status"')
        ->and($html)->toContain('data-warp-passkey-status')
        ->and($html)->toContain('role="alert"')
        ->and($html)->toContain('tabindex="-1"')
        ->and($html)->toContain('Sign in with a passkey')
        ->and($html)->toContain('Passkeys are not available in this browser.');
});

it('hides both live regions until the script writes into them', function() {
    $html = (string)(new PasskeyButtonTag());

    preg_match_all('/<p [^>]*>/', $html, $matches);

    expect($matches[0])->toHaveCount(2)
        ->and($matches[0][0])->toContain('hidden')
        ->and($matches[0][1])->toContain('hidden');
});

it('carries both core endpoint URLs and the CSRF field name', function() {
    $html = (string)(new PasskeyButtonTag());
    $csrfParam = Craft::$app->getRequest()->csrfParam;

    expect($html)->toContain(htmlspecialchars(UrlHelper::actionUrl('auth/passkey-request-options'), ENT_QUOTES))
        ->and($html)->toContain(htmlspecialchars(UrlHelper::actionUrl('users/login-with-passkey'), ENT_QUOTES))
        ->and($html)->toContain('data-warp-passkey-csrf-field="' . $csrfParam . '"')
        ->and($html)->toContain('name="' . $csrfParam . '"');
});

it('hands both failure messages to the client script', function() {
    $html = (string)(new PasskeyButtonTag());

    expect($html)->toContain('data-warp-passkey-cancelled-text="Passkey sign-in was cancelled or timed out.')
        ->and($html)->toContain('data-warp-passkey-failed-text="Passkey sign-in failed.');
});

it('lands a completed ceremony on the site root by default', function() {
    $html = (string)(new PasskeyButtonTag());

    expect($html)->toContain('data-warp-passkey-return-url="' . htmlspecialchars(UrlHelper::siteUrl(), ENT_QUOTES) . '"');
});

it('honours a returnUrl belonging to this site', function() {
    $html = (string)(new PasskeyButtonTag(['returnUrl' => '/members/account']));

    expect($html)->toContain('data-warp-passkey-return-url="/members/account"');
});

it('refuses a returnUrl the endpoints would refuse', function() {
    $siteRoot = htmlspecialchars(UrlHelper::siteUrl(), ENT_QUOTES);

    $offSite = (string)(new PasskeyButtonTag(['returnUrl' => 'https://evil.example.com/members']));
    $scheme = (string)(new PasskeyButtonTag(['returnUrl' => 'javascript:alert(1)']));
    $protocolRelative = (string)(new PasskeyButtonTag(['returnUrl' => '//evil.example.com']));

    expect($offSite)->toContain('data-warp-passkey-return-url="' . $siteRoot . '"')
        ->and($offSite)->not->toContain('evil.example.com')
        ->and($scheme)->toContain('data-warp-passkey-return-url="' . $siteRoot . '"')
        ->and($scheme)->not->toContain('javascript:')
        ->and($protocolRelative)->toContain('data-warp-passkey-return-url="' . $siteRoot . '"')
        ->and($protocolRelative)->not->toContain('//evil.example.com');
});

it('refuses a returnUrl belonging to another site of the install', function() {
    $other = warpMakeSite('warpother', 'https://other.example.test/');

    try {
        $html = (string)(new PasskeyButtonTag(['returnUrl' => 'https://other.example.test/members']));
    } finally {
        warpDeleteSite($other);
    }

    expect($html)->toContain('data-warp-passkey-return-url="' . htmlspecialchars(UrlHelper::siteUrl(), ENT_QUOTES) . '"')
        ->and($html)->not->toContain('other.example.test');
});

it('addresses every element it emits', function() {
    $html = (string)(new PasskeyButtonTag([
        'attrs' => ['class' => 'card', 'data' => ['mine' => 'yes']],
        'buttonAttrs' => ['class' => 'button button--secondary'],
        'fallbackAttrs' => ['class' => 'note'],
        'statusAttrs' => ['class' => 'note note--error'],
    ]));

    expect($html)->toContain('class="warp-passkey card"')
        ->and($html)->toContain('data-mine="yes"')
        // A data attribute of the caller's never takes the script's marker with it.
        ->and($html)->toMatch('/data-warp-passkey[\s>]/')
        ->and($html)->toContain('class="warp-passkey__button button button--secondary"')
        ->and($html)->toContain('class="warp-passkey__fallback note"')
        ->and($html)->toContain('class="warp-passkey__status note note--error"');
});

it('owns the container class outright when resetClass is passed', function() {
    $html = (string)(new PasskeyButtonTag(['attrs' => ['class' => 'card', 'resetClass' => true]]));

    expect($html)->toContain('class="card"')
        ->and($html)->not->toContain('class="warp-passkey"')
        ->and($html)->not->toContain('resetClass');
});

it('overrides every string it renders', function() {
    $html = (string)(new PasskeyButtonTag([
        'label' => 'Use your passkey',
        'fallbackText' => 'This browser cannot do passkeys.',
        'cancelledText' => 'You cancelled that.',
        'failedText' => 'That did not work.',
    ]));

    expect($html)->toContain('Use your passkey')
        ->and($html)->toContain('This browser cannot do passkeys.')
        ->and($html)->toContain('data-warp-passkey-cancelled-text="You cancelled that."')
        ->and($html)->toContain('data-warp-passkey-failed-text="That did not work."')
        ->and($html)->not->toContain('Sign in with a passkey')
        ->and($html)->not->toContain('Passkeys are not available in this browser.');
});

it('escapes the copy it renders', function() {
    $html = (string)(new PasskeyButtonTag(['label' => '<em>oops</em>']));

    expect($html)->toContain('&lt;em&gt;oops&lt;/em&gt;')
        ->and($html)->not->toContain('<em>oops</em>');
});

it('registers the behavior script and the stylesheet by default', function() {
    $bundles = warpRegisteredBundles(fn() => (string)(new PasskeyButtonTag()));

    expect($bundles)->toContain(WarpPasskeyAsset::class)
        ->and($bundles)->toContain(WarpFormsStyleAsset::class);
});

it('omits the stylesheet when renderCss is off for the render', function() {
    $bundles = warpRegisteredBundles(fn() => (string)(new PasskeyButtonTag(['renderCss' => false])));

    expect($bundles)->not->toContain(WarpFormsStyleAsset::class)
        ->and($bundles)->toContain(WarpPasskeyAsset::class);
});

it('registers Auth Kit\'s WebAuthn client script, which owns the ceremony', function() {
    $view = Craft::$app->getView();

    (string)(new PasskeyButtonTag());

    $files = array_keys($view->jsFiles[craft\web\View::POS_END] ?? []);
    $webauthn = array_filter($files, fn(string $url): bool => str_contains($url, 'authkit-webauthn.js'));

    expect($webauthn)->not->toBeEmpty();
});

it('keeps the passkey section\'s hiding rule out of the cascade layer', function() {
    $css = file_get_contents(dirname(__DIR__, 3) . '/src/web/assets/warpforms/warp-forms.css');

    expect($css)->toMatch('/^\.warp-passkey \[hidden\] \{$/m')
        ->and($css)->toContain('.warp-passkey__button {');
});

it('throws on an unknown builder option so typos fail loud', function() {
    new PasskeyButtonTag(['labell' => 'Sign in']);
})->throws(InvalidArgumentException::class);

it('resolves through the craft.warp variable in Twig', function() {
    $out = Craft::$app->getView()->renderString(
        '{{ craft.warp.passkeyButton({ returnUrl: "/members/account" }).render() }}',
    );

    expect($out)->toContain('data-warp-passkey-button')
        ->and($out)->toContain('data-warp-passkey-return-url="/members/account"');
});
