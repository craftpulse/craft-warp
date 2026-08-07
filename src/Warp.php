<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\helpers\UrlHelper;
use craftpulse\authkit\AuthKit;
use craftpulse\warp\base\PluginTrait;
use craftpulse\warp\controllers\OverviewController;
use craftpulse\warp\controllers\SettingsController;
use craftpulse\warp\models\Settings;
use craftpulse\warp\services\ServicesTrait;

/**
 * Warp is a passwordless, front-end authentication product for Craft member
 * sites. It assembles Auth Kit's primitives — magic links, email one-time
 * codes, and passkeys — into a polished login, registration, and account
 * surface, with a control-panel section for settings and an overview.
 *
 * The plugin class is a thin orchestrator: event wiring and URL registration
 * live in [[PluginTrait]], service accessors in [[ServicesTrait]].
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class Warp extends Plugin
{
    // Traits
    // =========================================================================

    use PluginTrait;
    use ServicesTrait;

    // Static Properties
    // =========================================================================

    /**
     * @var Warp The plugin instance.
     *
     * @since 5.0.0
     */
    public static Warp $plugin;

    // Public Properties
    // =========================================================================

    /**
     * @var bool Whether the plugin has its own section in the control panel.
     *
     * @since 5.0.0
     */
    public bool $hasCpSection = true;

    /**
     * @var bool Whether the plugin has a settings page in the control panel.
     *
     * @since 5.0.0
     */
    public bool $hasCpSettings = true;

    /**
     * @var string The plugin's schema version.
     *
     * Craft compares this against the value recorded for Warp in the `plugins`
     * table to decide whether a site has a pending database update, using a
     * strictly-greater comparison. It must therefore be raised in any release
     * that ships a new dated migration, or the control panel never prompts for
     * the update and the migration only runs for someone who happens to run
     * `craft up` by hand. It is independent of the plugin's released version.
     *
     * @since 5.0.1
     */
    public string $schemaVersion = '5.0.3';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        Craft::setAlias('@craftpulse/warp', __DIR__);

        // Auth Kit is a library-shipped Yii module, not a Craft plugin: it
        // arrives with Composer, never appears in the installed-plugins list,
        // and nothing installs or enables it. Every consumer registers it from
        // its own init(), and the call is idempotent, so an install running
        // Warp alongside another consumer (Warden) is fine either way round:
        // the first caller creates the module, the rest resolve the same
        // instance. Registration happens here rather than from the deferred
        // onInit() wiring below so Auth Kit's services are reachable for the
        // whole of Warp's own boot.
        //
        // Registration is all Warp does to it. Warp writes NOTHING onto Auth
        // Kit's shared services: routes, lifetimes, throttles, and the
        // recent-auth window ride each issuance or check as per-call options,
        // so a co-installed consumer can never clobber Warp's configuration,
        // nor Warp theirs.
        AuthKit::register();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'craftpulse\\warp\\console\\controllers';
        }

        Craft::$app->onInit(function() {
            $this->_attachEventHandlers();
        });
    }

    /**
     * @inheritdoc
     * @return array<string, mixed>|null
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        if ($item === null) {
            return null;
        }

        $item['subnav'] = [];
        $user = Craft::$app->getUser();

        if ($user->getIdentity()?->can(OverviewController::PERMISSION_VIEW_OVERVIEW)) {
            $item['subnav']['overview'] = [
                'label' => Craft::t('warp', 'Overview'),
                'url' => 'warp',
            ];
        }

        if ($user->getIdentity()?->can(SettingsController::PERMISSION_MANAGE_SETTINGS)) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('warp', 'Settings'),
                'url' => 'warp/settings',
            ];
        }

        // A member of the CP with access to the plugin but none of its screens
        // gets no nav item at all — never a dead entry that leads to a 403.
        if ($item['subnav'] === []) {
            return null;
        }

        return $item;
    }

    /**
     * @inheritdoc
     *
     * Warp's settings live inside its own control-panel section (correct
     * breadcrumb, `Settings` subnav highlighted), so the global Settings entry
     * redirects there rather than rendering the bare settings fragment. The
     * `warp/settings` route arrives with the settings controller in a later
     * phase; the redirect target is fixed now so it resolves once that lands.
     */
    public function getSettingsResponse(): mixed
    {
        /** @var \craft\web\Response $response */
        $response = Craft::$app->getResponse();

        return $response->redirect(UrlHelper::cpUrl('warp/settings'));
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
}
