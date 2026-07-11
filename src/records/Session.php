<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\records;

use craft\db\ActiveRecord;
use craftpulse\warp\db\Table;

/**
 * Session record maps the `warp_sessions` table — the device registry Warp keeps
 * alongside core's `{{%sessions}}` table, which stores only a token and its
 * timestamps and so carries no device information of its own.
 *
 * Each row pins the sha256 hash of a Craft auth-session token to the device that
 * produced it (a truncated user-agent and IP, for display only). Listing joins
 * these rows back to the user's live core sessions by re-hashing each core
 * token; revocation deletes the core row via that same hash, then the registry
 * row. Only the hash is stored, never the token itself, so a registry leak
 * yields nothing usable.
 *
 * @property int $id
 * @property int $userId
 * @property string $tokenHash
 * @property string|null $userAgent
 * @property string|null $ip
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class Session extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return Table::SESSIONS;
    }
}
