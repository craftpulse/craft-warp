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

use Craft;
use craft\elements\User;
use craftpulse\authkit\models\Token;
use craftpulse\warp\models\Settings;
use craftpulse\warp\Warp;
use Throwable;
use yii\base\Component;

/**
 * Registration owns Warp's passwordless account-provisioning decision — the
 * back half of the unified request/verify flow. Auth Kit issues and burns a
 * user-less registration token (mailbox possession is the only proof it
 * carries); Warp decides, at verify time, what account that proof unlocks.
 *
 * [[isEnabled()]] gates the feature on both Warp's own `enableRegistration`
 * setting and Craft's core `allowPublicRegistration` — Warp never overrides the
 * core switch, so when either is off the unified form degrades to login-only
 * (decision D3).
 *
 * [[fulfill()]] resolves the token's payload email against the account matrix
 * (decision D2): an unknown address is created active with no password and
 * dropped into the configured group; a pending account is activated; an active
 * account is returned as-is (its holder just proved they own the mailbox, the
 * same evidence a magic link accepts); a suspended, locked, or deactivated
 * account fails closed with a null return, so a stale token can never resurrect
 * a blocked account. Passwords never enter the picture — Warp is passwordless
 * first (decision D4).
 *
 * An instance is available via `Warp::$plugin->getRegistration()`.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class Registration extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Fulfills a consumed registration token, returning the user its holder
     * should be logged in as, or null when the account is in a state that must
     * fail closed.
     *
     * The token's payload email drives the account matrix (decision D2):
     *
     * - no account yet: created active with no password, username set to the
     *   email, assigned to [[Settings::$registrationGroupUid]] (or Craft's
     *   default group when that is unset or dangling);
     * - a pending account: activated in place;
     * - an active account: returned unchanged — mailbox possession is the same
     *   proof a magic link accepts;
     * - suspended, locked, or deactivated: null, so a stale token cannot
     *   revive a blocked account.
     *
     * @param Token $token the burned registration token, its email in the payload
     * @return User|null the user to log in, or null on any fail-closed branch
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function fulfill(Token $token): ?User
    {
        $email = $this->_email($token);

        if ($email === null) {
            return null;
        }

        $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($email);

        if ($user === null) {
            return $this->_createUser($email);
        }

        $status = $user->getStatus();

        if ($status === User::STATUS_PENDING) {
            return $this->_activateUser($user);
        }

        // getStatus() folds a locked account into "active", so the lock is
        // checked explicitly here — a locked account must fail closed.
        if ($status === User::STATUS_ACTIVE && !$user->locked) {
            return $user;
        }

        // Suspended, locked, or deactivated — a stale token must not revive it.
        return null;
    }

    /**
     * Returns whether passwordless registration is currently open.
     *
     * Requires both Warp's own [[Settings::$enableRegistration]] and Craft's
     * `allowPublicRegistration` (decision D3): Warp never overrides the core
     * switch, so a site can close registration from either place and the unified
     * form degrades to login-only with no change to its HTTP response.
     *
     * @return bool
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function isEnabled(): bool
    {
        if (!$this->_settings()->enableRegistration) {
            return false;
        }

        return $this->_allowsPublicRegistration();
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns Craft's own public-registration switch, read from project config
     * where core keeps it (`users.allowPublicRegistration`) — it is not a
     * GeneralConfig setting. This is the exact source core's own
     * `UsersController` checks before honouring an anonymous sign-up.
     *
     * @return bool
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _allowsPublicRegistration(): bool
    {
        return (bool)Craft::$app->getProjectConfig()->get('users.allowPublicRegistration');
    }

    /**
     * Activates a pending account in place, returning it, or null if Craft
     * refuses the activation. A failure is logged, never surfaced.
     *
     * @param User $user the pending user to activate
     * @return User|null
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _activateUser(User $user): ?User
    {
        try {
            Craft::$app->getUsers()->activateUser($user);
        } catch (Throwable $e) {
            Craft::warning("Could not activate the pending registrant: {$e->getMessage()}", __METHOD__);

            return null;
        }

        return $user;
    }

    /**
     * Assigns a freshly created registrant to the configured user group, falling
     * back to Craft's default group when [[Settings::$registrationGroupUid]] is
     * unset or points at a group that no longer exists.
     *
     * @param User $user the saved user to assign
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _assignGroup(User $user): void
    {
        $groupUid = $this->_settings()->registrationGroupUid;

        if ($groupUid !== null && $groupUid !== '') {
            $group = Craft::$app->getUserGroups()->getGroupByUid($groupUid);

            if ($group !== null) {
                Craft::$app->getUsers()->assignUserToGroups((int)$user->id, [(int)$group->id]);

                return;
            }
        }

        Craft::$app->getUsers()->assignUserToDefaultGroup($user);
    }

    /**
     * Creates an active, password-less account for an address that has none, and
     * assigns it to the configured group. Returns the created user, or null if
     * the save fails (logged, never surfaced).
     *
     * The account is created active because the token already proved mailbox
     * possession — the same status Craft grants after email verification, and
     * the SSO precedent for password-less active users (decision D3).
     *
     * @param string $email the address to provision
     * @return User|null
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _createUser(string $email): ?User
    {
        $user = new User();
        $user->username = $email;
        $user->email = $email;
        $user->active = true;

        if (!Craft::$app->getElements()->saveElement($user)) {
            Craft::warning("Could not create the registrant for {$email}.", __METHOD__);

            return null;
        }

        $this->_assignGroup($user);

        return $user;
    }

    /**
     * Extracts the target email from a registration token's payload, or null if
     * it is missing or malformed.
     *
     * @param Token $token the burned registration token
     * @return string|null
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _email(Token $token): ?string
    {
        $email = $token->payload['email'] ?? null;

        if (!is_string($email) || trim($email) === '') {
            return null;
        }

        return trim($email);
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
