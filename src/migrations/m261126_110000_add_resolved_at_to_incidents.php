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
 * Class m261126_110000_add_resolved_at_to_incidents
 *
 * Adds `resolvedAt` to `bouy_incidents` — null means unresolved, a
 * timestamp means a human marked this fingerprint fixed via Harbour's new
 * "Resolve" action (`IncidentsController::actionResolve()`). Deliberately
 * a nullable timestamp rather than a boolean: `IncidentsService::
 * recordOccurrence()` clears it back to null on every new occurrence, so
 * "when" is never meaningful once reoccurrence flips it back — only
 * "resolved or not" is ever read — but a timestamp costs nothing extra
 * and keeps the door open for showing "resolved 3 days ago" later without
 * another migration.
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class m261126_110000_add_resolved_at_to_incidents extends Migration {
    public function safeUp(): bool {
        $this->addColumn(Table::BOUY_INCIDENTS, 'resolvedAt', $this->dateTime()->null()->after('lastOccurredAt'));

        return true;
    }

    public function safeDown(): bool {
        $this->dropColumn(Table::BOUY_INCIDENTS, 'resolvedAt');

        return true;
    }
}
