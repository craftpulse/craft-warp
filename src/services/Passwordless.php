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
use craftpulse\authkit\AuthKit;
use craftpulse\warp\models\Settings;
use craftpulse\warp\Warp;
use yii\base\Component;

/**
 * Passwordless is Warp's front-end login orchestrator. It sits between the
 * public controllers and Auth Kit's headless token store: it enforces which
 * channels are enabled, delegates issuance to Auth Kit, and owns the single
 * session-login hook that later phases extend (passkey nudge, login log,
 * session capture).
 *
 * Issuance is deliberately opaque. [[request()]] returns void: an enabled
 * channel dispatches to Auth Kit (which is itself enumeration-safe — an unknown
 * or ineligible address takes the same timing-equalized path), and a disabled
 * channel is refused without any signal the caller could relay. The public
 * controller therefore responds identically in every branch.
 *
 * An instance is available via `Warp::$plugin->getPasswordless()`.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class Passwordless extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Logs a user into a front-end session, honouring Craft's configured
     * session duration.
     *
     * This is the single hook point for every Warp email-flow login: later
     * phases extend it (passkey enrollment nudge, login logging, device-session
     * capture) so those concerns attach in exactly one place.
     *
     * @param User $user the user to log in
     * @return bool whether the session login succeeded
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function loginUser(User $user): bool
    {
        $generalConfig = Craft::$app->getConfig()->getGeneral();

        return $this->_userSession()->login($user, $generalConfig->userSessionDuration);
    }

    /**
     * Issues a passwordless login credential for an address over the requested
     * channel, when that channel is enabled.
     *
     * Returns void deliberately: the caller facing the public must not learn
     * whether a credential was issued. A channel not in [[Settings::$loginMethods]]
     * is refused silently; an enabled channel delegates to Auth Kit, which never
     * reveals whether the address maps to an account.
     *
     * @param string $email the address to send a login credential to
     * @param string $channel one of [[Settings::CHANNEL_MAGIC_LINK]] or [[Settings::CHANNEL_OTP]]
     * @param string|null $returnUrl the validated URL to return to after login
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function request(string $email, string $channel, ?string $returnUrl = null): void
    {
        // A channel the admin has disabled is closed end to end — refused here
        // even if requested directly, with no signal the caller could relay.
        if (!in_array($channel, $this->_settings()->loginMethods, true)) {
            return;
        }

        $tokens = AuthKit::$plugin->getTokens();

        if ($channel === Settings::CHANNEL_MAGIC_LINK) {
            $tokens->issueMagicLink($email, $returnUrl);

            return;
        }

        if ($channel === Settings::CHANNEL_OTP) {
            $tokens->issueOtp($email);
        }
    }

    // Private Methods
    // =========================================================================

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
