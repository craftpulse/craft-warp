<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\controllers;

use Craft;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\UrlManager;
use craftpulse\warp\models\Settings;
use craftpulse\warp\Warp;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * SettingsController renders Warp's settings inside the plugin's own
 * control-panel section, so they keep the Warp breadcrumb and the `Settings`
 * subnav item rather than bouncing out to the global plugin-settings screen.
 *
 * Access is gated by [[PERMISSION_MANAGE_SETTINGS]]: the permission decides who
 * may be on the screen, while `allowAdminChanges` decides only whether writes
 * are possible. With admin changes disabled (the standard production posture)
 * the screen renders read-only and the save action fails closed.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class SettingsController extends Controller
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The permission that grants access to Warp's settings screen.
     *
     * @since 5.0.0
     */
    public const PERMISSION_MANAGE_SETTINGS = 'warp:manage-settings';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws ForbiddenHttpException if the user lacks the manage-settings permission
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(self::PERMISSION_MANAGE_SETTINGS);

        return true;
    }

    /**
     * Renders the settings screen within the Warp section.
     *
     * @return Response
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function actionEdit(): Response
    {
        $pluginName = Warp::$plugin->name;
        $title = Craft::t('warp', 'Settings');

        return $this->renderTemplate('warp/settings/_index', [
            'pluginName' => $pluginName,
            'title' => $title,
            'docTitle' => "{$pluginName} - {$title}",
            'settings' => Warp::$plugin->getSettings(),
            'defaultGroupName' => $this->_defaultUserGroupName(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
            'crumbs' => [
                ['label' => $pluginName, 'url' => UrlHelper::cpUrl('warp')],
            ],
        ]);
    }

    /**
     * Persists the posted settings to project config.
     *
     * The submitted keys are merged onto the current settings before saving, so
     * a partial post (only the fields on screen) never drops the settings it
     * did not carry.
     *
     * @return Response|null `null` re-renders the edit screen with validation errors
     * @throws ForbiddenHttpException if project config changes are disallowed
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException('Administrative changes are disallowed in this environment.');
        }

        $plugin = Warp::$plugin;
        $current = $plugin->getSettings();
        assert($current instanceof Settings);

        $submitted = $this->request->getBodyParam('settings', []);
        $submitted = is_array($submitted) ? $this->_mapLoginMethods($submitted) : [];
        $settings = array_merge($current->getAttributes(), $submitted);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            $this->setFailFlash(Craft::t('warp', 'Couldn’t save settings.'));

            /** @var UrlManager $urlManager */
            $urlManager = Craft::$app->getUrlManager();
            $urlManager->setRouteParams([
                'settings' => $current,
            ]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('warp', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the name of the user group Craft is configured to put new users
     * in, or `null` when no usable group is configured.
     *
     * The resolution mirrors `craft\services\Users::getDefaultUserGroups()`
     * (read the `users.defaultGroup` UID out of project config, then look the
     * group up by UID) rather than calling that method: it requires a User
     * instance and fires `EVENT_DEFINE_DEFAULT_USER_GROUPS`, so calling it here
     * would hand a plugin a fabricated user and let it skew a piece of settings
     * copy. Mirroring the lookup instead means this agrees with core on every
     * edition by construction, including Team's virtual group, since
     * `UserGroups::getGroupByUid()` is a plain query and is not edition-gated.
     *
     * Because that event is deliberately not fired, the copy this feeds
     * describes the CONFIGURED default rather than promising what any given
     * registrant will actually receive.
     *
     * The name is returned HTML-encoded: its sole consumer interpolates it into
     * the raw-HTML `instructions` string of the registration-group field. Do not
     * reuse this value anywhere that escapes again or expects a raw name.
     *
     * @return ?string the HTML-encoded group name, or `null` when project config
     * names no default group, names one that no longer exists, or names one
     * carrying no name to print
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _defaultUserGroupName(): ?string
    {
        $uid = Craft::$app->getProjectConfig()->get('users.defaultGroup');

        if (!is_string($uid) || $uid === '') {
            return null;
        }

        $name = Craft::$app->getUserGroups()->getGroupByUid($uid)?->name;

        if ($name === null || $name === '') {
            return null;
        }

        return Html::encode($name);
    }

    /**
     * Folds the two independent login-method lightswitches back into the
     * `loginMethods` string array the settings model persists.
     *
     * The screen presents the channels as two switches for a clearer UX, but the
     * model keeps its `string[]` shape for backward compatibility (the
     * project-config schema is unchanged). The two helper keys are consumed here
     * and dropped so only real settings reach the model. An empty result is left
     * as-is, so the model's at-least-one-method validation fails closed rather
     * than the controller silently correcting it.
     *
     * @param array<string, mixed> $submitted the posted settings
     * @return array<string, mixed> the settings with `loginMethods` reconstructed
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _mapLoginMethods(array $submitted): array
    {
        // A post that carries neither switch (a partial, non-form submission)
        // leaves loginMethods untouched, so it merges from the current settings.
        // The rendered form always posts both switches (a lightswitch emits its
        // key even when off), so a genuine save always reaches the mapping below.
        if (!array_key_exists('loginMethodMagicLink', $submitted) && !array_key_exists('loginMethodOtp', $submitted)) {
            return $submitted;
        }

        $methods = [];

        if (!empty($submitted['loginMethodMagicLink'])) {
            $methods[] = Settings::CHANNEL_MAGIC_LINK;
        }

        if (!empty($submitted['loginMethodOtp'])) {
            $methods[] = Settings::CHANNEL_OTP;
        }

        unset($submitted['loginMethodMagicLink'], $submitted['loginMethodOtp']);
        $submitted['loginMethods'] = $methods;

        return $submitted;
    }
}
