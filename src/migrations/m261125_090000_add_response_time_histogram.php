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
use digitalastronaut\craftbouy\services\ResponseTimeService;

/**
 * Class m261125_090000_add_response_time_histogram
 *
 * Adds a fixed-width latency histogram to each response-time bucket —
 * one integer column per bin in `ResponseTimeService::BIN_EDGES_MS` —
 * so percentiles (P50/P95/P99) can be computed from real data instead
 * of only `min`/`max`/`avg`, which cannot reconstruct a distribution.
 * Bounded by bin count, not traffic, same as the rest of this table.
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class m261125_090000_add_response_time_histogram extends Migration {
    public function safeUp(): bool {
        $binCount = \count(ResponseTimeService::BIN_EDGES_MS) + 1;

        for ($i = 0; $i < $binCount; $i++) {
            $this->addColumn(Table::BOUY_RESPONSE_TIME_BUCKETS, "bin{$i}Count", $this->integer()->notNull()->defaultValue(0));
        }

        return true;
    }

    public function safeDown(): bool {
        $binCount = \count(ResponseTimeService::BIN_EDGES_MS) + 1;

        for ($i = 0; $i < $binCount; $i++) {
            $this->dropColumn(Table::BOUY_RESPONSE_TIME_BUCKETS, "bin{$i}Count");
        }

        return true;
    }
}
