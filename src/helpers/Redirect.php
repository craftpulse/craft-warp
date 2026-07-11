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

/**
 * Redirect validates user-supplied return URLs against the install's site base
 * URLs so Warp's passwordless entry points can never be used as open
 * redirectors.
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
     * Accepted: site-relative paths (`/members`), and absolute URLs that sit
     * under one of the install's site base URLs. Rejected: protocol-relative
     * URLs, backslash tricks, control characters (header-injection defense in
     * depth — PHP's `header()` refuses them anyway), and any host that is not a
     * configured site.
     *
     * @param string|null $url the user-supplied return URL
     * @return string|null
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public static function validateReturnUrl(?string $url): ?string
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

        if (str_starts_with($url, '/')) {
            return str_starts_with($url, '//') ? null : $url;
        }

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $baseUrl = $site->getBaseUrl();

            if ($baseUrl === null) {
                continue;
            }

            // Guard against prefix tricks: https://site.test must not accept
            // https://site.test.evil.com.
            if (self::_startsUnder($url, rtrim($baseUrl, '/'))) {
                return $url;
            }
        }

        return null;
    }

    // Private Methods
    // =========================================================================

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
