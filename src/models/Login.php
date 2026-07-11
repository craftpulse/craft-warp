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

use craft\base\Model;
use DateTime;

/**
 * Login is a single recorded passwordless sign-in: which user, over which
 * method, from which device and address, and when. The overview screen renders
 * a list of these; the log is append-only and pruned by garbage collection.
 *
 * The method constants are the canonical set of passwordless channels Warp
 * records — the email flows name their own channel, and a passkey login is
 * detected off core's endpoint (see
 * [[\craftpulse\warp\services\Logins::record()]]).
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class Login extends Model
{
    // Const Properties
    // =========================================================================

    /**
     * @var string A sign-in completed by clicking an emailed magic link.
     *
     * @since 5.0.0
     */
    public const METHOD_MAGIC_LINK = 'magic-link';

    /**
     * @var string A sign-in completed by entering an emailed one-time code.
     *
     * @since 5.0.0
     */
    public const METHOD_OTP = 'otp';

    /**
     * @var string A sign-in completed with a WebAuthn passkey, through core's
     * `users/login-with-passkey` endpoint.
     *
     * @since 5.0.0
     */
    public const METHOD_PASSKEY = 'passkey';

    /**
     * @var string A first sign-in completed by verifying a registration link,
     * which also provisions the account.
     *
     * @since 5.0.0
     */
    public const METHOD_REGISTER = 'register';

    // Public Properties
    // =========================================================================

    /**
     * @var DateTime|null When the sign-in was recorded.
     *
     * @since 5.0.0
     */
    public ?DateTime $dateCreated = null;

    /**
     * @var int|null The database id of the log row.
     *
     * @since 5.0.0
     */
    public ?int $id = null;

    /**
     * @var string|null The requesting IP address at sign-in, truncated to fit
     * an IPv6 literal.
     *
     * @since 5.0.0
     */
    public ?string $ip = null;

    /**
     * @var string The passwordless method the sign-in used — one of the
     * `METHOD_*` constants.
     *
     * @since 5.0.0
     */
    public string $method = self::METHOD_MAGIC_LINK;

    /**
     * @var string|null The requesting user-agent at sign-in, truncated to 255
     * characters.
     *
     * @since 5.0.0
     */
    public ?string $userAgent = null;

    /**
     * @var int|null The Craft user the sign-in belongs to.
     *
     * @since 5.0.0
     */
    public ?int $userId = null;
}
