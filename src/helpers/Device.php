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
 * Device turns a raw user-agent string into a coarse, human-friendly label like
 * "Chrome on macOS" — the name a member sees against each active session.
 *
 * This is display-only labelling, deliberately shallow: a handful of substring
 * checks over the common browser and OS families, no third-party parser, and no
 * attempt at recognition. It is emphatically NOT fingerprinting (see decision D5
 * in the build plan) — two different phones can share a label, and that is fine.
 * The label never influences authorization; it only helps a person tell "my
 * laptop" from "my phone" in a list.
 *
 * Order matters in both passes: several user-agents embed older tokens for
 * compatibility (Edge and Opera both carry `Chrome`; Chrome carries `Safari`),
 * so the more specific family is tested first and wins.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
abstract class Device
{
    // Public Methods
    // =========================================================================

    /**
     * Returns a coarse "Browser on OS" label for a user-agent string, or a
     * generic "Unknown device" when nothing recognisable is found (or the
     * string is missing entirely).
     *
     * @param string|null $userAgent the raw user-agent, or null when none was captured
     * @return string a display label — "Chrome on macOS", "Safari", "Windows", or "Unknown device"
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public static function label(?string $userAgent): string
    {
        $unknown = Craft::t('warp', 'Unknown device');

        if ($userAgent === null || trim($userAgent) === '') {
            return $unknown;
        }

        $browser = self::_browser($userAgent);
        $os = self::_os($userAgent);

        if ($browser !== null && $os !== null) {
            return Craft::t('warp', '{browser} on {os}', ['browser' => $browser, 'os' => $os]);
        }

        return $browser ?? $os ?? $unknown;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the coarse browser family for a user-agent, or null when none of
     * the known families match.
     *
     * @param string $userAgent the raw user-agent
     * @return string|null the browser name, or null
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private static function _browser(string $userAgent): ?string
    {
        return match (true) {
            str_contains($userAgent, 'Edg') => 'Edge',
            str_contains($userAgent, 'OPR/'), str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Firefox') => 'Firefox',
            str_contains($userAgent, 'Chrome') => 'Chrome',
            str_contains($userAgent, 'Safari') => 'Safari',
            default => null,
        };
    }

    /**
     * Returns the coarse operating-system family for a user-agent, or null when
     * none of the known families match.
     *
     * @param string $userAgent the raw user-agent
     * @return string|null the OS name, or null
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private static function _os(string $userAgent): ?string
    {
        return match (true) {
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad'), str_contains($userAgent, 'iPod') => 'iOS',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X'), str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'CrOS') => 'ChromeOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };
    }
}
