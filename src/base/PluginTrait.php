<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\base;

use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use craftpulse\authkit\AuthKit;
use craftpulse\warp\models\Settings;
use yii\base\Event;

/**
 * PluginTrait owns Warp's event listeners, URL rule registration, and plugin
 * lifecycle wiring, keeping the main plugin class a thin orchestrator.
 *
 * The registrar methods are deliberately empty in the scaffold — each is filled
 * as its feature phase lands: site URL rules and the Auth Kit wiring with the
 * passwordless flows, CP URL rules with the overview and settings screens, and
 * the Twig variable with the front-end surface.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
trait PluginTrait
{
    // Private Methods
    // =========================================================================

    /**
     * Attaches Warp's event handlers.
     *
     * Called from `Warp::init()` once the application has fully initialized.
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _attachEventHandlers(): void
    {
        $this->_registerSiteUrlRules();
        $this->_registerCpUrlRules();
        $this->_registerVariable();
        $this->_configureAuthKit();
    }

    /**
     * Pushes Warp's settings into Auth Kit's services.
     *
     * Auth Kit owns the token store, one-time-code issuance, and recent-auth
     * gate but is headless — it exposes its tunables as service properties.
     * Warp is the product that configures them, applying its settings once here
     * rather than scattering configuration across controllers.
     *
     * Skipped gracefully if Auth Kit is somehow not installed.
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _configureAuthKit(): void
    {
        $authKit = AuthKit::getInstance();

        if ($authKit === null) {
            return;
        }

        $settings = $this->getSettings();
        assert($settings instanceof Settings);

        $tokens = $authKit->getTokens();
        $tokens->magicLinkRoute = 'warp/auth/verify-link';
        $tokens->tokenTtl = $settings->tokenTtl;
        $tokens->otpDigits = $settings->otpDigits;
        $tokens->otpMaxAttempts = $settings->otpMaxAttempts;
        $tokens->perEmailLimit = $settings->perEmailLimit;
        $tokens->perEmailWindow = $settings->perEmailWindow;

        $authKit->getPasskeys()->recentAuthDuration = $settings->recentAuthDuration;
    }

    /**
     * Registers Warp's control-panel URL rules — the overview and settings
     * screens.
     *
     * Filled in Phase 6 (CP settings + overview).
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _registerCpUrlRules(): void
    {
    }

    /**
     * Registers Warp's site URL rules — the front-end passwordless endpoints.
     *
     * Extended in later phases (registration, passkeys, sessions).
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _registerSiteUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function(RegisterUrlRulesEvent $event): void {
                $event->rules['warp/auth/request'] = 'warp/auth/request';
                $event->rules['warp/auth/verify-link'] = 'warp/auth/verify-link';
                $event->rules['warp/auth/verify-code'] = 'warp/auth/verify-code';
            },
        );
    }

    /**
     * Registers the `craft.warp` Twig variable — the single front-end handle
     * for the passwordless surface.
     *
     * Filled in Phase 4 (front-end passkeys + variable).
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _registerVariable(): void
    {
    }
}
