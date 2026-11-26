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
 * Class Incident
 *
 * One row per distinct error (grouped by `fingerprint` — exception class
 * + file + line, never by message content). `IncidentsService::
 * recordOccurrence()` is the only place rows here are ever written,
 * via a single atomic upsert rather than a read-then-save cycle.
 *
 * @property int $id
 * @property string $fingerprint
 * @property string $exceptionClass
 * @property string $file
 * @property int $line
 * @property string $message
 * @property string|null $stackTrace
 * @property string|null $codePreview JSON-encoded; see `SourcePreviewService::buildPreview()`
 * @property int $statusCode
 * @property string|null $url
 * @property string|null $requestMethod
 * @property array<int, array{occurredAt: string, userAgent: string|null}>|null $recentOccurrences JSON-encoded ring buffer; see `IncidentsService::RECENT_OCCURRENCES_LIMIT`
 * @property int $occurrenceCount
 * @property string $firstOccurredAt
 * @property string $lastOccurredAt
 * @property string|null $resolvedAt Null means unresolved; see `IncidentsService::setResolved()`.
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class Incident extends ActiveRecord {
    /**
     * @inheritdoc
     */
    public static function tableName(): string {
        return Table::BOUY_INCIDENTS;
    }
}
