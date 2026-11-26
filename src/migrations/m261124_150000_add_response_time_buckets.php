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
 * Class m261124_150000_add_response_time_buckets
 *
 * Introduces `bouy_response_time_buckets`: real visitor response times,
 * aggregated into fixed-width time buckets (`ResponseTimeService::
 * BUCKET_MINUTES`) rather than stored one row per request — a bucket's
 * row count is bounded by *time*, never by the site's traffic volume, so
 * a busy site generates the same handful of rows per day as a quiet one.
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class m261124_150000_add_response_time_buckets extends Migration {
    public function safeUp(): bool {
        $this->createTable(Table::BOUY_RESPONSE_TIME_BUCKETS, [
            'id' => $this->primaryKey(),

            'bucketStart' => $this->dateTime()->notNull(),
            'sampleCount' => $this->integer()->notNull()->defaultValue(0),
            'totalMs' => $this->bigInteger()->notNull()->defaultValue(0),
            'minMs' => $this->integer()->notNull(),
            'maxMs' => $this->integer()->notNull(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::BOUY_RESPONSE_TIME_BUCKETS, 'bucketStart', true);

        return true;
    }

    public function safeDown(): bool {
        $this->dropTableIfExists(Table::BOUY_RESPONSE_TIME_BUCKETS);

        return true;
    }
}
