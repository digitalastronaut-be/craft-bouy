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
 * Class m261124_200000_add_request_method_to_incidents
 *
 * Adds `requestMethod` to `bouy_incidents` — the latest occurrence's HTTP
 * method (`GET`, `POST`, etc.), `null` for a console-originated error, same
 * reasoning `url` is already nullable for. Harbour's incident detail page
 * shows this alongside the URL path.
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class m261124_200000_add_request_method_to_incidents extends Migration {
    public function safeUp(): bool {
        $this->addColumn(Table::BOUY_INCIDENTS, 'requestMethod', $this->string(10)->null()->after('url'));

        return true;
    }

    public function safeDown(): bool {
        $this->dropColumn(Table::BOUY_INCIDENTS, 'requestMethod');

        return true;
    }
}
