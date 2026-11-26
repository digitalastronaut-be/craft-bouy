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
 * Class m261124_190000_add_code_preview_to_incidents
 *
 * Adds `codePreview` to `bouy_incidents` — a small line-numbered window
 * of source code around where the latest occurrence was thrown (see
 * `SourcePreviewService`). Stored as JSON, manually encoded/decoded by
 * `IncidentsService` rather than relying on automatic column casting —
 * same convention already used for other JSON columns in this codebase
 * (e.g. `qr_payments_paymenttypes.reminderRecipients`).
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class m261124_190000_add_code_preview_to_incidents extends Migration {
    public function safeUp(): bool {
        $this->addColumn(Table::BOUY_INCIDENTS, 'codePreview', $this->json()->null()->after('stackTrace'));

        return true;
    }

    public function safeDown(): bool {
        $this->dropColumn(Table::BOUY_INCIDENTS, 'codePreview');

        return true;
    }
}
