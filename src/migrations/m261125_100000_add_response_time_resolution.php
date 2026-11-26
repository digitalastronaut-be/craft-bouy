<?php
/**
 * Bouy plugin for Craft CMS
 *
 * Small monitoring client exposing a credentialed endpoint that reports
 * version numbers, error logs, and response times to a central Harbour
 * installation.
 *
 * @link      https://digitalastronaut.be
 * @copyright Copyright (c) 2026 Digitalastronaut
 */

namespace digitalastronaut\craftbouy\migrations;

use craft\db\Migration;
use craft\helpers\MigrationHelper;

use digitalastronaut\craftbouy\helpers\Table;
use digitalastronaut\craftbouy\services\ResponseTimeService;

/**
 * Class m261125_100000_add_response_time_resolution
 *
 * Adds `resolutionMinutes` to `bouy_response_time_buckets` so raw, hourly,
 * and daily rollup rows can coexist in the same table (see
 * `ResponseTimeService::rollupRawToHourly()`/`rollupHourlyToDaily()`).
 * Existing rows default to `BUCKET_MINUTES` — they're all raw today.
 *
 * The old unique index on `bucketStart` alone has to become a composite
 * index on `(bucketStart, resolutionMinutes)`: an hourly bucket and a daily
 * bucket can share the same clock-aligned `bucketStart` (e.g. both start at
 * midnight), so `bucketStart` alone can no longer identify a row.
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class m261125_100000_add_response_time_resolution extends Migration {
    public function safeUp(): bool {
        $this->addColumn(
            Table::BOUY_RESPONSE_TIME_BUCKETS,
            'resolutionMinutes',
            $this->integer()->notNull()->defaultValue(ResponseTimeService::BUCKET_MINUTES)->after('bucketStart'),
        );

        MigrationHelper::dropIndexIfExists(Table::BOUY_RESPONSE_TIME_BUCKETS, 'bucketStart', true, $this);
        $this->createIndex(null, Table::BOUY_RESPONSE_TIME_BUCKETS, ['bucketStart', 'resolutionMinutes'], true);

        return true;
    }

    public function safeDown(): bool {
        MigrationHelper::dropIndexIfExists(Table::BOUY_RESPONSE_TIME_BUCKETS, ['bucketStart', 'resolutionMinutes'], true, $this);
        $this->createIndex(null, Table::BOUY_RESPONSE_TIME_BUCKETS, 'bucketStart', true);

        $this->dropColumn(Table::BOUY_RESPONSE_TIME_BUCKETS, 'resolutionMinutes');

        return true;
    }
}
