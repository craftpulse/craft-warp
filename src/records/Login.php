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
 * Login record maps the `warp_logins` table — the append-only log of
 * passwordless sign-ins that backs the control-panel overview screen.
 *
 * @property int $id
 * @property int $userId
 * @property string $method
 * @property string|null $userAgent
 * @property string|null $ip
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class Login extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return Table::LOGINS;
    }
}
