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
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\User;
use craft\web\Controller;
use craftpulse\warp\models\Settings;
use craftpulse\warp\Warp;
use yii\web\Response;

/**
 * OverviewController renders Warp's control-panel landing screen: a read-only
 * summary of recent passwordless sign-ins, passkey adoption, and the current
 * registration and login-method posture.
 *
 * Access requires the [[PERMISSION_VIEW_OVERVIEW]] permission on top of core's
 * plugin-section gate, so a member of the Warp section can be granted or denied
 * the overview independently.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class OverviewController extends Controller
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The permission required to view the overview screen. Declared
     * here as the single source of truth, referenced by both the registration
     * in `PluginTrait::_registerUserPermissions()` and the gate below.
     *
     * @since 5.0.0
     */
    public const PERMISSION_VIEW_OVERVIEW = 'warp:viewOverview';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws \yii\web\ForbiddenHttpException if the user lacks the overview permission
     * @throws \yii\web\BadRequestHttpException if the request is not a control-panel request
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(self::PERMISSION_VIEW_OVERVIEW);

        return true;
    }

    /**
     * Renders the overview screen.
     *
     * @return Response
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function actionIndex(): Response
    {
        $settings = Warp::$plugin->getSettings();
        assert($settings instanceof Settings);

        $recentLogins = Warp::$plugin->getLogins()->getRecent();

        return $this->renderTemplate('warp/overview/_index', [
            'title' => Craft::t('warp', 'Overview'),
            'recentLogins' => $recentLogins,
            'usersById' => $this->_usersById($recentLogins),
            'passkeyUserCount' => $this->_passkeyUserCount(),
            'registrationEnabled' => Warp::$plugin->getRegistration()->isEnabled(),
            'enableRegistrationSetting' => $settings->enableRegistration,
            'allowPublicRegistration' => (bool)Craft::$app->getProjectConfig()->get('users.allowPublicRegistration'),
            'loginMethods' => $settings->loginMethods,
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the number of distinct users holding at least one WebAuthn
     * credential — the passkey-adoption headline, read straight from core's
     * credential table.
     *
     * @return int
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _passkeyUserCount(): int
    {
        return (int)(new Query())
            ->from(CraftTable::WEBAUTHN)
            ->select('userId')
            ->distinct()
            ->count();
    }

    /**
     * Resolves the users behind a set of login rows into an id-keyed map, so
     * the template links each sign-in to its user without an per-row query.
     *
     * @param array<int, \craftpulse\warp\models\Login> $logins the login rows to resolve users for
     * @return array<int, User> the users, keyed by id
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _usersById(array $logins): array
    {
        $userIds = array_values(array_unique(array_map(
            static fn($login): int => (int)$login->userId,
            $logins,
        )));

        if ($userIds === []) {
            return [];
        }

        $users = User::find()
            ->id($userIds)
            ->status(null)
            ->indexBy('id')
            ->all();

        return $users;
    }
}
