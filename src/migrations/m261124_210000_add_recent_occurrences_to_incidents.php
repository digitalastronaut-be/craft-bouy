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
 * Class m261124_210000_add_recent_occurrences_to_incidents
 *
 * Adds `recentOccurrences` to `bouy_incidents`: a small, fixed-size ring
 * buffer (`IncidentsService::RECENT_OCCURRENCES_LIMIT` entries) of
 * `{occurredAt, userAgent}` pairs for the most recent real hits, so
 * Harbour can show per-request detail without a row-per-occurrence table —
 * the same volume problem `bouy_incidents` itself was already introduced
 * to fix, just one layer deeper. Oldest entries are dropped as new ones
 * arrive, so this column's size is bounded regardless of traffic.
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class m261124_210000_add_recent_occurrences_to_incidents extends Migration {
    public function safeUp(): bool {
        $this->addColumn(Table::BOUY_INCIDENTS, 'recentOccurrences', $this->json()->null()->after('requestMethod'));

        return true;
    }

    public function safeDown(): bool {
        $this->dropColumn(Table::BOUY_INCIDENTS, 'recentOccurrences');

        return true;
    }
}
