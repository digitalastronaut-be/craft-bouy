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
 * Class ResponseTimeBucket
 *
 * One row per fixed-width time window (see `ResponseTimeService::
 * BUCKET_MINUTES`), aggregating every real site request that finished
 * inside it. `ResponseTimeService::recordRequest()` is the only place
 * rows here are ever written, via a single atomic upsert.
 *
 * @property int $id
 * @property string $bucketStart
 * @property int $resolutionMinutes
 * @property int $sampleCount
 * @property int $totalMs
 * @property int $minMs
 * @property int $maxMs
 * @property int $bin0Count
 * @property int $bin1Count
 * @property int $bin2Count
 * @property int $bin3Count
 * @property int $bin4Count
 * @property int $bin5Count
 * @property int $bin6Count
 * @property int $bin7Count
 * @property int $bin8Count
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class ResponseTimeBucket extends ActiveRecord {
    /**
     * @inheritdoc
     */
    public static function tableName(): string {
        return Table::BOUY_RESPONSE_TIME_BUCKETS;
    }
}
