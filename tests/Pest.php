<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Pest configuration — binds craft-pest's TestCase AND its `RefreshesDatabase`
 * trait to every test in this suite. `TestCase` alone boots Craft but does NOT
 * wrap tests in a transaction; only `RefreshesDatabase` opens a transaction in
 * `setUp()` and rolls it back in `tearDown()` (see
 * `markhuot\craftpest\test\RefreshesDatabase`). Without it every factory write
 * in this suite committed permanently.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\enums\CmsEdition;
use craftpulse\authkit\AuthKit;
use markhuot\craftpest\test\RefreshesDatabase;
use markhuot\craftpest\test\TestCase;
use yii\caching\ArrayCache;

/**
 * Silences every audit surface Warp's flows can reach: Auth Kit's own legacy
 * sink registry, and the bus a bridge plugin (Audit Kit's AuthKitBridge, if
 * co-installed) forwards into. Auth Kit's `Audit::record()` fires both the
 * sink fan-out and, additively, `EVENT_AFTER_RECORD` — muting only the sink
 * array would leave that second path live, so a test that never explicitly
 * resets it would still relay real audit rows onto a co-installed Audit
 * Kit's bus. Guarded by class_exists() so the suite still runs when Audit
 * Kit is absent (it is not one of Warp's dependencies).
 */
function muteAuditSurfaces(): void
{
    // 1. Auth Kit's own sink registry — the one Warp's own tests populate
    // with CollectingAuditSink doubles.
    AuthKit::$plugin->getAudit()->setSinks([]);

    // 2. The bus a co-installed Audit Kit's bridge forwards into.
    if (class_exists(\craftpulse\auditkit\AuditKit::class)) {
        \craftpulse\auditkit\AuditKit::$plugin->getBus()->setSinks([]);
    }
}

// Per-test in-memory cache: avoids the playground FileCache's filemtime() stat
// warnings during project-config writes and keeps cache state isolated.
//
// Craft edition is pinned to Pro on every test rather than inherited from
// whatever a fresh `db_test` install defaults to (Solo): Solo caps user
// creation at one (User::beforeSave() silently vetoes further saves via
// Users::canCreateUsers()), which several of Warp's flows exercise directly,
// and craft\services\UserGroups::saveGroup()/deleteGroupById() additionally
// `requireEdition(CmsEdition::Pro)` for the registration/auth-group fixtures
// this suite creates. setEdition() writes to project config, so it is rolled
// back with the rest of the per-test transaction and must be re-pinned every
// test rather than once at bootstrap.
uses(TestCase::class, RefreshesDatabase::class)
    ->beforeEach(function() {
        Craft::$app->set('cache', new ArrayCache());
        Craft::$app->setEdition(CmsEdition::Pro);
        muteAuditSurfaces();
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
