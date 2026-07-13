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
use craft\helpers\App;

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
     * @var bool Whether stored IP addresses are anonymized. When enabled, the
     * final octet of an IPv4 address (the final 80 bits of an IPv6 address) is
     * zeroed through [[\craftpulse\warp\helpers\Ip::anonymize()]] before a
     * login-log or session-registry row is written. Geo lookups still run on
     * the full address first, so city-level location is unaffected. Applies to
     * new rows only; rows already stored keep their captured address until
     * pruned.
     *
     * @since 5.0.0
     */
    public bool $anonymizeIp = false;

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
     * @var string The URL a fresh city MMDB is downloaded from by the geo refresh
     * command. Accepts a literal URL or an environment-variable reference; resolve
     * it through [[getGeoDatabaseUrl()]]. This setting is not surfaced in the
     * control panel — set it in `config/warp.php` when you need to point at a
     * different, licence-appropriate database. Defaults to the openly licensed,
     * keyless ip-location-db city database.
     *
     * @since 5.0.0
     */
    public string $geoDatabaseUrl = 'https://cdn.jsdelivr.net/npm/@ip-location-db/geo-whois-asn-city-mmdb/geo-whois-asn-city.mmdb';

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
     * @var bool Whether to email a member when they sign in from a location
     * (country and city) they have never signed in from before. Requires a geo
     * database to be present — with none, no location is resolved, so nothing is
     * ever flagged and no alert is sent.
     *
     * @since 5.0.0
     */
    public bool $notifyOnNewLocation = true;

    /**
     * @var int|string The number of digits in an issued OTP code. Accepts a
     * literal integer or an environment-variable reference like `$WARP_OTP_DIGITS`;
     * resolve it through [[getOtpDigits()]], never by reading the property.
     *
     * @since 5.0.0
     */
    public int|string $otpDigits = 6;

    /**
     * @var int|string The number of failed OTP attempts before a code is burned.
     * Accepts a literal integer or an environment-variable reference; resolve it
     * through [[getOtpMaxAttempts()]].
     *
     * @since 5.0.0
     */
    public int|string $otpMaxAttempts = 5;

    /**
     * @var int|string The maximum number of tokens issued to one address per
     * [[perEmailWindow]] seconds. Accepts a literal integer or an
     * environment-variable reference; resolve it through [[getPerEmailLimit()]].
     *
     * @since 5.0.0
     */
    public int|string $perEmailLimit = 5;

    /**
     * @var int|string The per-address issuance throttle window, in seconds.
     * Accepts a literal integer or an environment-variable reference; resolve it
     * through [[getPerEmailWindow()]].
     *
     * @since 5.0.0
     */
    public int|string $perEmailWindow = 300;

    /**
     * @var int|string The recent-auth window, in seconds — how long a prior
     * sign-in satisfies the recent-auth gate before a step-up is required.
     * Accepts a literal integer or an environment-variable reference; resolve it
     * through [[getRecentAuthDuration()]].
     *
     * @since 5.0.0
     */
    public int|string $recentAuthDuration = 300;

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
     * @var int|string How long an issued magic link or OTP code stays valid, in
     * seconds. Accepts a literal integer or an environment-variable reference;
     * resolve it through [[getTokenTtl()]].
     *
     * @since 5.0.0
     */
    public int|string $tokenTtl = 900;

    // Public Methods
    // =========================================================================

    /**
     * Returns the resolved URL a fresh city MMDB is downloaded from.
     *
     * @return string
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getGeoDatabaseUrl(): string
    {
        return (string)App::parseEnv($this->geoDatabaseUrl);
    }

    /**
     * Returns the resolved number of digits in an issued OTP code.
     *
     * @return int
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getOtpDigits(): int
    {
        return $this->_resolveInt($this->otpDigits);
    }

    /**
     * Returns the resolved number of failed OTP attempts allowed before a code
     * is burned.
     *
     * @return int
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getOtpMaxAttempts(): int
    {
        return $this->_resolveInt($this->otpMaxAttempts);
    }

    /**
     * Returns the resolved maximum number of tokens issued to one address per
     * [[getPerEmailWindow()]] seconds.
     *
     * @return int
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getPerEmailLimit(): int
    {
        return $this->_resolveInt($this->perEmailLimit);
    }

    /**
     * Returns the resolved per-address issuance throttle window, in seconds.
     *
     * @return int
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getPerEmailWindow(): int
    {
        return $this->_resolveInt($this->perEmailWindow);
    }

    /**
     * Returns the resolved recent-auth window, in seconds.
     *
     * @return int
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getRecentAuthDuration(): int
    {
        return $this->_resolveInt($this->recentAuthDuration);
    }

    /**
     * Returns the resolved lifetime of an issued magic link or OTP code, in
     * seconds.
     *
     * @return int
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getTokenTtl(): int
    {
        return $this->_resolveInt($this->tokenTtl);
    }

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

    /**
     * Validates one of the environment-aware numeric tunables by resolving it
     * first and range-checking the resolved value, so a literal integer and an
     * environment-variable reference are held to the same bounds. A value that
     * resolves to something non-numeric — an undefined or misspelled environment
     * variable — fails with a clear message rather than a confusing range error.
     *
     * @param string $attribute the attribute under validation
     * @param array<string, int>|null $params the `min` and `max` bounds for the resolved value
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function validateResolvedInt(string $attribute, ?array $params = null): void
    {
        $resolved = App::parseEnv((string)$this->$attribute);

        if ($resolved === null || !is_numeric($resolved)) {
            $this->addError($attribute, Craft::t('warp', '{attribute} must be a whole number, or an environment variable that resolves to one.', [
                'attribute' => $this->getAttributeLabel($attribute),
            ]));

            return;
        }

        $value = (int)$resolved;
        $min = $params['min'] ?? null;
        $max = $params['max'] ?? null;

        if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
            $this->addError($attribute, Craft::t('warp', '{attribute} must resolve to a value between {min} and {max}.', [
                'attribute' => $this->getAttributeLabel($attribute),
                'min' => $min,
                'max' => $max,
            ]));
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
        $rules[] = [['tokenTtl', 'recentAuthDuration', 'perEmailWindow'], 'validateResolvedInt', 'params' => ['min' => 60, 'max' => 86400], 'skipOnEmpty' => false];
        $rules[] = [['otpDigits'], 'validateResolvedInt', 'params' => ['min' => 4, 'max' => 10], 'skipOnEmpty' => false];
        $rules[] = [['otpMaxAttempts'], 'validateResolvedInt', 'params' => ['min' => 1, 'max' => 10], 'skipOnEmpty' => false];
        $rules[] = [['perEmailLimit'], 'validateResolvedInt', 'params' => ['min' => 1, 'max' => 100], 'skipOnEmpty' => false];
        $rules[] = [['anonymizeIp', 'enableRegistration', 'enablePasskeyNudge', 'notifyOnNewLocation'], 'boolean'];
        $rules[] = [['geoDatabaseUrl'], 'string'];
        $rules[] = [['registrationGroupUid'], 'string'];
        $rules[] = [['registrationGroupUid'], 'validateRegistrationGroupUid'];

        return $rules;
    }

    // Private Methods
    // =========================================================================

    /**
     * Resolves a numeric tunable's raw value — a literal or an
     * environment-variable reference — to a concrete integer.
     *
     * @param int|string $value the raw stored value
     * @return int
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _resolveInt(int|string $value): int
    {
        return (int)App::parseEnv((string)$value);
    }
}
