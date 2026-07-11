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
 * Access requires an admin. The edit screen stays viewable when
 * `allowAdminChanges` is disabled (the standard production posture); the save
 * action re-checks it and fails closed.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class SettingsController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws ForbiddenHttpException if the user is not an admin
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requireAdmin(false);

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
