<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Open-redirect matrix for the same-site return URL validator.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\warp\helpers\Redirect;

it('accepts a site-relative path', function() {
    expect(Redirect::validateReturnUrl('/members'))->toBe('/members')
        ->and(Redirect::validateReturnUrl('/members?tab=profile'))->toBe('/members?tab=profile');
});

it('accepts an absolute URL under a site base URL', function() {
    $base = rtrim((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), '/');

    expect(Redirect::validateReturnUrl("{$base}/members"))->toBe("{$base}/members")
        ->and(Redirect::validateReturnUrl($base))->toBe($base);
});

it('rejects protocol-relative and backslash URLs', function() {
    expect(Redirect::validateReturnUrl('//evil.example.com'))->toBeNull()
        ->and(Redirect::validateReturnUrl('/\\evil.example.com'))->toBeNull()
        ->and(Redirect::validateReturnUrl('https://site.test\\@evil.example.com'))->toBeNull();
});

it('rejects foreign hosts', function() {
    expect(Redirect::validateReturnUrl('https://evil.example.com/members'))->toBeNull();
});

it('rejects host-prefix tricks on the site base URL', function() {
    $base = rtrim((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), '/');

    expect(Redirect::validateReturnUrl("{$base}.evil.example.com/phish"))->toBeNull();
});

it('rejects URLs containing control characters', function() {
    expect(Redirect::validateReturnUrl("/members\nSet-Cookie: x=y"))->toBeNull()
        ->and(Redirect::validateReturnUrl("/members\r\nLocation: https://evil.example.com"))->toBeNull()
        ->and(Redirect::validateReturnUrl("/mem\tbers"))->toBeNull()
        ->and(Redirect::validateReturnUrl("/members\0"))->toBeNull();
});

it('passes empty values through as null', function() {
    expect(Redirect::validateReturnUrl(null))->toBeNull()
        ->and(Redirect::validateReturnUrl(''))->toBeNull();
});
