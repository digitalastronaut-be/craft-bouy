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

namespace digitalastronaut\craftbouy\records;

use craft\db\ActiveRecord;

use digitalastronaut\craftbouy\helpers\Table;

/**
 * Class IncidentOccurrenceBucket
 *
 * One row per `(incidentId, bucketStart, resolutionMinutes)`, counting how
 * many times that incident fired in that window. Three tiers share this
 * table — minute-wide (raw), hourly, and daily — promoted into each other
 * by `IncidentsService::rollupMinuteToHourly()`/`rollupHourlyToDaily()` as
 * they age; `IncidentsService::recordOccurrence()` is the only place a
 * fresh (minute-resolution) row is ever written, via a single atomic
 * upsert.
 *
 * @property int $id
 * @property int $incidentId
 * @property string $bucketStart
 * @property int $resolutionMinutes
 * @property int $occurrenceCount
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class IncidentOccurrenceBucket extends ActiveRecord {
    /**
     * @inheritdoc
     */
    public static function tableName(): string {
        return Table::BOUY_INCIDENT_OCCURRENCE_BUCKETS;
    }
}
