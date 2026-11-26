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
 * Class m261124_140000_add_incidents
 *
 * Introduces `bouy_incidents`: one row per *distinct* error (grouped by
 * exception class + file + line, see `IncidentsService::fingerprint()`),
 * not one row per occurrence — `occurrenceCount` is incremented in place
 * instead. This is the structural fix for error storms (many visitors
 * hitting the same broken template) that used to flood Harbour with a
 * duplicate row per hit.
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class m261124_140000_add_incidents extends Migration {
    public function safeUp(): bool {
        $this->createTable(Table::BOUY_INCIDENTS, [
            'id' => $this->primaryKey(),

            'fingerprint' => $this->string(64)->notNull(),
            'exceptionClass' => $this->string(255)->notNull(),
            'file' => $this->string(500)->notNull(),
            'line' => $this->integer()->notNull(),
            'message' => $this->string(500)->notNull(),
            'statusCode' => $this->integer()->notNull(),
            'url' => $this->string(255)->null(),
            'occurrenceCount' => $this->integer()->notNull()->defaultValue(1),
            'firstOccurredAt' => $this->dateTime()->notNull(),
            'lastOccurredAt' => $this->dateTime()->notNull(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::BOUY_INCIDENTS, 'fingerprint', true);
        $this->createIndex(null, Table::BOUY_INCIDENTS, 'lastOccurredAt', false);

        return true;
    }

    public function safeDown(): bool {
        $this->dropTableIfExists(Table::BOUY_INCIDENTS);

        return true;
    }
}
