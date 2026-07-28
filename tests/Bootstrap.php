<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Pest / PHPUnit bootstrap. Run the suite from Warp's OWN root — its own
 * `vendor/bin/pest` (or `ddev composer test`, which resolves to the same
 * binary) — never via a shared playground's
 * `ddev craft pest -- --configuration=vendor/craftpulse/craft-warp/phpunit.xml.dist`.
 * That shared invocation leaves the process's working directory at the
 * playground's Craft root, not here, so craft-pest-core's InstallsCraft
 * plugin never finds this plugin's `phpunit.xml.dist` and its `<env>` DB
 * overrides in `phpunit.xml.dist` (see the comment there) never apply —
 * Craft boots against the playground's live `db` instead of `db_test` and
 * every fixture this suite writes commits permanently.
 *
 * With the correct invocation the working directory is this plugin's own
 * root, so its own autoloader (already mapping both Craft and the plugin's
 * own `src`/`tests`) is authoritative and craft-pest's TestCase boots the
 * application itself against `db_test` — this file only wires autoloading,
 * installs the plugin(s) under test, and pins the process timezone.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

$craftBase = getcwd();

// Warp vendors its own `craftcms/cms` (see composer.json) precisely so its
// suite can boot a fully isolated Craft install rather than depend on
// whatever plugins happen to be co-installed in a shared app. Checking for
// `vendor/craftcms/cms` (rather than a `craft` executable, which only exists
// at a consuming project's root, never a plugin's own) confirms this really
// is Warp's own root before autoloading from it.
if ($craftBase === false || !is_dir($craftBase . '/vendor/craftcms/cms')) {
    fwrite(STDERR, "Warp test bootstrap could not locate a standalone Craft install at cwd={$craftBase}.\n");
    fwrite(STDERR, "Run tests from Warp's own root, e.g.:\n");
    fwrite(STDERR, "  ddev exec --dir /var/www/html/cms/vendor/craftpulse/craft-warp vendor/bin/pest\n");
    fwrite(STDERR, "  ddev exec --dir /var/www/html/cms/vendor/craftpulse/craft-warp composer test\n");
    exit(1);
}

$composerLoader = require $craftBase . '/vendor/autoload.php';

// The plugin's autoload-dev mapping never lands in the consuming project's
// vendor dir, so the test namespace is registered here.
if (is_object($composerLoader) && method_exists($composerLoader, 'addPsr4')) {
    $composerLoader->addPsr4('craftpulse\\warp\\tests\\', __DIR__ . '/');
}

// Pest auto-discovers tests/Pest.php from the test root once getcwd() is this
// plugin's own root (true for the standalone invocation this file requires
// above), so it is NOT required here as well: doing so registers the same
// `uses()->in(__DIR__)` binding twice and Pest refuses the second, identical
// registration with "Test case can not be used ... already uses the test
// case".

// =============================================================================
// Plugin install — Auth Kit, then Warp. craft-pest-core's InstallsCraft plugin
// (which already booted Craft by this point in the Kernel sequence, see the
// class docblock above) installs Craft core and applies any pending project
// config, but never installs the plugin(s) under test.
//
// Auth Kit is Warp's hard `require` dependency (not a suggest-only
// integration): every passwordless flow reaches through `AuthKit::$plugin`
// (its Passwords registry, its Audit sink registry), so Auth Kit must be
// installed before Warp — a plugin whose Install.php or runtime code
// references another plugin's tables/services fails otherwise.
// =============================================================================

if (Craft::$app->getIsInstalled(true)) {
    $plugins = Craft::$app->getPlugins();

    foreach (['auth-kit', 'warp'] as $handle) {
        if (!$plugins->isPluginInstalled($handle)) {
            $plugins->installPlugin($handle);
        }
    }
}

// =============================================================================
// Timezone — Craft stores and compares every datetime attribute in UTC, but a
// fresh install's `system.timeZone` project-config default is
// `America/Los_Angeles` (see craft\migrations\Install), and
// Application::init() calls date_default_timezone_set() from that value
// during the boot InstallsCraft already performed above — clobbering
// anything set earlier in this file. Pinned back to UTC here, after boot, so
// a DateTime built with the process's ambient timezone that round-trips
// through a saved element attribute (stored via ->format('Y-m-d H:i:s'),
// reloaded assuming UTC) doesn't come back shifted — which would silently
// break any test that persists a DateTime and re-reads it in the same run
// (e.g. an expiry check).
// =============================================================================

date_default_timezone_set('UTC');
