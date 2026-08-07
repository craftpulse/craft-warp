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

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\FileHelper;
use craftpulse\authkit\AuthKit;
use craftpulse\authkit\db\Table as AuthKitTable;
use craftpulse\authkit\migrations\Adoption;
use craftpulse\authkit\services\Geo as AuthKitGeo;
use craftpulse\warp\db\Table;
use Throwable;

/**
 * Hands the device registry, the geo database, and new-location awareness over
 * to the shared `craftpulse/craft-auth-kit` module, carrying every existing row
 * and file across so nothing a site already has is lost.
 *
 * Warp kept its own `warp_sessions` registry and its own new-location alerting.
 * So does Warden. On an install running both, one sign-in produced two device
 * cards and two "new sign-in to your account" emails. One shared registry, one
 * shared location history, and one claimed alert is the fix, and it only works
 * if every consumer reads the same tables — hence this migration.
 *
 * Four things happen, all idempotent:
 *
 * 1. Auth Kit's schema is brought up, creating `authkit_sessions` and
 *    `authkit_locations` (through [[Adoption::adoptFromPlugin()]], the
 *    documented call, which also covers an install still carrying plugin-era
 *    Auth Kit registration).
 * 2. Every `warp_sessions` row is copied into the shared registry, uid and
 *    timestamps intact, so no member is signed out and no device card
 *    disappears. The old table is then dropped.
 * 3. Every distinct place in `warp_logins` is seeded into the shared location
 *    history, so a member who has been signing in from the same city for
 *    months is not treated as a stranger there by the next consumer to read it.
 * 4. An installed `storage/warp/geo/city.mmdb` is moved to the shared path, so
 *    location awareness keeps working without a re-download.
 *
 * The other half of the handover, the withdrawn download URL Warp shipped as a
 * default, is deliberately NOT repaired here: see
 * [[\craftpulse\warp\models\Settings::DEAD_GEO_DATABASE_URL]] for why a plugin
 * upgrade has no business rewriting an install's tracked project config.
 *
 * @author    CraftPulse
 * @package   Warp
 * @since     5.0.1
 */
class m260807_000001_adopt_auth_kit_sessions extends Migration
{
    // Const Properties
    // =========================================================================

    /**
     * @var int How many registry rows are read and re-inserted at a time. A
     * registry only ever describes live sessions, so this is a ceiling on memory
     * rather than a number any ordinary install reaches.
     *
     * @since 5.0.1
     */
    private const COPY_BATCH_SIZE = 500;

