<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Open-redirect matrix for the same-site return URL validator, plus the per-site
 * enforcement: a destination is honoured only when it belongs to the site the
 * request was made against, whether the sites differ by host or only by path
 * prefix.
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

it('rejects a destination on another site of the install', function() {
    $other = warpMakeSite('warpother', 'https://other.example.test/');

    try {
        expect(Redirect::validateReturnUrl('https://other.example.test/members'))->toBeNull()
            // The same URL is honoured for a request served by that site.
            ->and(warpWithCurrentSite($other, fn() => Redirect::validateReturnUrl('https://other.example.test/members')))
            ->toBe('https://other.example.test/members');
    } finally {
        warpDeleteSite($other);
    }
});

it('rejects a destination on a path-prefixed site sharing the host', function() {
    $base = rtrim((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), '/');
    $fr = warpMakeSite('warpfr', "{$base}/fr/", 'fr');

    try {
        expect(Redirect::validateReturnUrl("{$base}/fr/members"))->toBeNull()
            ->and(Redirect::validateReturnUrl("{$base}/members"))->toBe("{$base}/members")
            // And the other way around: the prefixed site owns its own URLs and
            // not the ones above its prefix.
            ->and(warpWithCurrentSite($fr, fn() => Redirect::validateReturnUrl("{$base}/fr/members")))
            ->toBe("{$base}/fr/members")
            ->and(warpWithCurrentSite($fr, fn() => Redirect::validateReturnUrl("{$base}/members")))
            ->toBeNull();
    } finally {
        warpDeleteSite($fr);
    }
});

it('enforces the path prefix on site-relative paths too', function() {
    $base = rtrim((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), '/');
    $fr = warpMakeSite('warpfr', "{$base}/fr/", 'fr');

    try {
        expect(Redirect::validateReturnUrl('/members'))->toBe('/members')
            ->and(Redirect::validateReturnUrl('/fr/members'))->toBeNull()
            ->and(warpWithCurrentSite($fr, fn() => Redirect::validateReturnUrl('/fr/members')))
            ->toBe('/fr/members')
            ->and(warpWithCurrentSite($fr, fn() => Redirect::validateReturnUrl('/members')))
            ->toBeNull();
    } finally {
        warpDeleteSite($fr);
    }
});

it('does not mistake a path prefix for a partial segment match', function() {
    $base = rtrim((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), '/');
    $fr = warpMakeSite('warpfr', "{$base}/fr/", 'fr');

    try {
        // /french belongs to the primary site, not to the site at /fr.
        expect(Redirect::validateReturnUrl('/french/members'))->toBe('/french/members')
            ->and(warpWithCurrentSite($fr, fn() => Redirect::validateReturnUrl('/french/members')))
            ->toBeNull();
    } finally {
        warpDeleteSite($fr);
    }
});

it('honours an explicitly named site over the current one', function() {
    $other = warpMakeSite('warpother', 'https://other.example.test/');

    try {
        expect(Redirect::validateReturnUrl('https://other.example.test/members', $other))
            ->toBe('https://other.example.test/members')
            ->and(Redirect::validateReturnUrl('https://other.example.test/members', Craft::$app->getSites()->getPrimarySite()))
            ->toBeNull();
    } finally {
        warpDeleteSite($other);
    }
});
