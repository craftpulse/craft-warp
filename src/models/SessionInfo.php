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
 * SessionInfo is one row the session-management screen renders: a single active
 * Craft session, resolved for display. It is assembled by
 * [[\craftpulse\warp\services\Sessions::getSessionsForUser()]] by joining the
 * user's live rows in core's `{{%sessions}}` table against Warp's device
 * registry, never persisted itself.
 *
 * The [[uid]] is the registry row's UID, the handle the front end posts back to
 * revoke this one session — the raw token is never exposed. A session core knows
 * about but the registry does not (created before Warp was installed, or by a
 * path Warp does not capture) still appears, labelled as an unknown device and
 * with a null [[uid]]; those are revocable only through "sign out everywhere
 * else", since there is no per-row handle to target.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class SessionInfo extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var string A coarse, human-friendly device label like "Chrome on macOS",
     * derived from the captured user-agent by
     * [[\craftpulse\warp\helpers\Device::label()]]. "Unknown device" when the
     * user-agent is missing or the session predates the registry.
     *
     * @since 5.0.0
     */
    public string $deviceLabel = '';

    /**
     * @var bool Whether this is the session making the current request — the one
     * a "this device" badge marks and that "sign out everywhere else" spares.
     *
     * @since 5.0.0
     */
    public bool $isCurrent = false;

    /**
     * @var string|null The IP captured when the session was registered, or null
     * for a session that predates the registry.
     *
     * @since 5.0.0
     */
    public ?string $ip = null;

    /**
     * @var DateTime|null When the session was last seen, taken from core's own
     * `{{%sessions}}.dateUpdated` — core touches it as the session is used, so
     * it reflects genuine activity rather than Warp's bookkeeping.
     *
     * @since 5.0.0
     */
    public ?DateTime $lastSeen = null;

    /**
     * @var string|null The registry row's UID, the handle the front end posts to
     * revoke this session. Null for a session with no registry row — revocable
     * only through "sign out everywhere else".
     *
     * @since 5.0.0
     */
    public ?string $uid = null;
}
