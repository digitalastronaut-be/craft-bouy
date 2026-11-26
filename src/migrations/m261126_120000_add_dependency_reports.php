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
 * Class m261126_120000_add_dependency_reports
 *
 * Introduces `bouy_dependency_reports`: a single-row cache (always `id = 1`)
 * of the last composer/npm dependency audit — installed packages, OSV
 * vulnerability matches, and latest-version lookups for direct dependencies.
 * A dedicated table rather than Craft's data cache, since that can be
 * flushed (`cache/flush-all`) independently of this plugin and shouldn't
 * silently leave `PackagesService::getReport()` with nothing to serve right
 * as Harbour happens to poll. See `PackagesService::refresh()`, the only
 * place this table is ever written, run from a daily cron
 * (`MaintenanceController::actionRefreshDependencies()`) rather than from a
 * web request.
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class m261126_120000_add_dependency_reports extends Migration {
    public function safeUp(): bool {
        $this->createTable(Table::BOUY_DEPENDENCY_REPORTS, [
            'id' => $this->primaryKey(),

            'report' => $this->json()->null(),
            'refreshedAt' => $this->dateTime()->null(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        return true;
    }

    public function safeDown(): bool {
        $this->dropTableIfExists(Table::BOUY_DEPENDENCY_REPORTS);

        return true;
    }
}
