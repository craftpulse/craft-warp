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
    public const PERMISSION_MANAGE_SETTINGS = 'warp:manageSettings';

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
        return $this->renderTemplate('warp/settings/_index', [
            'title' => Craft::t('warp', 'Settings'),
            'settings' => Warp::$plugin->getSettings(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
            'crumbs' => [
                ['label' => Craft::t('warp', 'Warp'), 'url' => UrlHelper::cpUrl('warp')],
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
        $settings = array_merge($current->getAttributes(), is_array($submitted) ? $submitted : []);

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
}
