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
 * Class m261124_180000_add_stack_trace_to_incidents
 *
 * Adds `stackTrace` to `bouy_incidents` — the latest occurrence's full
 * trace (`Throwable::getTraceAsString()`), truncated the same way
 * `message` already is. Storing only the *latest* trace rather than one
 * per occurrence keeps this in line with the rest of the table: row
 * count stays bounded by distinct bugs, not by how many times each one
 * fired.
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class m261124_180000_add_stack_trace_to_incidents extends Migration {
    public function safeUp(): bool {
        $this->addColumn(Table::BOUY_INCIDENTS, 'stackTrace', $this->text()->null()->after('message'));

        return true;
    }

    public function safeDown(): bool {
        $this->dropColumn(Table::BOUY_INCIDENTS, 'stackTrace');

        return true;
    }
}
