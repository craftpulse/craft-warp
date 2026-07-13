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
use craftpulse\warp\db\Table;
use craftpulse\warp\models\Login;
use craftpulse\warp\records\Login as LoginRecord;
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
            ->select(['id', 'userId', 'method', 'userAgent', 'ip', 'dateCreated'])
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
            $record = new LoginRecord();
            $record->userId = (int)$user->id;
            $record->method = $method;
            $record->userAgent = $userAgent !== null ? mb_substr($userAgent, 0, self::USER_AGENT_MAX_LENGTH) : null;
            $record->ip = $ip;
            $record->save(false);
        } catch (Throwable $e) {
            Craft::warning("Could not record the login for user {$user->id}: {$e->getMessage()}", __METHOD__);
        }
    }

    // Private Methods
    // =========================================================================

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
            'dateCreated' => is_string($dateCreated) ? Carbon::parse($dateCreated) : null,
        ]);
    }
}
