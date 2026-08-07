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

use craftpulse\authkit\models\SessionInfo as AuthKitSessionInfo;

/**
 * SessionInfo is one row the session-management screen renders: a single active
 * Craft session, resolved for display. It is assembled by
 * [[\craftpulse\warp\services\Sessions::getSessionsForUser()]] by joining the
 * user's live rows in core's `{{%sessions}}` table against the shared device
 * registry, never persisted itself.
 *
 * The shape moved to [[\craftpulse\authkit\models\SessionInfo]] with the
 * registry it describes. This subclass is the type `craft.warp.sessions`
 * hands templates, kept as Warp published it: every property
 * ([[\craftpulse\authkit\models\SessionInfo::$uid]],
 * [[\craftpulse\authkit\models\SessionInfo::$deviceLabel]], and the rest) is
 * inherited unchanged, and a `SessionInfo` from Warp still satisfies an
 * Auth Kit type hint.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class SessionInfo extends AuthKitSessionInfo
{
}
