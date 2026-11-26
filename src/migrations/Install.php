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
use digitalastronaut\craftbouy\services\IncidentsService;
use digitalastronaut\craftbouy\services\ResponseTimeService;

/**
 * Class Install
 *
 * Squashes what used to be twelve incremental migrations
 * (`m261124_140000_add_incidents` through
 * `m261126_120000_add_dependency_reports`) into the schema they'd built
 * up to by the end — this plugin predates any real install base to stay
 * backwards-compatible with, so there's no value in replaying that
 * history on every fresh install. A brand-new install only ever needs
 * today's schema; `Plugin::install()` runs this once, then marks every
 * *other* file still in `migrations/` as already applied without
 * running them — which is exactly how a site ended up with none of
 * these tables at all: there was no `Install.php` for it to run first.
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class Install extends Migration {
    public function safeUp(): bool {
        $this->createTables();
        $this->createForeignKeys();
        $this->createIndexes();

        return true;
    }

    public function safeDown(): bool {
        $this->dropForeignKeys();
        $this->dropTables();

        return true;
    }

    /**
     * @return void
     */
    public function createTables(): void {
        $this->createIncidentsTable();
        $this->createIncidentOccurrenceBucketsTable();
        $this->createResponseTimeBucketsTable();
        $this->createDependencyReportsTable();
    }

    /**
     * @return void
     */
    public function createForeignKeys(): void {
        $this->addForeignKey(null, Table::BOUY_INCIDENT_OCCURRENCE_BUCKETS, 'incidentId', Table::BOUY_INCIDENTS, 'id', 'CASCADE', null);
    }

    /**
     * @return void
     */
    public function createIndexes(): void {
        $this->createIndex(null, Table::BOUY_INCIDENTS, 'fingerprint', true);
        $this->createIndex(null, Table::BOUY_INCIDENTS, 'lastOccurredAt', false);

        $this->createIndex(null, Table::BOUY_INCIDENT_OCCURRENCE_BUCKETS, ['incidentId', 'bucketStart', 'resolutionMinutes'], true);

        $this->createIndex(null, Table::BOUY_RESPONSE_TIME_BUCKETS, ['bucketStart', 'resolutionMinutes'], true);
    }

    /**
     * @return void
     */
    public function dropTables(): void {
        $this->dropTableIfExists(Table::BOUY_DEPENDENCY_REPORTS);
        $this->dropTableIfExists(Table::BOUY_RESPONSE_TIME_BUCKETS);
        $this->dropTableIfExists(Table::BOUY_INCIDENT_OCCURRENCE_BUCKETS);
        $this->dropTableIfExists(Table::BOUY_INCIDENTS);
    }

    /**
     * @return void
     */
    public function dropForeignKeys(): void {
        $this->dropAllForeignKeysToTable(Table::BOUY_INCIDENT_OCCURRENCE_BUCKETS);
    }

    /**
     * One row per *distinct* error (grouped by exception class + file +
     * line, see `IncidentsService::fingerprint()`), not one row per
     * occurrence — `occurrenceCount` is incremented in place instead, a
     * structural fix for error storms (many visitors hitting the same
     * broken template) that would otherwise flood this table with a
     * duplicate row per hit. `stackTrace`/`codePreview` hold only the
     * *latest* occurrence's detail; `recentOccurrences` is a small
     * fixed-size ring buffer (`IncidentsService::RECENT_OCCURRENCES_LIMIT`
     * entries) of `{occurredAt, userAgent}` pairs for the most recent
     * real hits — both bounded regardless of traffic, same reasoning as
     * the table itself. `resolvedAt` is cleared back to null on every
     * new occurrence (`IncidentsService::recordOccurrence()`) — only
     * "resolved or not" is ever read, never "when".
     *
     * @return void
     */
    private function createIncidentsTable(): void {
        $this->createTable(Table::BOUY_INCIDENTS, [
            'id' => $this->primaryKey(),

            'fingerprint' => $this->string(64)->notNull(),
            'exceptionClass' => $this->string(255)->notNull(),
            'file' => $this->string(500)->notNull(),
            'line' => $this->integer()->notNull(),
            'message' => $this->string(500)->notNull(),
            'stackTrace' => $this->text()->null(),
            'codePreview' => $this->json()->null(),
            'statusCode' => $this->integer()->notNull(),
            'url' => $this->string(255)->null(),
            'requestMethod' => $this->string(10)->null(),
            'recentOccurrences' => $this->json()->null(),
            'occurrenceCount' => $this->integer()->notNull()->defaultValue(1),
            'firstOccurredAt' => $this->dateTime()->notNull(),
            'lastOccurredAt' => $this->dateTime()->notNull(),
            'resolvedAt' => $this->dateTime()->null(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    /**
     * One row per (incident, time bucket) pair at one of three
     * resolutions — minute-wide (raw), hourly, or daily
     * (`IncidentsService::DAILY_RESOLUTION_MINUTES`) — incrementing
     * `occurrenceCount` in place as rows roll up from one resolution to
     * the next (`IncidentsService::rollupMinuteToHourly()`/
     * `rollupHourlyToDaily()`), the same bounded-by-time shape
     * `bouy_response_time_buckets` uses for response times, applied
     * here to error frequency so Harbour can chart a trend per incident
     * (`IncidentsService::TREND_DAYS`-wide) without ever storing one row
     * per occurrence. Row count is bounded by distinct incidents ×
     * resolutions × that window — rows past it are pruned
     * (`pruneOldOccurrenceBuckets()`), never by traffic volume.
     *
     * @return void
     */
    private function createIncidentOccurrenceBucketsTable(): void {
        $this->createTable(Table::BOUY_INCIDENT_OCCURRENCE_BUCKETS, [
            'id' => $this->primaryKey(),

            'incidentId' => $this->integer()->notNull(),
            'bucketStart' => $this->dateTime()->notNull(),
            'resolutionMinutes' => $this->integer()->notNull()->defaultValue(IncidentsService::DAILY_RESOLUTION_MINUTES),
            'occurrenceCount' => $this->integer()->notNull()->defaultValue(0),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    /**
     * Real visitor response times, aggregated into fixed-width time
     * buckets at one of several resolutions
     * (`ResponseTimeService::BUCKET_MINUTES` raw, rolled up to hourly
     * then daily — `rollupRawToHourly()`/`rollupHourlyToDaily()`)
     * rather than stored one row per request: a bucket's row count is
     * bounded by *time*, never by the site's traffic volume. `bin{N}Count`
     * is a fixed-width latency histogram — one column per bin in
     * `ResponseTimeService::BIN_EDGES_MS` — so percentiles (P50/P90/P99)
     * can be computed from real data instead of only `min`/`max`/`avg`,
     * which can't reconstruct a distribution.
     *
     * @return void
     */
    private function createResponseTimeBucketsTable(): void {
        $columns = [
            'id' => $this->primaryKey(),

            'bucketStart' => $this->dateTime()->notNull(),
            'resolutionMinutes' => $this->integer()->notNull()->defaultValue(ResponseTimeService::BUCKET_MINUTES),
            'sampleCount' => $this->integer()->notNull()->defaultValue(0),
            'totalMs' => $this->bigInteger()->notNull()->defaultValue(0),
            'minMs' => $this->integer()->notNull(),
            'maxMs' => $this->integer()->notNull(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ];

        foreach (range(0, \count(ResponseTimeService::BIN_EDGES_MS)) as $i) {
            $columns["bin{$i}Count"] = $this->integer()->notNull()->defaultValue(0);
        }

        $this->createTable(Table::BOUY_RESPONSE_TIME_BUCKETS, $columns);
    }

    /**
     * A single-row cache (always `id = 1`) of the last composer/npm
     * dependency audit — installed packages, OSV vulnerability matches,
     * and latest-version lookups for direct dependencies. A dedicated
     * table rather than Craft's data cache, since that can be flushed
     * (`cache/flush-all`) independently of this plugin and shouldn't
     * silently leave `PackagesService::getReport()` with nothing to
     * serve right as Harbour happens to poll.
     *
     * @return void
     */
    private function createDependencyReportsTable(): void {
        $this->createTable(Table::BOUY_DEPENDENCY_REPORTS, [
            'id' => $this->primaryKey(),

            'report' => $this->json()->null(),
            'refreshedAt' => $this->dateTime()->null(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }
}
