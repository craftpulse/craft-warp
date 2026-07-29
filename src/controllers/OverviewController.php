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
use craft\helpers\AdminTable;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craftpulse\warp\helpers\Device;
use craftpulse\warp\models\Login;
use craftpulse\warp\models\Settings;
use craftpulse\warp\Warp;
use yii\web\Response;

/**
 * OverviewController renders Warp's control-panel landing screen: a native
 * stat-tile summary of the current posture (registration, enabled login
 * methods, passkey adoption) above a paginated, searchable table of recent
 * passwordless sign-ins.
 *
 * The table is a VueAdminTable driven by [[actionTableData()]] in API mode, so
 * the list is bounded and filterable rather than an unbounded dump. Access
 * requires the [[PERMISSION_VIEW_OVERVIEW]] permission on top of core's
 * plugin-section gate, so a member of the Warp section can be granted or denied
 * the overview independently — and the data endpoint inherits the same gate
 * through [[beforeAction()]].
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
    public const PERMISSION_VIEW_OVERVIEW = 'warp:view-overview';

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
     * Renders the overview screen — the posture stat tiles and the mount point
     * for the recent-sign-ins table.
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

        return $this->renderTemplate('warp/overview/_index', [
            'title' => Craft::t('warp', 'Overview'),
            'passkeyUserCount' => $this->_passkeyUserCount(),
            'registrationEnabled' => Warp::$plugin->getRegistration()->isEnabled(),
            'enableRegistrationSetting' => $settings->enableRegistration,
            'allowPublicRegistration' => (bool)Craft::$app->getProjectConfig()->get('users.allowPublicRegistration'),
            'loginMethods' => $settings->loginMethods,
        ]);
    }

    /**
     * Returns one page of recent sign-ins as a VueAdminTable payload — the
     * pagination block plus the formatted rows.
     *
     * The query, search, and sort live in [[\craftpulse\warp\services\Logins::getTableData()]];
     * this action reads the table's request params, whitelists the sort column,
     * and shapes the rows for display. It inherits the overview permission gate
     * from [[beforeAction()]].
     *
     * @return Response
     * @throws \yii\web\BadRequestHttpException if the request does not accept JSON
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function actionTableData(): Response
    {
        $this->requireAcceptsJson();

        $page = max(1, (int)$this->request->getParam('page', 1));
        $limit = max(1, (int)$this->request->getParam('per_page', 20));
        $search = $this->request->getParam('search');
        $sortField = match ($this->request->getParam('sort.0.field')) {
            'email' => 'email',
            'method' => 'method',
            default => 'when',
        };
        $sortDir = $this->request->getParam('sort.0.direction') === 'asc' ? SORT_ASC : SORT_DESC;

        $data = Warp::$plugin->getLogins()->getTableData(
            $page,
            $limit,
            is_string($search) ? $search : null,
            $sortField,
            $sortDir,
        );

        return $this->asJson([
            'pagination' => AdminTable::paginationLinks($page, $data['total'], $limit),
            'data' => $this->_formatRows($data['rows']),
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Formats raw login-log rows into VueAdminTable data rows.
     *
     * @param array<int, array<string, mixed>> $rows the raw rows from the service
     * @return array<int, array<string, mixed>> the display-ready rows
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _formatRows(array $rows): array
    {
        $formatter = Craft::$app->getFormatter();

        return array_map(function(array $row) use ($formatter): array {
            $userId = (int)$row['userId'];
            $email = $row['email'] !== null ? (string)$row['email'] : null;
            $created = $row['dateCreated'] ?? null;
            $userAgent = $row['userAgent'] !== null ? (string)$row['userAgent'] : null;

            return [
                'id' => (int)$row['id'],
                'title' => $email ?? Craft::t('warp', 'Deleted user'),
                'url' => $email !== null ? UrlHelper::cpUrl("users/$userId") : null,
                'method' => $this->_methodLabel((string)$row['method']),
                'device' => Device::label($userAgent),
                'ip' => $row['ip'] !== null ? (string)$row['ip'] : '-',
                'location' => $this->_locationLabel(
                    isset($row['city']) && $row['city'] !== null ? (string)$row['city'] : null,
                    isset($row['country']) && $row['country'] !== null ? (string)$row['country'] : null,
                ),
                'when' => is_string($created) ? $formatter->asDatetime($created, 'short') : '-',
            ];
        }, $rows);
    }

    /**
     * Composes a human-friendly location label from a city and country, or
     * `null` when neither is known (no geo database, or a login recorded
     * before geo enrichment landed) — the table renders the null as a muted
     * "Location unknown" badge rather than a bare placeholder string.
     *
     * @param string|null $city the resolved city, or null
     * @param string|null $country the resolved country, or null
     * @return string|null
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _locationLabel(?string $city, ?string $country): ?string
    {
        return match (true) {
            $city !== null && $country !== null => "$city, $country",
            $country !== null => $country,
            $city !== null => $city,
            default => null,
        };
    }

    /**
     * Returns the display label for a login method.
     *
     * @param string $method the stored method — a [[Login]] `METHOD_*` value
     * @return string
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _methodLabel(string $method): string
    {
        return match ($method) {
            Login::METHOD_MAGIC_LINK => Craft::t('warp', 'Magic link'),
            Login::METHOD_OTP => Craft::t('warp', 'One-time code'),
            Login::METHOD_PASSKEY => Craft::t('warp', 'Passkey'),
            Login::METHOD_REGISTER => Craft::t('warp', 'Registration'),
            default => $method,
        };
    }

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
}
