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
use craftpulse\authkit\audit\AuthEvent;
use craftpulse\authkit\AuthKit;
use craftpulse\warp\db\Table;
use craftpulse\warp\helpers\Device;
use craftpulse\warp\helpers\Ip;
use craftpulse\warp\models\SessionInfo;
use craftpulse\warp\models\Settings;
use craftpulse\warp\records\Session as SessionRecord;
use craftpulse\warp\Warp;
use Throwable;
use yii\base\Component;

/**
 * Sessions owns Warp's device registry and the front-end session-management
 * screen it powers. Core's `{{%sessions}}` table stores only a token and its
 * timestamps — no device information — so Warp keeps a parallel registry that
 * pins the sha256 hash of each Craft auth-session token to the device that
 * produced it. The two are joined at read time by re-hashing each live core
 * token.
 *
 * Deleting a `{{%sessions}}` row is the authoritative server-side kill: Craft
 * validates its session token against that table on every authenticated request,
 * so a browser whose row is gone is a guest on its next request. Warp stores
 * only the hash, so revocation re-derives the match by hashing the user's live
 * tokens — a registry leak yields no usable token. This mirrors Warden's
 * `Sessions::_deleteCraftSession()` pattern.
 *
 * Ownership is always scoped in the query, never trusted from the posted uid: a
 * user can only ever reach their own rows. Recording is best-effort — a
 * bookkeeping failure must never block a login already completed.
 *
 * An instance is available via `Warp::$plugin->getSessions()`.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class Sessions extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var int The maximum stored length of a captured user-agent string.
     *
     * @since 5.0.0
     */
    private const USER_AGENT_MAX_LENGTH = 255;

    // Public Methods
    // =========================================================================

    /**
     * Returns the current user's active sessions as [[SessionInfo]] models, the
     * current session first and the rest by most recently seen.
     *
     * The list is core's `{{%sessions}}` rows for the user, each joined to its
     * registry row (when one exists) for the device label and IP. A core session
     * with no registry row — one created before Warp was installed, or by a path
     * Warp does not capture — still appears, labelled "Unknown device" with a
     * null uid, so nothing is hidden from the person managing their account.
     *
     * @param User $user the user whose sessions to list
     * @return array<int, SessionInfo> the user's active sessions
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getSessionsForUser(User $user): array
    {
        $userId = (int)$user->id;
        $currentHash = $this->_currentTokenHash();

        $coreRows = (new Query())
            ->select(['token', 'dateUpdated'])
            ->from(CraftTable::SESSIONS)
            ->where(['userId' => $userId])
            ->all();

        /** @var array<string, array<string, mixed>> $registry */
        $registry = (new Query())
            ->select(['uid', 'tokenHash', 'userAgent', 'ip', 'city'])
            ->from(Table::SESSIONS)
            ->where(['userId' => $userId])
            ->indexBy('tokenHash')
            ->all();

        $sessions = array_map(
            fn(array $row): SessionInfo => $this->_toSessionInfo($row, $registry, $currentHash),
            $coreRows,
        );

        usort($sessions, static function(SessionInfo $a, SessionInfo $b): int {
            if ($a->isCurrent !== $b->isCurrent) {
                return $a->isCurrent ? -1 : 1;
            }

            return ($b->lastSeen?->getTimestamp() ?? 0) <=> ($a->lastSeen?->getTimestamp() ?? 0);
        });

        return $sessions;
    }

    /**
     * Prunes orphaned registry rows — rows whose core `{{%sessions}}` row no
     * longer exists.
     *
     * Wired to `craft\services\Gc::EVENT_RUN`, so it rides Craft's own garbage
     * collection: core purges stale `{{%sessions}}` rows first, then this clears
     * the registry rows left pointing at nothing. The match cannot be expressed
     * in SQL (core stores the raw token, Warp the hash), so live tokens are
     * hashed into a set and non-matching registry rows are dropped — acceptable
     * for a background pass over Warp's small front-end registry.
     *
     * @return int the number of registry rows deleted
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function pruneOrphans(): int
    {
        $liveHashes = [];

        foreach ((new Query())->select(['token'])->from(CraftTable::SESSIONS)->column() as $token) {
            $liveHashes[hash('sha256', (string)$token)] = true;
        }

        $deleted = 0;

        $rows = (new Query())
            ->select(['id', 'tokenHash'])
            ->from(Table::SESSIONS)
            ->all();

        foreach ($rows as $row) {
            if (!isset($liveHashes[$row['tokenHash']])) {
                $deleted += Db::delete(Table::SESSIONS, ['id' => $row['id']]);
            }
        }

        return $deleted;
    }

    /**
     * Forgets the registry row for a Craft session token — called on logout,
     * where core deletes its own `{{%sessions}}` row and the registry row would
     * otherwise be left orphaned until garbage collection.
     *
     * @param string $token Craft's auth-session token
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function pruneToken(string $token): void
    {
        Db::delete(Table::SESSIONS, ['tokenHash' => hash('sha256', $token)]);
    }

    /**
     * Records the current request's session against the device that made it —
     * the sha256 hash of Craft's freshly issued auth-session token, plus a
     * truncated user-agent and IP for display.
     *
     * Called from `WebUser::EVENT_AFTER_LOGIN`, by which point core has already
     * generated the token and inserted the `{{%sessions}}` row. Best-effort and
     * null-guarded: no token (a console or session-less context) or an already
     * recorded hash is a silent no-op, and a failed registry read or write is
     * logged and swallowed so it never blocks a login.
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function record(): void
    {
        $userSession = $this->_userSession();
        $token = $userSession->getToken();

        if ($token === null) {
            return;
        }

        $user = $userSession->getIdentity();

        if (!$user instanceof User) {
            return;
        }

        $tokenHash = hash('sha256', $token);
        $request = Craft::$app->getRequest();
        $userAgent = null;
        $ip = null;

        if ($request instanceof WebRequest) {
            $userAgent = $request->getUserAgent();
            $ip = $request->getUserIP();
        }

        // Bookkeeping must never block a login already completed, so the registry
        // read and write are wrapped: any failure is logged and swallowed.
        try {
            if (SessionRecord::find()->where(['tokenHash' => $tokenHash])->exists()) {
                return;
            }

            $location = Warp::$plugin->getGeo()->lookup($ip);

            $record = new SessionRecord();
            $record->userId = (int)$user->id;
            $record->tokenHash = $tokenHash;
            $record->userAgent = $userAgent !== null ? mb_substr($userAgent, 0, self::USER_AGENT_MAX_LENGTH) : null;
            // The geo lookup above ran on the full address; only the stored
            // copy is coarsened when anonymization is enabled.
            $record->ip = $this->_settings()->anonymizeIp ? Ip::anonymize($ip) : $ip;
            $record->city = $location['city'];
            $record->country = $location['country'];
            $record->save(false);
        } catch (Throwable $e) {
            Craft::warning("Could not record the session for user {$user->id}: {$e->getMessage()}", __METHOD__);
        }
    }

    /**
     * Revokes one of a user's sessions by its registry uid.
     *
     * Ownership is scoped in the lookup — the row must match both the uid and the
     * user — so the posted uid alone can never reach another user's session. When
     * it matches, the core `{{%sessions}}` row is deleted via its stored hash
     * (the authoritative kill) and the registry row with it. A uid that resolves
     * to no owned row is a no-op returning false.
     *
     * @param User $user the user revoking a session
     * @param string $uid the registry uid of the session to revoke
     * @return bool whether a session was revoked
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function revoke(User $user, string $uid): bool
    {
        $userId = (int)$user->id;

        $row = (new Query())
            ->select(['id', 'tokenHash'])
            ->from(Table::SESSIONS)
            ->where(['uid' => $uid, 'userId' => $userId])
            ->one();

        if ($row === null) {
            return false;
        }

        $this->_deleteCraftSession($userId, (string)$row['tokenHash']);
        Db::delete(Table::SESSIONS, ['id' => $row['id']]);
        $this->_recordRevocation($userId, 'single');

        return true;
    }

    /**
     * Revokes every session a user holds except the one making the current
     * request — the "sign out everywhere else" action.
     *
     * Walks the user's core `{{%sessions}}` rows, skips the current token, and
     * deletes each other row (the authoritative kill) along with its registry
     * row. Rows with no registry entry are still revoked — the delete keys on the
     * core row, so an unknown device is signed out too.
     *
     * @param User $user the user signing out their other sessions
     * @return int the number of sessions revoked
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function revokeOthers(User $user): int
    {
        $userId = (int)$user->id;
        $currentHash = $this->_currentTokenHash();

        $coreRows = (new Query())
            ->select(['id', 'token'])
            ->from(CraftTable::SESSIONS)
            ->where(['userId' => $userId])
            ->all();

        $revoked = 0;

        foreach ($coreRows as $row) {
            $hash = hash('sha256', (string)$row['token']);

            if ($currentHash !== null && hash_equals($currentHash, $hash)) {
                continue;
            }

            Db::delete(CraftTable::SESSIONS, ['id' => $row['id']]);
            Db::delete(Table::SESSIONS, ['userId' => $userId, 'tokenHash' => $hash]);
            $revoked++;
        }

        // Only a genuine revocation is an audit fact — "sign out everywhere else"
        // that found nothing to sign out (only the current session) records
        // nothing.
        if ($revoked > 0) {
            $this->_recordRevocation($userId, 'others');
        }

        return $revoked;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the sha256 hash of the current request's session token, or null
     * when there is no session token (a guest or session-less context).
     *
     * @return string|null
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _currentTokenHash(): ?string
    {
        $token = $this->_userSession()->getToken();

        return $token !== null ? hash('sha256', $token) : null;
    }

    /**
     * Deletes the core `{{%sessions}}` row matching a stored token hash.
     *
     * The registry stores only hashes, so the match is re-derived by hashing the
     * user's live core tokens — a user rarely holds more than a handful.
     * Comparison is constant-time as basic hygiene, though the hashes compared
     * here are not secret-bearing.
     *
     * @param int $userId the user the session belongs to
     * @param string $tokenHash the sha256 hash of the session token to kill
     * @return int the number of core session rows deleted (0 or 1)
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _deleteCraftSession(int $userId, string $tokenHash): int
    {
        $candidates = (new Query())
            ->select(['id', 'token'])
            ->from(CraftTable::SESSIONS)
            ->where(['userId' => $userId])
            ->all();

        foreach ($candidates as $candidate) {
            if (hash_equals($tokenHash, hash('sha256', (string)$candidate['token']))) {
                return Db::delete(CraftTable::SESSIONS, ['id' => $candidate['id']]);
            }
        }

        return 0;
    }

    /**
     * Records a session revocation as an audit fact through Auth Kit's neutral
     * contract — a no-op with no sinks registered. Only genuine revocations
     * reach here (the callers gate on a real kill), never a no-op.
     *
     * @param int $userId the user whose session was revoked
     * @param string $scope the revocation scope — `single` or `others`
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _recordRevocation(int $userId, string $scope): void
    {
        // Session management runs entirely on core's tables, so it still works
        // with Auth Kit disabled — the audit deref is guarded so a missing
        // contract can never turn an already-completed kill into a 500.
        if (AuthKit::getInstance() === null) {
            return;
        }

        AuthKit::$plugin->getAudit()->record(new AuthEvent(
            name: AuthEvent::SESSION_REVOKED,
            emitter: 'warp',
            userId: $userId,
            details: ['scope' => $scope],
        ));
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
     * Maps one core `{{%sessions}}` row to a [[SessionInfo]], joining its
     * registry entry for device metadata and flagging the current session.
     *
     * @param array<string, mixed> $row the core session row, with `token` and `dateUpdated`
     * @param array<string, array<string, mixed>> $registry the user's registry rows, keyed by token hash
     * @param string|null $currentHash the hash of the current request's token, or null
     * @return SessionInfo
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _toSessionInfo(array $row, array $registry, ?string $currentHash): SessionInfo
    {
        $hash = hash('sha256', (string)$row['token']);
        $match = $registry[$hash] ?? null;
        $lastSeen = $row['dateUpdated'] ?? null;

        $userAgent = $match !== null && $match['userAgent'] !== null ? (string)$match['userAgent'] : null;

        $info = new SessionInfo();
        $info->uid = $match !== null ? (string)$match['uid'] : null;
        $info->ip = $match !== null && $match['ip'] !== null ? (string)$match['ip'] : null;
        $info->city = $match !== null && $match['city'] !== null ? (string)$match['city'] : null;
        $info->deviceLabel = $match !== null
            ? Device::label($userAgent)
            : Craft::t('warp', 'Unknown device');
        $info->deviceType = Device::type($userAgent);
        // The column holds a naive UTC string while the process timezone is
        // the system timezone, so the zone must be named at parse time.
        $info->lastSeen = is_string($lastSeen) ? Carbon::parse($lastSeen, 'UTC') : null;
        $info->isCurrent = $currentHash !== null && hash_equals($currentHash, $hash);

        return $info;
    }

    /**
     * Returns the web user session component, narrowed for static analysis —
     * this service only ever runs on web requests.
     *
     * @return \craft\web\User
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _userSession(): \craft\web\User
    {
        $userSession = Craft::$app->getUser();
        assert($userSession instanceof \craft\web\User);

        return $userSession;
    }
}
