<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\services;

use Carbon\Carbon;
use Craft;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\User;
use craft\helpers\Db;
use craft\web\Request as WebRequest;
use craftpulse\authkit\AuthKit;
use craftpulse\authkit\services\Locations;
use craftpulse\warp\db\Table;
use craftpulse\warp\helpers\Ip;
use craftpulse\warp\models\Login;
use craftpulse\warp\models\Settings;
use craftpulse\warp\records\Login as LoginRecord;
use craftpulse\warp\Warp;
use Throwable;
use yii\base\Component;

/**
 * Logins owns Warp's append-only passwordless login log — the storage behind
 * the control-panel overview screen. It records a row per successful
 * passwordless sign-in, reads back the most recent rows, and prunes rows older
 * than [[PRUNE_MAX_AGE_DAYS]] on garbage collection.
 *
 * The log is intentionally minimal: which user, over which method, from which
 * (truncated) device and address, and when — the smallest audit surface the
 * overview renders. It is never an authentication authority, so a failed write
 * must never block a login; recording is best-effort.
 *
 * New-location awareness is not Warp's own any more. The rule that decides
 * whether a sign-in came from somewhere the member has never been, and the alert
 * that follows, live in [[\craftpulse\authkit\services\Locations]] so that two
 * security plugins on one install produce one email rather than two. Warp keeps
 * its own baseline — this log, not Auth Kit's shared history — by handing the
 * rule a query over `warp_logins`, so the `isNewLocation` column means exactly
 * what it always did: a place absent from Warp's own log.
 *
 * An instance of the service is available via `Warp::$plugin->getLogins()`.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class Logins extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var int How many days a login-log row is retained before garbage
     * collection prunes it. A fixed retention window, deliberately not a
     * setting — the overview is a recent-activity view, not a compliance
     * archive.
     *
     * @since 5.0.0
     */
    public const PRUNE_MAX_AGE_DAYS = 90;

    /**
     * @var string The system-message key for the new-location alert email. Warp
     * registers this message in `PluginTrait::_registerSystemMessages()` and
     * names it on every alert it asks Auth Kit to send, so an install that has
     * customized this copy keeps seeing its own words rather than Auth Kit's
     * generic equivalent. Declared here as the single source of truth.
     *
     * @since 5.0.0
     */
    public const MESSAGE_KEY_NEW_LOCATION = 'warp_new_location';

    /**
     * @var string The emitter handle recorded against every audit fact this
     * service produces through Auth Kit's neutral contract.
     *
     * @since 5.0.0
     */
    private const EMITTER = 'warp';

    /**
     * @var int The maximum stored length of a captured user-agent string.
     *
     * @since 5.0.0
     */
    private const USER_AGENT_MAX_LENGTH = 255;

    // Public Methods
    // =========================================================================

    /**
     * Returns the most recent login-log rows, newest first, as [[Login]]
     * models.
     *
     * @param int $limit the maximum number of rows to return
     * @return array<int, Login> the recent login records, newest first
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getRecent(int $limit = 50): array
    {
        $rows = (new Query())
            ->select(['id', 'userId', 'method', 'userAgent', 'ip', 'city', 'country', 'isNewLocation', 'dateCreated'])
            ->from(Table::LOGINS)
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->all();

        return array_map([$this, '_toModel'], $rows);
    }

    /**
     * Returns one page of login-log rows for the control-panel overview's
     * paginated table, joined to each user's email and username for display and
     * search.
     *
     * The result is the raw data the [[\craftpulse\warp\controllers\OverviewController]]
     * shapes into the VueAdminTable payload — the query (join, search, sort,
     * pagination) is owned here; the presentation is owned there. Search matches
     * the user's email or username; sorting is limited to the whitelisted columns
     * the table exposes, defaulting to newest first.
     *
     * @param int $page the 1-based page number
     * @param int $limit the page size
     * @param string|null $search a term to match against user email or username
     * @param string|null $sortField the column to sort by — `email`, `method`, or `when`
     * @param int $sortDir the sort direction, a `SORT_*` constant
     * @return array{total: int, rows: array<int, array<string, mixed>>}
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getTableData(int $page, int $limit, ?string $search = null, ?string $sortField = null, int $sortDir = SORT_DESC): array
    {
        $query = (new Query())
            ->from(['l' => Table::LOGINS])
            ->leftJoin(['u' => CraftTable::USERS], '[[u.id]] = [[l.userId]]');

        if ($search !== null && trim($search) !== '') {
            $term = trim($search);
            $query->andWhere(['or',
                ['like', 'u.email', $term],
                ['like', 'u.username', $term],
            ]);
        }

        $total = (int)(clone $query)->count('[[l.id]]');

        $sortColumn = match ($sortField) {
            'email' => 'u.email',
            'method' => 'l.method',
            default => 'l.dateCreated',
        };

        $rows = $query
            ->select([
                'id' => 'l.id',
                'userId' => 'l.userId',
                'method' => 'l.method',
                'userAgent' => 'l.userAgent',
                'ip' => 'l.ip',
                'city' => 'l.city',
                'country' => 'l.country',
                'dateCreated' => 'l.dateCreated',
                'email' => 'u.email',
                'username' => 'u.username',
            ])
            ->orderBy([$sortColumn => $sortDir, 'l.id' => SORT_DESC])
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->all();

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * Prunes login-log rows older than [[PRUNE_MAX_AGE_DAYS]].
     *
     * Wired to `craft\services\Gc::EVENT_RUN` so it rides Craft's own garbage
     * collection schedule rather than firing per request.
     *
     * @return int the number of rows deleted
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function prune(): int
    {
        $cutoff = Db::prepareDateForDb(Carbon::now()->subDays(self::PRUNE_MAX_AGE_DAYS));

        return Db::delete(Table::LOGINS, ['<', 'dateCreated', $cutoff]);
    }

    /**
     * Records a successful passwordless sign-in.
     *
     * Best-effort: a failed insert is logged and swallowed so a bookkeeping
     * problem can never block a login the user has already completed. The user-agent is
     * truncated to [[USER_AGENT_MAX_LENGTH]] and the IP to the column width at
     * the database boundary.
     *
     * @param User $user the user who signed in
     * @param string $method the passwordless method used — a [[Login]] `METHOD_*` constant
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function record(User $user, string $method): void
    {
        $request = Craft::$app->getRequest();
        $userAgent = null;
        $ip = null;

        if ($request instanceof WebRequest) {
            $userAgent = $request->getUserAgent();
            $ip = $request->getUserIP();
        }

        // A bookkeeping failure must never block a login already completed, so
        // the insert is wrapped: any failure is logged and swallowed.
        try {
            $userId = (int)$user->id;
            $locations = $this->_locations();
            $location = Warp::$plugin->getGeo()->lookup($ip);
            // The baseline is Warp's own log, read as it stood before this row
            // lands, so a member's very first sign-in is never "new".
            $isNewLocation = $locations->isNew($userId, $location['country'], $location['city'], $this->_history());

            $record = new LoginRecord();
            $record->userId = $userId;
            $record->method = $method;
            $record->userAgent = $userAgent !== null ? mb_substr($userAgent, 0, self::USER_AGENT_MAX_LENGTH) : null;
            // The geo lookup above ran on the full address; only the stored
            // copy is coarsened when anonymization is enabled.
            $record->ip = $this->_settings()->anonymizeIp ? Ip::anonymize($ip) : $ip;
            $record->city = $location['city'];
            $record->country = $location['country'];
            $record->isNewLocation = $isNewLocation;
            $record->save(false);

            if ($isNewLocation) {
                // Auth Kit claims the alert, so a second security plugin that
                // spotted the same trip does not send a second email. Warp's
                // own setting decides only whether the member hears about it;
                // the audit fact is recorded either way.
                $locations->alert($user, $location['country'], $location['city'], [
                    'emitter' => self::EMITTER,
                    'messageKey' => self::MESSAGE_KEY_NEW_LOCATION,
                    'notify' => $this->_settings()->notifyOnNewLocation,
                ]);
            }
        } catch (Throwable $e) {
            Craft::warning("Could not record the login for user {$user->id}: {$e->getMessage()}", __METHOD__);
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the query the new-location rule reads Warp's baseline from — the
     * login log itself, so `isNewLocation` keeps meaning "a place absent from
     * Warp's own log" rather than "absent from the shared history".
     *
     * @return Query<int, array<string, mixed>>
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _history(): Query
    {
        return (new Query())->from(Table::LOGINS);
    }

    /**
     * Returns Auth Kit's locations service, which owns the new-location rule
     * and the one alert that goes out per place.
     *
     * @return Locations
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _locations(): Locations
    {
        return AuthKit::getInstance()->getLocations();
    }

    /**
     * Returns Warp's settings model.
     *
     * @return Settings
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _settings(): Settings
    {
        $settings = Warp::$plugin->getSettings();
        assert($settings instanceof Settings);

        return $settings;
    }

    /**
     * Maps a raw login-log row to a [[Login]] model.
     *
     * @param array<string, mixed> $row the selected row
     * @return Login
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _toModel(array $row): Login
    {
        $dateCreated = $row['dateCreated'] ?? null;

        return new Login([
            'id' => (int)$row['id'],
            'userId' => (int)$row['userId'],
            'method' => (string)$row['method'],
            'userAgent' => $row['userAgent'] !== null ? (string)$row['userAgent'] : null,
            'ip' => $row['ip'] !== null ? (string)$row['ip'] : null,
            'city' => $row['city'] !== null ? (string)$row['city'] : null,
            'country' => $row['country'] !== null ? (string)$row['country'] : null,
            'isNewLocation' => (bool)$row['isNewLocation'],
            // The column holds a naive UTC string while the process timezone is
            // the system timezone, so the zone must be named at parse time.
            'dateCreated' => is_string($dateCreated) ? Carbon::parse($dateCreated, 'UTC') : null,
        ]);
    }
}
