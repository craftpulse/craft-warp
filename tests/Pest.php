<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Pest configuration — binds craft-pest's TestCase (which boots Craft and
 * wraps each test in a database transaction) to every test in this suite.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use markhuot\craftpest\test\TestCase;
use yii\caching\ArrayCache;

// Per-test in-memory cache keeps cache state isolated between tests and avoids
// the playground FileCache's filemtime() stat warnings.
uses(TestCase::class)
    ->beforeEach(function() {
        Craft::$app->set('cache', new ArrayCache());
    })
    ->in(__DIR__);

/**
 * Sets Craft's public-registration switch only when it differs from the
 * current value. A project-config write inside craft-pest's per-test
 * transaction can desync the memoized config version from the stored one
 * (surfacing as a StaleResourceException on a later write), so the suite
 * skips every write it does not strictly need.
 */
function setAllowPublicRegistration(bool $value): void
{
    if ((bool)Craft::$app->getProjectConfig()->get('users.allowPublicRegistration') !== $value) {
        Craft::$app->getProjectConfig()->set('users.allowPublicRegistration', $value);
    }
}
