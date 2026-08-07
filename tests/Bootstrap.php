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
 * application itself against `warp_test` — this file only wires autoloading,
 * asserts the database really is the test one, installs the plugin(s) under
 * test, and pins the process timezone.
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
// Fail closed on the database. Craft is already booted by this point (see the
// class docblock), so the question worth asking is not what the environment
// says but what the connection actually landed on: an invocation from the
// wrong working directory leaves `CRAFT_DB_DATABASE` reading DDEV's ambient
// `db`, and comparing the environment against itself would agree with that
// perfectly while every fixture this suite writes commits to the playground's
// live schema.
//
// The name is a literal rather than a read of the pin it is checking, for the
// same reason. Keep it in step with `CRAFT_DB_DATABASE` in
// `phpunit.xml.dist`; drift aborts the run instead of redirecting it.
//
// Raised rather than `exit(1)`: Pest installs a shutdown handler that ends the
// process with its own status, so an `exit(1)` here prints the refusal and
// still reports success to the shell — which is worse than no guard at all on
// CI. An exception out of the bootstrap is reported by PHPUnit as "Error in
// bootstrap script" and exits non-zero.
// =============================================================================

const WARP_TEST_DATABASE = 'warp_test';

$connectedDatabase = (string)Craft::$app->getDb()->createCommand('SELECT DATABASE()')->queryScalar();

if ($connectedDatabase !== WARP_TEST_DATABASE) {
    throw new RuntimeException(sprintf(
        "Warp test bootstrap refused to run: Craft connected to '%s', not the test database '%s'. "
        . 'Check the CRAFT_DB_DATABASE entry in phpunit.xml.dist, and run the suite from Warp’s own root.',
        $connectedDatabase,
        WARP_TEST_DATABASE,
    ));
}

// =============================================================================
// Plugin install — Warp alone. craft-pest-core's InstallsCraft plugin (which
// already booted Craft by this point in the Kernel sequence, see the class
// docblock above) installs Craft core and applies any pending project config,
// but never installs the plugin(s) under test.
//
// Auth Kit is not installed here, because as of 1.7.0 it is not installable:
// it is a library-shipped Yii module that Warp registers from its own `init()`
// and whose schema Warp's `Install` migration brings up on the
// `module:auth-kit` track. Installing Warp is therefore the whole of the
// setup — the token store the passwordless flows reach through
// `AuthKit::$plugin` exists by the time this returns.
// =============================================================================

if (Craft::$app->getIsInstalled(true)) {
    $plugins = Craft::$app->getPlugins();

    if (!$plugins->isPluginInstalled('warp')) {
        $plugins->installPlugin('warp');
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
