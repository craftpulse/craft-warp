<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\migrations;

use craft\db\Migration;
use craftpulse\authkit\migrations\Adoption;

/**
 * Converts a plugin-era Auth Kit install into the module-era layout Auth Kit
 * 1.7.0 ships, so upgrading Warp is all a site has to do.
 *
 * Before 1.7.0 Auth Kit was a Craft plugin: an `auth-kit` row in the `plugins`
 * table, a `plugins.auth-kit` project-config entry, an entry in the control
 * panel's plugin list, and a migration history on the `plugin:auth-kit` track.
 * From 1.7.0 it is a library-shipped Yii module that Composer installs and no
 * one enables, with its own `module:auth-kit` migration track.
 *
 * The conversion itself belongs to Auth Kit, which owns every fact about its
 * own layout, so this migration is the single documented call and nothing else
 * (see [[\craftpulse\authkit\migrations\Adoption]] for the exact steps). In
 * short: the plugin era's applied migrations are marked applied on the module
 * track and the plugin-track rows deleted, the `plugins` row and project-config
 * entry are removed with an explicit flush, and the module migrator is run to
 * apply anything genuinely pending. Auth Kit's own tables are never touched, so
 * no token, passkey, or credential is lost.
 *
 * Three properties matter here and all belong to `adoptFromPlugin()`:
 *
 * - It is idempotent, so a re-run finds nothing left to do.
 * - It degrades to a plain migrator `up()` on an install that never had the
 *   plugin, which is what a site installing Warp fresh at this release gets
 *   (that install runs [[Install]] instead, and this migration is marked
 *   applied without running).
 * - It is safe when several consumers ship it. Warden ships the same adoption
 *   migration; on an install running both, whichever migration runs first does
 *   the work and the second is a no-op.
 *
 * There is deliberately no `safeDown()` beyond the base no-op: reversing the
 * adoption would mean re-registering a plugin whose package no longer declares
 * itself one, which cannot succeed. Downgrading means downgrading the Composer
 * requirement.
 *
 * @author    CraftPulse
 * @package   Warp
 * @since     5.0.0
 */
class m260802_100000_adopt_auth_kit_module extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return bool
     *
     * @throws \Throwable if a pending Auth Kit migration fails to apply
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function safeUp(): bool
    {
        Adoption::adoptFromPlugin();

        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function safeDown(): bool
    {
        echo "m260802_100000_adopt_auth_kit_module cannot be reverted.\n";

        return false;
    }
}