    /**
     * @var string The registry table Warp owned before the handover. Pinned as a
     * literal, not read from [[Table]], because that constant now names Auth
     * Kit's table — a migration records history and must not drift with the code.
     *
     * @since 5.0.1
     */
    private const LEGACY_SESSIONS_TABLE = '{{%warp_sessions}}';

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
     * @since 5.0.1
     */
    public function safeUp(): bool
    {
        Adoption::adoptFromPlugin();

        $this->_carryOverSessionRegistry();
        $this->_backfillLocationHistory();
        $this->_moveGeoDatabase();

        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool
     *
     * @author CraftPulse
     * @since 5.0.1
     */
    public function safeDown(): bool
    {
        echo "m260807_000001_adopt_auth_kit_sessions cannot be reverted.\n";

        return false;
    }

    // Private Methods
    // =========================================================================

    /**
     * Seeds the shared location history from every distinct place already in
     * Warp's login log.
     *
     * Without this, the shared history starts empty and the first consumer to
     * read it treats a member's own home city as somewhere they have never been.
     * The rule spares a user with no history at all (a first sign-in is never
     * new), so the practical effect would be one wrongly-quiet login rather than
     * a wrong alert — but a baseline the site already has is worth keeping.
     *
     * Distinct places, not rows: a member with 400 sign-ins from two cities
     * contributes two.
     *
     * @author CraftPulse
     * @since 5.0.1
     */
    private function _backfillLocationHistory(): void
    {
        if (!$this->db->tableExists(Table::LOGINS)) {
            return;
        }

        $locations = AuthKit::getInstance()->getLocations();

        $places = (new Query())
            ->select(['userId', 'country', 'city'])
            ->distinct()
            ->from(Table::LOGINS)
            ->where(['not', ['country' => null]])
            ->all($this->db);

        foreach ($places as $place) {
            $locations->seen(
                (int)$place['userId'],
                (string)$place['country'],
                $place['city'] !== null ? (string)$place['city'] : null,
            );
        }
    }

    /**
     * Copies every `warp_sessions` row into the shared registry and drops the
     * old table.
     *
     * Rows whose token hash is already in the shared registry are skipped, so a
     * re-run copies nothing twice and an install where another consumer already
     * registered the same live session keeps that consumer's row. The uid, the
     * timestamps, and the resolved location are all carried across: the uid in
     * particular is the handle the front end posts to revoke one device, so a
     * page rendered before the upgrade still works against the row after it.
     *
     * Copied in PHP rather than as an `INSERT ... SELECT`, because the guard
     * would have to subquery the insert target and MySQL refuses that.
     *
     * @author CraftPulse
     * @since 5.0.1
     */
    private function _carryOverSessionRegistry(): void
    {
        if (!$this->db->tableExists(self::LEGACY_SESSIONS_TABLE)) {
            return;
        }

        $columns = ['userId', 'tokenHash', 'userAgent', 'ip', 'city', 'country', 'dateCreated', 'dateUpdated', 'uid'];

        $claimed = array_flip((new Query())
            ->select(['tokenHash'])
            ->from(AuthKitTable::SESSIONS)
            ->column($this->db));

        $query = (new Query())->select($columns)->from(self::LEGACY_SESSIONS_TABLE);

        foreach ($query->batch(self::COPY_BATCH_SIZE, $this->db) as $batch) {
            $rows = [];

            foreach ($batch as $row) {
                if (!isset($claimed[$row['tokenHash']])) {
                    $rows[] = array_map(static fn(string $column): mixed => $row[$column], $columns);
                }
            }

            if ($rows !== []) {
                $this->batchInsert(AuthKitTable::SESSIONS, $columns, $rows);
            }
        }

        $this->dropTableIfExists(self::LEGACY_SESSIONS_TABLE);
        // Craft memoizes table schemas, so the guard at the top of this method
        // would still report the dropped table as present on a second call in
        // the same process — which is exactly what a re-run is.
        $this->db->getSchema()->refreshTableSchema(self::LEGACY_SESSIONS_TABLE);
    }

    /**
     * Moves an installed city database from Warp's old storage path to the
     * shared one.
     *
     * The file is not small and re-downloading it is the site's problem, not
     * ours, so it is carried rather than abandoned. A move only happens when
     * there is something to move and nothing already at the destination — an
     * install where another consumer has already put a (likely newer) database
     * there keeps that one. Failure is logged, never fatal: an upgrade must not
     * fall over because a storage directory is read-only, and the worst outcome
     * is one `warp/geo/refresh` run.
     *
     * @author CraftPulse
     * @since 5.0.1
     */
    private function _moveGeoDatabase(): void
    {
        $source = Craft::$app->getPath()->getStoragePath()
            . DIRECTORY_SEPARATOR . 'warp'
            . DIRECTORY_SEPARATOR . 'geo'
            . DIRECTORY_SEPARATOR . 'city.mmdb';

        $target = (new AuthKitGeo())->path();

        if (!is_file($source) || is_file($target)) {
            return;
        }

        try {
            FileHelper::createDirectory(dirname($target));
            rename($source, $target);
        } catch (Throwable $e) {
            Craft::warning("Could not move the geo database to the shared path: {$e->getMessage()}", __METHOD__);
        }
    }
}
