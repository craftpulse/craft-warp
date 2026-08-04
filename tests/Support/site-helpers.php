<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Shared multi-site test fixture. The return-URL validator enforces that a
 * destination belongs to the site the request was made against, which only has
 * teeth on an install that holds more than one site: a genuinely isolated
 * `db_test` install starts single-site, so the second and third sites those
 * assertions need are created here rather than assumed.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\models\Site;

/**
 * Creates and saves a site, returning it, and returns an already-existing site
 * of the same handle untouched so a repeated call inside one test is harmless.
 *
 * `craft\services\Sites` memoizes every site for the lifetime of the process —
 * craft-pest boots one Craft application for the whole run — so a site created
 * here must be deleted through {@see warpDeleteSite()} within the same test
 * rather than left to the `RefreshesDatabase` rollback, which drops the row
 * while leaving the service's in-memory copy live for every later test.
 *
 * @param string $handle
 * @param string $baseUrl
 * @param string $language
 * @return Site
 *
 * @author CraftPulse
 * @since 5.0.0
 */
function warpMakeSite(string $handle, string $baseUrl, string $language = 'en'): Site
{
    $sites = Craft::$app->getSites();
    $existing = $sites->getSiteByHandle($handle);

    if ($existing !== null) {
        return $existing;
    }

    $site = new Site([
        'groupId' => $sites->getPrimarySite()->groupId,
        'name' => ucfirst($handle),
        'handle' => $handle,
        'language' => $language,
        'hasUrls' => true,
        'baseUrl' => $baseUrl,
    ]);

    if (!$sites->saveSite($site)) {
        throw new RuntimeException("Could not save the Warp test site {$handle}: " . implode(', ', $site->getFirstErrors()));
    }

    _warpRefreshIsMultiSite();

    return $site;
}

/**
 * Deletes a site created by {@see warpMakeSite()}, keeping the Sites service's
 * memoization consistent with the database instead of depending on the per-test
 * transaction rollback to do it.
 *
 * @param Site $site
 * @return void
 *
 * @author CraftPulse
 * @since 5.0.0
 */
function warpDeleteSite(Site $site): void
{
    Craft::$app->getSites()->deleteSite($site);
    _warpRefreshIsMultiSite();
}

/**
 * Runs the given callback with the current site set to `$site`, restoring the
 * previous current site afterwards — the stand-in for "the request was served by
 * this site", which is what the return-URL validator reads.
 *
 * @param Site $site
 * @param callable $callback
 * @return mixed
 *
 * @author CraftPulse
 * @since 5.0.0
 */
function warpWithCurrentSite(Site $site, callable $callback): mixed
{
    $sites = Craft::$app->getSites();
    $previous = $sites->getCurrentSite();
    $sites->setCurrentSite($site);

    try {
        return $callback();
    } finally {
        $sites->setCurrentSite($previous);
    }
}

/**
 * Refreshes both of `craft\base\ApplicationTrait::getIsMultiSite()`'s
 * independently memoized results, the plain call and the `$withTrashed=true`
 * variant `craft\elements\db\ElementQuery::prepare()` gates its own site
 * scoping on. Left stale from the single-site state an earlier test observed,
 * every element query silently drops its site filtering.
 *
 * @return void
 *
 * @author CraftPulse
 * @since 5.0.0
 */
function _warpRefreshIsMultiSite(): void
{
    Craft::$app->getIsMultiSite(true, false);
    Craft::$app->getIsMultiSite(true, true);
}
