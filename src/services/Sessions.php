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

use craft\elements\User;
use craftpulse\authkit\AuthKit;
use craftpulse\authkit\models\SessionInfo as AuthKitSessionInfo;
use craftpulse\authkit\services\Sessions as AuthKitSessions;
use craftpulse\warp\models\SessionInfo;
use craftpulse\warp\models\Settings;
use craftpulse\warp\Warp;
use yii\base\Component;

/**
 * Sessions is Warp's handle on the device registry behind the front-end
 * session-management screen.
 *
 * The registry itself lives in the shared `craftpulse/craft-auth-kit` module
 * (see [[\craftpulse\authkit\services\Sessions]]) rather than in Warp. Two
 * security plugins each keeping their own registry meant two device lists for
 * one browser and two new-location emails for one sign-in; one shared table,
 * captured idempotently, means one of each.
 *
 * This service stays as Warp's published surface, unchanged in signature, and
 * supplies what Auth Kit deliberately does not assume: the `warp` emitter handle
 * on every audit fact, and Warp's own [[Settings::$anonymizeIp]] deciding
 * whether a captured address is coarsened before it is written.
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
     * @var string The emitter handle recorded against every audit fact this
     * service produces through Auth Kit's neutral contract.
     *
     * @since 5.0.0
     */
    private const EMITTER = 'warp';

    // Public Methods
    // =========================================================================

    /**
     * Returns the current user's active sessions as [[SessionInfo]] models, the
     * current session first and the rest by most recently seen.
     *
     * A core session with no registry row — one created before Warp was
     * installed, or by a path the registry does not capture — still appears,
     * labelled "Unknown device" with a null uid, so nothing is hidden from the
     * person managing their account.
     *
     * The re-map below names every property by hand, and it has to stay
     * exhaustive: [[SessionInfo]] extends Auth Kit's model and its docblock
     * promises templates that every property is inherited unchanged, so a
     * property added upstream and missed here is not absent, it is present and
     * permanently null. Whenever Warp's Auth Kit requirement is raised to a
     * release that adds one, add it here too.
     *
     * @param User $user the user whose sessions to list
     * @return array<int, SessionInfo> the user's active sessions
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getSessionsForUser(User $user): array
    {
        return array_map(
            static fn(AuthKitSessionInfo $info): SessionInfo => new SessionInfo([
                'city' => $info->city,
                'deviceLabel' => $info->deviceLabel,
                'deviceType' => $info->deviceType,
                'isCurrent' => $info->isCurrent,
                'isNewLocation' => $info->isNewLocation,
                'ip' => $info->ip,
                'lastSeen' => $info->lastSeen,
                'uid' => $info->uid,
            ]),
            $this->_registry()->getSessionsForUser($user),
        );
    }

    /**
     * Prunes orphaned registry rows — rows whose core `{{%sessions}}` row no
     * longer exists.
     *
     * Auth Kit owns the table and wires this onto `craft\services\Gc::EVENT_RUN`
     * itself, so Warp's own garbage-collection listener is belt and braces: the
     * second pass in one run finds nothing left to do.
     *
     * @return int the number of registry rows deleted
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function pruneOrphans(): int
    {
        return $this->_registry()->pruneOrphans();
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
        $this->_registry()->pruneToken($token);
    }

    /**
     * Records the current request's session against the device that made it.
     *
     * Called from `WebUser::EVENT_AFTER_LOGIN`, by which point core has already
     * generated the token and inserted the `{{%sessions}}` row. Warp's
     * [[Settings::$anonymizeIp]] rides the call, so the shared registry stores
     * exactly what this install's privacy posture asks for. Best-effort
     * throughout: a bookkeeping failure never blocks a login already completed.
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function record(): void
    {
        $this->_registry()->record(['anonymizeIp' => $this->_settings()->anonymizeIp]);
    }

    /**
     * Revokes one of a user's sessions by its registry uid.
     *
     * Ownership is scoped in the lookup — the row must match both the uid and the
     * user — so the posted uid alone can never reach another user's session. A
     * uid that resolves to no owned row is a no-op returning false.
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
        return $this->_registry()->revoke($user, $uid, ['emitter' => self::EMITTER]);
    }

    /**
     * Revokes every session a user holds except the one making the current
     * request — the "sign out everywhere else" action.
     *
     * Rows with no registry entry are still revoked — the delete keys on the core
     * row, so an unknown device is signed out too.
     *
     * @param User $user the user signing out their other sessions
     * @return int the number of sessions revoked
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function revokeOthers(User $user): int
    {
        return $this->_registry()->revokeOthers($user, ['emitter' => self::EMITTER]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the shared registry service.
     *
     * @return AuthKitSessions
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _registry(): AuthKitSessions
    {
        return AuthKit::getInstance()->getSessions();
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
}
