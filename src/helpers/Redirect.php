<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\helpers;

use Craft;
use craft\models\Site;

/**
 * Redirect validates user-supplied return URLs against the site the request was
 * made against, so Warp's passwordless entry points can never be used as open
 * redirectors, and never as a hop onto another site of the same install either.
 *
 * Enforcement is per site, not per install: a `returnUrl` is honoured only when
 * the site it belongs to is the site that served the request. A member signing
 * in on one site cannot be sent to another, which is what a session scoped to
 * one cookie domain would have delivered them signed out for anyway.
 *
 * Ownership is resolved the way [[\craft\web\Request]] resolves the requested
 * site: every site's base URL is matched against the URL and the longest match
 * wins, so two sites sharing a host and differing only by path prefix
 * (`https://example.test/` and `https://example.test/fr/`) each own their own
 * URLs rather than the shorter prefix owning both. Base URLs are read through
 * [[\craft\models\Site::getBaseUrl()]], which resolves an environment variable
 * or alias first, so an aliased base URL is compared in its resolved form.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
abstract class Redirect
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the given return URL if it is safe to redirect to, or `null`.
     *
     * Accepted: site-relative paths (`/members`) that sit under the site's own
     * base path, and absolute URLs that sit under the site's own base URL.
     * Rejected: anything belonging to another site of the install,
     * protocol-relative URLs, backslash tricks, control characters
     * (header-injection defense in depth — PHP's `header()` refuses them
     * anyway), and any host that is not this site.
     *
     * @param string|null $url the user-supplied return URL
     * @param Site|null $site the site the request was made against; defaults to
     * the current site, which is the one Craft resolved from the request URL
     * @return string|null
     * @throws \craft\errors\SiteNotFoundException if no sites exist
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public static function validateReturnUrl(?string $url, ?Site $site = null): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        if (str_contains($url, '\\')) {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        $site ??= Craft::$app->getSites()->getCurrentSite();

        if (str_starts_with($url, '/')) {
            if (str_starts_with($url, '//')) {
                return null;
            }

            return self::_ownsPath($site, $url) ? $url : null;
        }

        return self::_ownsUrl($site, $url) ? $url : null;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns a site's base URL host, or null when its base URL names none —
     * either because the site has no base URL at all or because it is a
     * host-less one such as `/fr/`.
     *
     * @param Site $site
     * @return string|null
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private static function _baseHost(Site $site): ?string
    {
        $baseUrl = $site->getBaseUrl();

        if ($baseUrl === null) {
            return null;
        }

        $host = parse_url($baseUrl, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * Returns a site's base path, normalized to no leading or trailing slash
     * (so a site at the root of its host returns an empty string), or null when
     * the site names no base URL at all.
     *
     * @param Site $site
     * @return string|null
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private static function _basePath(Site $site): ?string
    {
        $baseUrl = $site->getBaseUrl();

        if ($baseUrl === null) {
            return null;
        }

        return self::_normalizePath(parse_url($baseUrl, PHP_URL_PATH));
    }

    /**
     * Returns how specifically a site's base URL matches an absolute URL — the
     * length of the matched base URL, so a longer (more specific) base URL wins
     * a comparison — or null when it does not match at all.
     *
     * @param Site $site
     * @param string $url the absolute URL under test
     * @return int|null
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private static function _matchLength(Site $site, string $url): ?int
    {
        $baseUrl = $site->getBaseUrl();

        if ($baseUrl === null) {
            return null;
        }

        $prefix = rtrim($baseUrl, '/');

        return self::_startsUnder($url, $prefix) ? strlen($prefix) : null;
    }

    /**
     * Normalizes a URL path component into the form base paths are compared in:
     * collapsed slashes, no leading or trailing slash. A missing or unparseable
     * path becomes an empty string, which is what the root of a host is.
     *
     * @param string|null|false $path a `parse_url()` path component
     * @return string
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private static function _normalizePath(string|null|false $path): string
    {
        if (!is_string($path)) {
            return '';
        }

        return trim((string)preg_replace('/\/\/+/', '/', $path), '/');
    }

    /**
     * Returns whether a root-relative path belongs to the given site: it must
     * sit under that site's own base path, and no other site of the install may
     * claim it with a longer base path on the same host.
     *
     * A site that names no base URL has no path prefix to hold the URL to, so
     * every root-relative path is accepted for it — the pre-enforcement
     * behavior, kept for headless and URL-less sites.
     *
     * @param Site $site the site the request was made against
     * @param string $url the root-relative URL under test
     * @return bool
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private static function _ownsPath(Site $site, string $url): bool
    {
        $prefix = self::_basePath($site);

        if ($prefix === null) {
            return true;
        }

        if (!self::_pathStartsUnder($url, $prefix)) {
            return false;
        }

        $host = self::_baseHost($site);

        foreach (Craft::$app->getSites()->getAllSites() as $other) {
            if ($other->id === $site->id) {
                continue;
            }

            $otherPrefix = self::_basePath($other);

            if ($otherPrefix === null || strlen($otherPrefix) <= strlen($prefix)) {
                continue;
            }

            // A site on another host cannot claim a path on this one.
            $otherHost = self::_baseHost($other);

            if ($host !== null && $otherHost !== null && $otherHost !== $host) {
                continue;
            }

            if (self::_pathStartsUnder($url, $otherPrefix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns whether an absolute URL belongs to the given site: it must sit
     * under that site's own base URL, and no other site of the install may match
     * it more specifically.
     *
     * @param Site $site the site the request was made against
     * @param string $url the absolute URL under test
     * @return bool
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private static function _ownsUrl(Site $site, string $url): bool
    {
        $length = self::_matchLength($site, $url);

        if ($length === null) {
            return false;
        }

        foreach (Craft::$app->getSites()->getAllSites() as $other) {
            if ($other->id === $site->id) {
                continue;
            }

            $otherLength = self::_matchLength($other, $url);

            if ($otherLength !== null && $otherLength > $length) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns whether a URL's path sits under `$prefix` at a segment boundary,
     * the comparison [[\craft\web\Request]] scores a site's base path with, so a
     * prefix like `fr` never matches `french/pages`.
     *
     * @param string $url the URL whose path is under test
     * @param string $prefix the normalized base path the target must sit under
     * @return bool
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private static function _pathStartsUnder(string $url, string $prefix): bool
    {
        if ($prefix === '') {
            return true;
        }

        $path = self::_normalizePath(parse_url($url, PHP_URL_PATH));

        return str_starts_with($path . '/', $prefix . '/');
    }

    /**
     * Returns whether `$url` sits under `$prefix` at a path boundary — the
     * character after the prefix must be a delimiter, not more path, so a prefix
     * like `https://site.test` never matches `https://site.test.evil`.
     *
     * @param string $url the URL under test
     * @param string $prefix the base URL the target must sit under
     * @return bool
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private static function _startsUnder(string $url, string $prefix): bool
    {
        if (!str_starts_with($url, $prefix)) {
            return false;
        }

        $next = substr($url, strlen($prefix), 1);

        return $next === '' || $next === '/' || $next === '?' || $next === '#';
    }
}
