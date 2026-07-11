<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\models;

use Craft;
use craft\base\Model;

/**
 * Settings holds Warp's passwordless tunables. Auth Kit owns the token store
 * and exposes these as service properties; Warp is the product that owns the
 * configuration UX and pushes the values into Auth Kit at plugin init — see
 * `PluginTrait::_configureAuthKit()`.
 *
 * All of these knobs are edited in Warp's own control-panel section and are
 * project-config tracked, so they sync across environments.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class Settings extends Model
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The magic-link login channel — an emailed, single-use link
     * carrying an unguessable 32-byte secret.
     *
     * @since 5.0.0
     */
    public const CHANNEL_MAGIC_LINK = 'magic-link';

    /**
     * @var string The one-time-code login channel — an emailed short numeric
     * code the visitor types back into a verify form.
     *
     * @since 5.0.0
     */
    public const CHANNEL_OTP = 'otp';

    // Public Properties
    // =========================================================================

    /**
     * @var bool Whether Warp offers passwordless registration from the unified
     * request form. Registration additionally requires Craft's own
     * `allowPublicRegistration` — when either is off the form silently degrades
     * to login-only and an unknown address gets nothing. See
     * [[\craftpulse\warp\services\Registration::isEnabled()]].
     *
     * @since 5.0.0
     */
    public bool $enableRegistration = true;

    /**
     * @var bool Whether to nudge a user to enroll a passkey after they sign in
     * over an email flow (magic link, code, or registration) while holding no
     * passkey yet. Surfaced once per triggering login through
     * [[\craftpulse\warp\variables\WarpVariable::getShowPasskeyNudge()]].
     *
     * @since 5.0.0
     */
    public bool $enablePasskeyNudge = true;

    /**
     * @var array<int, string> The passwordless login channels Warp offers, a
     * subset of [[CHANNEL_MAGIC_LINK]] and [[CHANNEL_OTP]]. A channel not listed
     * here is refused at issuance even if requested directly, so disabling one
     * closes it end to end.
     *
     * @since 5.0.0
     */
    public array $loginMethods = [self::CHANNEL_MAGIC_LINK, self::CHANNEL_OTP];

    /**
     * @var int The number of digits in an issued OTP code.
     *
     * @since 5.0.0
     */
    public int $otpDigits = 6;

    /**
     * @var int The number of failed OTP attempts before a code is burned.
     *
     * @since 5.0.0
     */
    public int $otpMaxAttempts = 5;

    /**
     * @var int The maximum number of tokens issued to one address per
     * [[perEmailWindow]] seconds.
     *
     * @since 5.0.0
     */
    public int $perEmailLimit = 5;

    /**
     * @var int The per-address issuance throttle window, in seconds.
     *
     * @since 5.0.0
     */
    public int $perEmailWindow = 300;

    /**
     * @var int The recent-auth window, in seconds — how long a prior sign-in
     * satisfies the recent-auth gate before a step-up is required.
     *
     * @since 5.0.0
     */
    public int $recentAuthDuration = 300;

    /**
     * @var string|null The UID of the user group new registrants join, or null
     * to fall back to Craft's own default user group. Stored as a UID (never a
     * DB id) so the reference is project-config safe and stable across
     * environments.
     *
     * @since 5.0.0
     */
    public ?string $registrationGroupUid = null;

    /**
     * @var int How long an issued magic link or OTP code stays valid, in seconds.
     *
     * @since 5.0.0
     */
    public int $tokenTtl = 900;

    // Public Methods
    // =========================================================================

    /**
     * Validates that [[loginMethods]] is a non-empty subset of the supported
     * channels. An empty or out-of-range set would leave the front end with no
     * usable login path (or an unbacked one), so it fails closed.
     *
     * @param string $attribute the attribute under validation
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function validateLoginMethods(string $attribute): void
    {
        $value = $this->$attribute;

        if (!is_array($value) || $value === []) {
            $this->addError($attribute, Craft::t('warp', 'At least one login method must be enabled.'));

            return;
        }

        $invalid = array_diff($value, [self::CHANNEL_MAGIC_LINK, self::CHANNEL_OTP]);

        if ($invalid !== []) {
            $this->addError($attribute, Craft::t('warp', 'Login methods must be magic-link, otp, or both.'));
        }
    }

    /**
     * Validates that [[registrationGroupUid]], when set, references a user group
     * that still exists. A dangling UID (a group deleted after it was chosen)
     * fails closed rather than silently dropping new registrants into no group.
     *
     * @param string $attribute the attribute under validation
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function validateRegistrationGroupUid(string $attribute): void
    {
        $value = $this->$attribute;

        if ($value === null || $value === '') {
            return;
        }

        if (Craft::$app->getUserGroups()->getGroupByUid($value) === null) {
            $this->addError($attribute, Craft::t('warp', 'The selected registration user group no longer exists.'));
        }
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @return array<int, mixed>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['loginMethods'], 'validateLoginMethods', 'skipOnEmpty' => false];
        $rules[] = [['tokenTtl', 'recentAuthDuration', 'perEmailWindow'], 'integer', 'min' => 60, 'max' => 86400];
        $rules[] = [['otpDigits'], 'integer', 'min' => 4, 'max' => 10];
        $rules[] = [['otpMaxAttempts'], 'integer', 'min' => 1, 'max' => 10];
        $rules[] = [['perEmailLimit'], 'integer', 'min' => 1, 'max' => 100];
        $rules[] = [['enableRegistration', 'enablePasskeyNudge'], 'boolean'];
        $rules[] = [['registrationGroupUid'], 'string'];
        $rules[] = [['registrationGroupUid'], 'validateRegistrationGroupUid'];

        return $rules;
    }
}
