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

use Craft;
use craft\elements\User;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\User as WebUser;
use craftpulse\authkit\AuthKit;
use craftpulse\warp\controllers\OverviewController;
use craftpulse\warp\models\Login;
use craftpulse\warp\models\Settings;
use craftpulse\warp\variables\WarpVariable;
use yii\base\Event;
use yii\web\UserEvent;

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
        $this->_registerUserPermissions();
        $this->_registerLoginLog();
        $this->_registerSessionRegistry();
        $this->_registerVariable();
        $this->_configureAuthKit();
    }

    /**
     * Wires Warp's passwordless login log: the passkey capture listener and the
     * garbage-collection prune.
     *
     * Passkey logins run through core's own `users/login-with-passkey` endpoint,
     * never Warp's [[\craftpulse\warp\services\Passwordless]] service, so they
     * are recorded here off `EVENT_AFTER_LOGIN`. The route guard keeps this from
     * double-recording Warp's own email flows, whose session login fires the
     * same event but resolves to a `warp/auth/*` route and is recorded by
     * `Passwordless::loginUser()` instead.
     *
     * The device-registry capture rides the same event but is deliberately
     * unguarded: every front-end login — email flow or passkey — should register
     * its device, and by this point core has already generated the session token
     * the capture hashes.
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _registerLoginLog(): void
    {
        Event::on(
            WebUser::class,
            WebUser::EVENT_AFTER_LOGIN,
            function(UserEvent $event): void {
                $this->getSessions()->record();

                if (Craft::$app->requestedRoute !== 'users/login-with-passkey') {
                    return;
                }

                $identity = $event->identity;

                if ($identity instanceof User) {
                    $this->getLogins()->record($identity, Login::METHOD_PASSKEY);
                }
            },
        );

        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            function(): void {
                $this->getLogins()->prune();
            },
        );
    }

    /**
     * Wires the device registry's pruning: forgetting a session's registry row
     * when the user logs out, and clearing orphaned rows on garbage collection.
     *
     * The registry capture itself rides the login log's `EVENT_AFTER_LOGIN`
     * listener (see [[_registerLoginLog()]]) rather than a second handler, so a
     * login is recorded once. Logout is caught here on `EVENT_BEFORE_LOGOUT`,
     * where the session token is still readable, so the exact row can be removed;
     * garbage collection sweeps up rows whose core session has since vanished.
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _registerSessionRegistry(): void
    {
        Event::on(
            WebUser::class,
            WebUser::EVENT_BEFORE_LOGOUT,
            function(UserEvent $event): void {
                if (!$event->identity instanceof User) {
                    return;
                }

                // BEFORE_LOGOUT only fires on web requests, so the session is a
                // web user; the token is still readable here, before core clears
                // it, so the exact registry row can be forgotten.
                $userSession = Craft::$app->getUser();
                assert($userSession instanceof WebUser);
                $token = $userSession->getToken();

                if ($token !== null) {
                    $this->getSessions()->pruneToken($token);
                }
            },
        );

        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            function(): void {
                $this->getSessions()->pruneOrphans();
            },
        );
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
        $tokens->registrationRoute = 'warp/auth/verify-registration';
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
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event): void {
                $event->rules['warp'] = 'warp/overview/index';
                $event->rules['warp/settings'] = 'warp/settings/edit';
            },
        );
    }

    /**
     * Registers Warp's user permissions under a dedicated "Warp" heading.
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _registerUserPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => Craft::t('warp', 'Warp'),
                    'permissions' => [
                        OverviewController::PERMISSION_VIEW_OVERVIEW => [
                            'label' => Craft::t('warp', 'View the overview'),
                        ],
                    ],
                ];
            },
        );
    }

    /**
     * Registers Warp's site URL rules — the front-end passwordless endpoints.
     *
     * Extended in later phases (sessions).
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
                $event->rules['warp/auth/verify-registration'] = 'warp/auth/verify-registration';
                $event->rules['warp/passkeys/creation-options'] = 'warp/passkeys/creation-options';
                $event->rules['warp/passkeys/verify-creation'] = 'warp/passkeys/verify-creation';
                $event->rules['warp/passkeys/delete'] = 'warp/passkeys/delete';
            },
        );
    }

    /**
     * Registers the `craft.warp` Twig variable — the single front-end handle
     * for the passwordless surface.
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _registerVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event): void {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('warp', WarpVariable::class);
            },
        );
    }
}
