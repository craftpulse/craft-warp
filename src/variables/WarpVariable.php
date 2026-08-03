<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\variables;

use Craft;
use craft\elements\User;
use craftpulse\authkit\variables\AuthKitVariable;
use craftpulse\warp\controllers\AuthController;
use craftpulse\warp\models\SessionInfo;
use craftpulse\warp\models\Settings;
use craftpulse\warp\services\Passwordless;
use craftpulse\warp\twig\tags\OtpFormTag;
use craftpulse\warp\twig\tags\OtpInputTag;
use craftpulse\warp\twig\tags\RequestFormTag;
use craftpulse\warp\Warp;

/**
 * WarpVariable is the `craft.warp` Twig variable — the single front-end handle
 * onto Warp's passwordless surface. It exposes read accessors for passkey state
 * ([[getHasPasskeys()]], [[getPasskeys()]]) and the reference WebAuthn client URL
 * ([[getWebauthnJsUrl()]], all delegated to Auth Kit), whether registration is
 * open ([[getRegistrationEnabled()]]), the enabled login methods
 * ([[getLoginMethods()]]), the one-time-code length ([[getOtpDigits()]]), the
 * session-carried code-entry prefill ([[getRequestedEmail()]]), the current
 * user's active sessions ([[getSessions()]]), and the show-once
 * passkey-enrollment nudge ([[getShowPasskeyNudge()]]); plus the three render
 * builders ([[requestForm()]], [[otpForm()]], [[otpInput()]]) covering both
 * steps of the email flow. It is the whole front-end contract — templates never
 * reach a Warp service or record directly.
 *
 * The passkey passthroughs delegate to Auth Kit's own variable, so templates
 * never need to know where the split falls — Warp owns the front-end handle,
 * Auth Kit owns the credential machinery.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class WarpVariable
{
    // Public Methods
    // =========================================================================

    /**
     * Returns whether the current user has any passkeys enrolled.
     *
     * @return bool
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getHasPasskeys(): bool
    {
        return $this->_authKitVariable()->hasPasskeys();
    }

    /**
     * Returns the enabled passwordless login methods, a subset of
     * [[Settings::CHANNEL_MAGIC_LINK]] and [[Settings::CHANNEL_OTP]] — so a
     * template can render only the channels the site offers.
     *
     * @return array<int, string>
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getLoginMethods(): array
    {
        return $this->_settings()->loginMethods;
    }

    /**
     * Returns the number of digits in an issued one-time code, so a code-entry
     * template can size its input and copy to the configured length instead of
     * hardcoding a default.
     *
     * @return int
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getOtpDigits(): int
    {
        return $this->_settings()->getOtpDigits();
    }

    /**
     * Returns the current user's saved passkeys, or an empty array for a guest —
     * ready for a management UI.
     *
     * @return array<int, array<string, mixed>>
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getPasskeys(): array
    {
        return $this->_authKitVariable()->passkeys();
    }

    /**
     * Returns whether passwordless registration is currently open — both Warp's
     * own setting and Craft's `allowPublicRegistration` must be on.
     *
     * @return bool
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getRegistrationEnabled(): bool
    {
        return Warp::$plugin->getRegistration()->isEnabled();
    }

    /**
     * Returns the email address the visitor last requested a sign-in credential
     * for, or null when none is held — the session-carried prefill the code-entry
     * page reads, so the visitor need not retype the address they just submitted.
     * It is the visitor's own input echoed back, so nothing is revealed. Cleared
     * by the verify endpoint on a successful sign-in.
     *
     * @return string|null
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getRequestedEmail(): ?string
    {
        $email = $this->_session()->get(AuthController::SESSION_REQUESTED_EMAIL);

        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * Returns the current user's active sessions for the session-management
     * screen — the current session flagged, each other device named — or an
     * empty array for a guest.
     *
     * @return array<int, SessionInfo>
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getSessions(): array
    {
        $user = Craft::$app->getUser()->getIdentity();

        if (!$user instanceof User) {
            return [];
        }

        return Warp::$plugin->getSessions()->getSessionsForUser($user);
    }

    /**
     * Returns whether the passkey-enrollment nudge should be shown, clearing the
     * flag as it reads it so the nudge surfaces exactly once per triggering
     * login. Set by [[Passwordless::loginUser()]] after an email-flow login by a
     * user who holds no passkey.
     *
     * @return bool
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getShowPasskeyNudge(): bool
    {
        $session = $this->_session();
        $show = (bool)$session->get(Passwordless::SESSION_PASSKEY_NUDGE_KEY);
        $session->remove(Passwordless::SESSION_PASSKEY_NUDGE_KEY);

        return $show;
    }

    /**
     * Returns the published URL of the reference WebAuthn client script, for the
     * passkey login and enrollment JS.
     *
     * @return string
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getWebauthnJsUrl(): string
    {
        return $this->_authKitVariable()->webauthnJsUrl();
    }

    /**
     * Returns the fluent builder for the complete one-time-code verify form —
     * `craft.warp.otpForm({ returnUrl: url('members/account') }).render()`
     * renders the post to `warp/auth/verify-code` with CSRF, the carried email
     * prefill (or a visible email input), the segmented code input, and the
     * submit button.
     *
     * @param array<string, mixed> $params builder options; each key matches a
     * chainable setter on [[OtpFormTag]]
     * @return OtpFormTag
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function otpForm(array $params = []): OtpFormTag
    {
        return new OtpFormTag($params);
    }

    /**
     * Returns the fluent builder for the segmented one-time-code input alone —
     * `craft.warp.otpInput().render()` — for a page composing its own form
     * around it. One square per digit, sized to the `otpDigits` setting,
     * paste-aware, degrading to a plain input with no JavaScript.
     *
     * @param array<string, mixed> $params builder options; each key matches a
     * chainable setter on [[OtpInputTag]]
     * @return OtpInputTag
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function otpInput(array $params = []): OtpInputTag
    {
        return new OtpInputTag($params);
    }

    /**
     * Returns the fluent builder for the unified sign-in and sign-up request
     * form — `craft.warp.requestForm({ otpVerifyUrl: 'members/otp-verify' }).render()`
     * renders the post to `warp/auth/request` with CSRF, the labelled email
     * input, the channel choice when the site offers both, and the submit
     * button. The form every visitor starts at.
     *
     * @param array<string, mixed> $params builder options; each key matches a
     * chainable setter on [[RequestFormTag]]
     * @return RequestFormTag
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function requestForm(array $params = []): RequestFormTag
    {
        return new RequestFormTag($params);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns Auth Kit's own Twig variable, which owns the passkey surface Warp
     * re-exposes.
     *
     * @return AuthKitVariable
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _authKitVariable(): AuthKitVariable
    {
        return new AuthKitVariable();
    }

    /**
     * Returns Craft's session component, narrowed for static analysis — the
     * variable only ever renders on web requests.
     *
     * @return \craft\web\Session
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _session(): \craft\web\Session
    {
        /** @var \craft\web\Application $app */
        $app = Craft::$app;

        return $app->getSession();
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
