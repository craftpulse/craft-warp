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
use craftpulse\authkit\AuthKit;

/**
 * Brings Auth Kit's shared schema up to date for the 1.11.0 requirement, which
 * adds `isNewLocation` to the shared device registry.
 *
 * Auth Kit is a library-shipped Yii module, not a Craft plugin, so it has no
 * plugin row for Craft to watch, no schema version to compare, and no migration
 * track of its own on the console: `migrate/up --track=module:auth-kit` is
 * rejected outright. Nothing applies its migrations on its behalf. Its schema
 * reaches an existing install only when a consumer runs its migrator from a
 * dated migration of the consumer's own, which is the documented contract (see
 * "Keeping the schema current" in Auth Kit's installation guide) and the reason
 * this file exists. Raising the Composer constraint alone ships the new code
 * and never creates the column.
 *
 * A fresh install takes the same schema by a different road:
 * [[Install::safeUp()]] pumps the same migrator through
 * [[\craftpulse\authkit\migrations\Adoption::adoptFromPlugin()]] before it
 * creates Warp's own table, so this migration is only ever the upgrade path for
 * a site that already had Warp.
 *
 * The call is the bare `up()` rather than `adoptFromPlugin()`, which
 * [[m260807_000001_adopt_auth_kit_sessions]] used: adoption is a one-time
 * conversion of a plugin-era install and that migration already did it here.
 * Everything on the module track is recorded and never re-run, so this is a
 * no-op on an install where another consumer (Warden) already brought the
 * shared schema up.
 *
 * @author CraftPulse
 * @package Warp
 * @since 5.0.2
 */
class m260807_000002_auth_kit_new_location_flag extends Migration
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
     * @since 5.0.2
     */
    public function safeUp(): bool
    {
        AuthKit::getInstance()->getMigrator()->up();

        return true;
    }

    /**
     * @inheritdoc
     *
     * Deliberately a no-op rather than a teardown. What this migration owns is
     * a slot on Warp's own track, not the shared schema it pumps: the
     * `authkit_*` tables belong to Auth Kit and are read by every consumer on
     * the install, so dropping a column here to revert one Warp migration would
     * break Warden and Auth Kit's own service alongside it. Reverting Warp is
     * not a licence to un-ship someone else's schema.
     *
     * Auth Kit's own `m260807_000002_AddSessionIsNewLocation` carries the real
     * `safeDown()`, on the track that owns the column.
     *
     * @return bool
     *
     * @author CraftPulse
     * @since 5.0.2
     */
    public function safeDown(): bool
    {
        return true;
    }
}
