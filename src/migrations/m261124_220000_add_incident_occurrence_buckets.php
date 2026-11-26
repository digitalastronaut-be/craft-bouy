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

use digitalastronaut\craftbouy\helpers\Table;

/**
 * Class m261124_220000_add_incident_occurrence_buckets
 *
 * Introduces `bouy_incident_occurrence_buckets`: one row per
 * (incident, day) pair, incrementing `occurrenceCount` in place — the
 * same bounded-by-time shape `bouy_response_time_buckets` already uses,
 * applied to error frequency so Harbour can chart a trend per incident
 * (currently `IncidentsService::TREND_DAYS`-wide) without ever storing
 * one row per occurrence. Row count is
 * bounded by distinct incidents times `IncidentsService::TREND_DAYS`
 * (rows past that window are pruned), never by traffic volume.
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class m261124_220000_add_incident_occurrence_buckets extends Migration {
    public function safeUp(): bool {
        $this->createTable(Table::BOUY_INCIDENT_OCCURRENCE_BUCKETS, [
            'id' => $this->primaryKey(),

            'incidentId' => $this->integer()->notNull(),
            'bucketStart' => $this->dateTime()->notNull(),
            'occurrenceCount' => $this->integer()->notNull()->defaultValue(0),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::BOUY_INCIDENT_OCCURRENCE_BUCKETS, ['incidentId', 'bucketStart'], true);

        $this->addForeignKey(null, Table::BOUY_INCIDENT_OCCURRENCE_BUCKETS, 'incidentId', Table::BOUY_INCIDENTS, 'id', 'CASCADE', null);

        return true;
    }

    public function safeDown(): bool {
        $this->dropAllForeignKeysToTable(Table::BOUY_INCIDENT_OCCURRENCE_BUCKETS);
        $this->dropTableIfExists(Table::BOUY_INCIDENT_OCCURRENCE_BUCKETS);

        return true;
    }
}
