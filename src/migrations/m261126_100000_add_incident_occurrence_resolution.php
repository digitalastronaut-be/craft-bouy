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
use digitalastronaut\craftbouy\services\IncidentsService;

/**
 * Class m261126_100000_add_incident_occurrence_resolution
 *
 * Adds `resolutionMinutes` to `bouy_incident_occurrence_buckets` so raw
 * (minute-wide), hourly, and daily rollup rows can coexist in the same
 * table — the same tiered shape
 * `m261125_100000_add_response_time_resolution` already added to
 * `bouy_response_time_buckets`, see `IncidentsService::rollupMinuteToHourly()`/
 * `rollupHourlyToDaily()`. Existing rows default to `DAILY_RESOLUTION_MINUTES`
 * — they're all daily today.
 *
 * The old unique index on `(incidentId, bucketStart)` has to become a
 * composite index on `(incidentId, bucketStart, resolutionMinutes)`: a
 * minute, hourly, and daily bucket for the same incident can share a
 * clock-aligned `bucketStart` (e.g. all three can start at midnight), so
 * `(incidentId, bucketStart)` alone can no longer identify a row.
 *
 * Unlike `bouy_response_time_buckets` (a global table with no foreign
 * keys, where the equivalent index swap in
 * `m261125_100000_add_response_time_resolution` was a plain drop +
 * create), `incidentId` here backs a foreign key to `bouy_incidents` —
 * MySQL refuses to drop the index it's relying on ("needed in a foreign
 * key constraint"), so that FK has to be dropped first and restored
 * after, around the index swap.
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class m261126_100000_add_incident_occurrence_resolution extends Migration {
    public function safeUp(): bool {
        $this->addColumn(
            Table::BOUY_INCIDENT_OCCURRENCE_BUCKETS,
            'resolutionMinutes',
            $this->integer()->notNull()->defaultValue(IncidentsService::DAILY_RESOLUTION_MINUTES)->after('bucketStart'),
        );

        MigrationHelper::dropForeignKeyIfExists(Table::BOUY_INCIDENT_OCCURRENCE_BUCKETS, 'incidentId', $this);
        MigrationHelper::dropIndexIfExists(Table::BOUY_INCIDENT_OCCURRENCE_BUCKETS, ['incidentId', 'bucketStart'], true, $this);

        $this->createIndex(null, Table::BOUY_INCIDENT_OCCURRENCE_BUCKETS, ['incidentId', 'bucketStart', 'resolutionMinutes'], true);
        $this->addForeignKey(null, Table::BOUY_INCIDENT_OCCURRENCE_BUCKETS, 'incidentId', Table::BOUY_INCIDENTS, 'id', 'CASCADE', null);

        return true;
    }

    public function safeDown(): bool {
        MigrationHelper::dropForeignKeyIfExists(Table::BOUY_INCIDENT_OCCURRENCE_BUCKETS, 'incidentId', $this);
        MigrationHelper::dropIndexIfExists(Table::BOUY_INCIDENT_OCCURRENCE_BUCKETS, ['incidentId', 'bucketStart', 'resolutionMinutes'], true, $this);

        $this->createIndex(null, Table::BOUY_INCIDENT_OCCURRENCE_BUCKETS, ['incidentId', 'bucketStart'], true);
        $this->addForeignKey(null, Table::BOUY_INCIDENT_OCCURRENCE_BUCKETS, 'incidentId', Table::BOUY_INCIDENTS, 'id', 'CASCADE', null);

        $this->dropColumn(Table::BOUY_INCIDENT_OCCURRENCE_BUCKETS, 'resolutionMinutes');

        return true;
    }
}
