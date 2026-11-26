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

namespace digitalastronaut\craftbouy\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use craft\helpers\DateTimeHelper;

use digitalastronaut\craftbouy\Bouy;
use digitalastronaut\craftbouy\helpers\Table;
use digitalastronaut\craftbouy\records\Incident;
use digitalastronaut\craftbouy\records\IncidentOccurrenceBucket;

use DateTime;
use DateTimeZone;
use Throwable;
use yii\db\Expression;
use yii\db\JsonExpression;

/**
 * Class IncidentsService
 *
 * Groups errors by *where they were thrown* (exception class + file +
 * line), not by message content — robust against dynamic message
 * content (a different user ID, a different missing file name) without
 * needing any message-normalization heuristics. One row per distinct
 * fingerprint, with `occurrenceCount` incremented in place, so a storm
 * of visitors hitting the same broken template produces one incident,
 * not one row per hit.
 *
 * Per-request detail (user agent, timestamp) is kept at two bounded
 * granularities rather than one row per occurrence, the same two-layer
 * shape Sentry itself uses (a cheap aggregate "Issue" plus sampled
 * event detail):
 *  - `recentOccurrences`: a fixed-size ring buffer of the most recent
 *    `RECENT_OCCURRENCES_LIMIT` hits, for "what does this look like
 *    right now" debugging.
 *  - `bouy_incident_occurrence_buckets`: one row per (incident, window),
 *    a plain counter, bounded by distinct incidents times however many
 *    windows exist across its tiers, never by traffic volume. Three
 *    tiers share the table — see `rollupMinuteToHourly()`/
 *    `rollupHourlyToDaily()` — a minute-wide raw tier (so `statusFor()`
 *    can see a spike within minutes of it starting, not just once an
 *    hour-wide bucket fills up), rolled up into an hourly tier, rolled
 *    up again into the daily tier the `TREND_DAYS`-wide chart reads.
 *
 * Beyond the trend chart, this also backs a per-incident activity
 * `status` (see `statusFor()`) — new/escalating/quiet/declining/ongoing —
 * computed fresh on every `getReport()` rather than stored, the same way
 * `occurrenceTrend` already is.
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class IncidentsService extends Component {
    private const LIMIT = 50;
    private const PRUNE_AFTER_DAYS = 90;
    private const STACK_TRACE_MAX_LENGTH = 4000;
    private const RECENT_OCCURRENCES_LIMIT = 20;
    public const TREND_DAYS = 21;

    /**
     * Occurrence-bucket resolution tiers — see the class docblock.
     * `RAW_RETENTION_MINUTES`/`HOURLY_RETENTION_HOURS` are how old a row
     * at that tier gets before `rollupMinuteToHourly()`/
     * `rollupHourlyToDaily()` promotes it; the daily tier is never rolled
     * up further, only pruned past `TREND_DAYS` (`pruneOldOccurrenceBuckets()`).
     */
    private const RAW_RESOLUTION_MINUTES = 1;
    private const HOURLY_RESOLUTION_MINUTES = 60;
    public const DAILY_RESOLUTION_MINUTES = 1440;
    private const RAW_RETENTION_MINUTES = 30;
    private const HOURLY_RETENTION_HOURS = 72;

    public const STATUS_NEW = 'new';
    public const STATUS_ESCALATING = 'escalating';
    public const STATUS_QUIET = 'quiet';
    public const STATUS_DECLINING = 'declining';
    public const STATUS_ONGOING = 'ongoing';

    /**
     * Thresholds `statusFor()` classifies against — see that method for
     * what each one means.
     */
    private const NEW_WITHIN_HOURS = 24;
    private const QUIET_AFTER_HOURS = 48;
    private const RECENT_WINDOW_MINUTES = 15;
    private const BASELINE_WINDOW_DAYS = 7;
    private const ESCALATION_MULTIPLIER = 3;
    private const ESCALATION_MIN_COUNT = 5;
    private const DECLINE_RATIO = 1 / 3;

    /**
     * Records one occurrence of `$exception`, grouped by its fingerprint.
     * The existence check and the upsert aren't wrapped in one atomic
     * statement — a rare double "new incident" result under true
     * concurrency is harmless here, since the only consequence is
     * Harbour polling again for data it already has.
     *
     * @param Throwable $exception
     * @param int $statusCode
     * @param string|null $url
     * @param string|null $requestMethod
     * @param string|null $userAgent
     * @return bool Whether this is the first time this fingerprint has
     * been seen — callers only need to notify Harbour in that case.
     */
    public function recordOccurrence(Throwable $exception, int $statusCode, ?string $url, ?string $requestMethod = null, ?string $userAgent = null): bool {
        $fingerprint = $this->fingerprint($exception);
        $existing = Incident::find()->where(['fingerprint' => $fingerprint])->one();
        $isNew = $existing === null;
        $occurredAt = DateTimeHelper::currentUTCDateTime();
        $occurredAtForDb = Db::prepareDateForDb($occurredAt);
        $message = mb_substr($exception->getMessage(), 0, 500);
        $stackTrace = mb_substr($exception->getTraceAsString(), 0, self::STACK_TRACE_MAX_LENGTH);
        $codePreview = Bouy::getInstance()->getSourcePreview()->buildPreview($exception);
        $recentOccurrences = $this->appendRecentOccurrence($existing?->recentOccurrences ?? [], $occurredAt, $userAgent);
        // Wrapped explicitly rather than handed to `upsert()` as plain
        // arrays: the query builder's automatic json-column typecast
        // (which would otherwise wrap these) only reliably fires on an
        // `insert()`'s own column list, not on `upsert()`'s separate
        // `ON DUPLICATE KEY UPDATE` column list — leaving a raw PHP array
        // bound as a parameter there, which later crashes `getRawSql()`
        // (query logging, run on every `execute()`) with "Array to string
        // conversion" the moment it tries to inline that value into the
        // logged SQL. `JsonExpression` builds its own bound placeholder
        // instead, so this never reaches that code path.
        $codePreviewParam = $codePreview !== null ? new JsonExpression($codePreview) : null;
        $recentOccurrencesParam = new JsonExpression($recentOccurrences);

        Craft::$app->getDb()->createCommand()->upsert(
            Table::BOUY_INCIDENTS,
            [
                'fingerprint' => $fingerprint,
                'exceptionClass' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'message' => $message,
                'stackTrace' => $stackTrace,
                'codePreview' => $codePreviewParam,
                'statusCode' => $statusCode,
                'url' => $url,
                'requestMethod' => $requestMethod,
                'recentOccurrences' => $recentOccurrencesParam,
                'occurrenceCount' => 1,
                'firstOccurredAt' => $occurredAtForDb,
                'lastOccurredAt' => $occurredAtForDb,
            ],
            [
                'occurrenceCount' => new Expression('[[occurrenceCount]] + 1'),
                'message' => $message,
                'stackTrace' => $stackTrace,
                'codePreview' => $codePreviewParam,
                'url' => $url,
                'requestMethod' => $requestMethod,
                'recentOccurrences' => $recentOccurrencesParam,
                'lastOccurredAt' => $occurredAtForDb,
                // A fresh occurrence always means this is happening again —
                // auto-unresolves a fingerprint a human previously marked
                // fixed via `setResolved()`, the same way Sentry and
                // similar error trackers behave.
                'resolvedAt' => null,
            ],
        )->execute();

        $incidentId = $existing?->id ?? (int) Incident::find()->select(['id'])->where(['fingerprint' => $fingerprint])->scalar();

        $this->incrementOccurrenceBucket($incidentId, $occurredAt);

        return $isNew;
    }

    /**
     * @return array<int, array{fingerprint: string, exceptionClass: string, file: string, line: int, message: string, stackTrace: string|null, codePreview: array|null, statusCode: int, url: string|null, requestMethod: string|null, occurrenceCount: int, firstOccurredAt: string, lastOccurredAt: string, resolvedAt: string|null, recentOccurrences: array<int, array{occurredAt: string, userAgent: string|null}>, occurrenceTrend: array<int, array{date: string, count: int}>, status: string}>
     */
    public function getReport(): array {
        $incidents = Incident::find()
            ->orderBy(['lastOccurredAt' => SORT_DESC])
            ->limit(self::LIMIT)
            ->all();

        $incidentIds = array_map(static fn(Incident $incident): int => $incident->id, $incidents);
        $trends = $this->occurrenceTrends($incidentIds);
        $recentCounts = $this->recentOccurrenceCounts($incidentIds);

        return array_map(fn(Incident $incident): array => [
            'fingerprint' => $incident->fingerprint,
            'exceptionClass' => $incident->exceptionClass,
            'file' => $incident->file,
            'line' => $incident->line,
            'message' => $incident->message,
            'stackTrace' => $incident->stackTrace,
            'codePreview' => $incident->codePreview,
            'statusCode' => $incident->statusCode,
            'url' => $incident->url,
            'requestMethod' => $incident->requestMethod,
            'occurrenceCount' => $incident->occurrenceCount,
            'firstOccurredAt' => $incident->firstOccurredAt,
            'lastOccurredAt' => $incident->lastOccurredAt,
            'resolvedAt' => $incident->resolvedAt,
            // Newest first — the ring buffer itself is appended oldest to
            // newest, but a human reading "recent requests" wants the
            // latest hit at the top.
            'recentOccurrences' => array_reverse($incident->recentOccurrences ?? []),
            'occurrenceTrend' => $trends[$incident->id],
            'status' => $this->statusFor($incident, $recentCounts[$incident->id] ?? 0, $trends[$incident->id]),
        ], $incidents);
    }

    /**
     * Marks a fingerprint resolved or unresolved — the only place
     * `resolvedAt` is ever written from outside `recordOccurrence()`'s own
     * auto-unresolve-on-reoccurrence. Called from `IncidentsController::
     * actionResolve()`, Harbour's one write call against Bouy.
     *
     * @param string $fingerprint
     * @param bool $resolved
     * @return bool Whether a matching incident was found.
     */
    public function setResolved(string $fingerprint, bool $resolved): bool {
        $incident = Incident::find()->where(['fingerprint' => $fingerprint])->one();

        if ($incident === null) return false;

        $incident->resolvedAt = $resolved ? Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime()) : null;

        return $incident->save(false);
    }

    /**
     * @return int Number of pruned rows.
     */
    public function pruneOld(): int {
        $cutoff = Db::prepareDateForDb(
            DateTimeHelper::currentUTCDateTime()->modify('-' . self::PRUNE_AFTER_DAYS . ' days')
        );

        return Incident::deleteAll(['<', 'lastOccurredAt', $cutoff]);
    }

    /**
     * Drops occurrence-bucket rows older than `TREND_DAYS` — independent
     * of `pruneOld()`'s 90-day incident cutoff, since nothing ever reads
     * a bucket outside the trend chart's own window, even for an incident
     * that's still active.
     *
     * @return int Number of pruned rows.
     */
    public function pruneOldOccurrenceBuckets(): int {
        $cutoff = Db::prepareDateForDb(
            $this->floorToResolution(DateTimeHelper::currentUTCDateTime(), self::DAILY_RESOLUTION_MINUTES)->modify('-' . self::TREND_DAYS . ' days')
        );

        return IncidentOccurrenceBucket::deleteAll(['<', 'bucketStart', $cutoff]);
    }

    /**
     * Merges raw (minute-wide) buckets older than `RAW_RETENTION_MINUTES`
     * into hourly buckets, one per enclosing hour per incident. Intended
     * for a frequent (e.g. every 5 minutes) cron, separate from
     * `rollupHourlyToDaily()`'s/`pruneOld()`'s daily one — `RAW_RETENTION_MINUTES`
     * is far too short for a daily cadence to keep this tier bounded (see
     * `MaintenanceController::actionRollupRecent()`).
     *
     * @return int Number of raw rows consumed.
     */
    public function rollupMinuteToHourly(): int {
        $cutoff = Db::prepareDateForDb(
            DateTimeHelper::currentUTCDateTime()->modify('-' . self::RAW_RETENTION_MINUTES . ' minutes')
        );

        return $this->rollup(self::RAW_RESOLUTION_MINUTES, self::HOURLY_RESOLUTION_MINUTES, $cutoff);
    }

    /**
     * Merges hourly buckets older than `HOURLY_RETENTION_HOURS` into daily
     * buckets, one per enclosing UTC day per incident. Daily buckets are
     * never rolled up further, only pruned past `TREND_DAYS`
     * (`pruneOldOccurrenceBuckets()`).
     *
     * @return int Number of hourly rows consumed.
     */
    public function rollupHourlyToDaily(): int {
        $cutoff = Db::prepareDateForDb(
            DateTimeHelper::currentUTCDateTime()->modify('-' . self::HOURLY_RETENTION_HOURS . ' hours')
        );

        return $this->rollup(self::HOURLY_RESOLUTION_MINUTES, self::DAILY_RESOLUTION_MINUTES, $cutoff);
    }

    /**
     * Merges every `$fromResolution` row older than `$cutoff` into coarser
     * `$toResolution` rows, grouped by incident and by their enclosing
     * `$toResolution`-wide window — same arithmetic as
     * `ResponseTimeService::rollup()`, just summing one plain counter
     * instead of merging ms stats/histograms, and grouped per-incident
     * since (unlike response time) these buckets aren't global. One
     * transaction per (incident, window), so an interrupted run just
     * leaves the remaining ones for next time rather than losing or
     * double-counting anything.
     *
     * @param int $fromResolution
     * @param int $toResolution
     * @param string $cutoff
     * @return int Number of `$fromResolution` rows consumed.
     */
    private function rollup(int $fromResolution, int $toResolution, string $cutoff): int {
        $rows = IncidentOccurrenceBucket::find()
            ->where(['resolutionMinutes' => $fromResolution])
            ->andWhere(['<', 'bucketStart', $cutoff])
            ->all();

        $rowsByIncidentAndWindow = [];
        foreach ($rows as $row) {
            $windowStart = $this->windowStartForRow($row, $toResolution);
            $rowsByIncidentAndWindow[$row->incidentId][$windowStart][] = $row;
        }

        $consumed = 0;

        foreach ($rowsByIncidentAndWindow as $incidentId => $windowsForIncident) {
            foreach ($windowsForIncident as $windowStart => $windowRows) {
                Craft::$app->getDb()->transaction(function() use ($incidentId, $windowStart, $toResolution, $windowRows): void {
                    $this->mergeIntoBucket((int) $incidentId, $windowStart, $toResolution, $windowRows);

                    IncidentOccurrenceBucket::deleteAll(['id' => array_map(static fn(IncidentOccurrenceBucket $row): int => $row->id, $windowRows)]);
                });

                $consumed += \count($windowRows);
            }
        }

        return $consumed;
    }

    /**
     * Upserts the merge of `$rows` as a single `$resolutionMinutes` row
     * starting at `$bucketStart` for `$incidentId` — adding into whatever's
     * already there for that window rather than overwriting it, the same
     * read-modify-write-free shape `incrementOccurrenceBucket()` uses.
     *
     * @param int $incidentId
     * @param string $bucketStart
     * @param int $resolutionMinutes
     * @param IncidentOccurrenceBucket[] $rows
     * @return void
     */
    private function mergeIntoBucket(int $incidentId, string $bucketStart, int $resolutionMinutes, array $rows): void {
        $occurrenceCount = array_sum(array_map(static fn(IncidentOccurrenceBucket $row): int => $row->occurrenceCount, $rows));

        Craft::$app->getDb()->createCommand()->upsert(
            Table::BOUY_INCIDENT_OCCURRENCE_BUCKETS,
            [
                'incidentId' => $incidentId,
                'bucketStart' => $bucketStart,
                'resolutionMinutes' => $resolutionMinutes,
                'occurrenceCount' => $occurrenceCount,
            ],
            [
                'occurrenceCount' => new Expression('[[occurrenceCount]] + :occurrenceCount', [':occurrenceCount' => $occurrenceCount]),
            ],
        )->execute();
    }

    /**
     * Floors `$row`'s `bucketStart` down to the start of its enclosing
     * `$toResolution`-wide window. Parsed with an explicit UTC timezone
     * (never `DateTimeHelper::toDateTime()`, which defaults to converting
     * into the system timezone) — `bucketStart` is always stored in UTC,
     * so this never risks shifting a row into the wrong window.
     *
     * @param IncidentOccurrenceBucket $row
     * @param int $toResolution
     * @return string
     */
    private function windowStartForRow(IncidentOccurrenceBucket $row, int $toResolution): string {
        $bucketStart = new DateTime($row->bucketStart, new DateTimeZone('UTC'));

        return Db::prepareDateForDb($this->floorToResolution($bucketStart, $toResolution));
    }

    /**
     * @param Throwable $exception
     * @return string
     */
    private function fingerprint(Throwable $exception): string {
        return sha1($exception::class . '|' . $exception->getFile() . '|' . $exception->getLine());
    }

    /**
     * @param array<int, array{occurredAt: string, userAgent: string|null}> $recentOccurrences
     * @param DateTime $occurredAt
     * @param string|null $userAgent
     * @return array<int, array{occurredAt: string, userAgent: string|null}>
     */
    private function appendRecentOccurrence(array $recentOccurrences, DateTime $occurredAt, ?string $userAgent): array {
        $recentOccurrences[] = [
            'occurredAt' => $occurredAt->format(DateTime::ATOM),
            'userAgent' => $userAgent !== null ? mb_substr($userAgent, 0, 300) : null,
        ];

        return array_slice($recentOccurrences, -self::RECENT_OCCURRENCES_LIMIT);
    }

    /**
     * Always writes at the raw (minute) tier — `rollupMinuteToHourly()`/
     * `rollupHourlyToDaily()` promote it to coarser tiers later, the same
     * "always record at the finest resolution" shape
     * `ResponseTimeService::recordRequest()` uses.
     *
     * @param int $incidentId
     * @param DateTime $occurredAt
     * @return void
     */
    private function incrementOccurrenceBucket(int $incidentId, DateTime $occurredAt): void {
        $bucketStart = Db::prepareDateForDb($this->floorToResolution($occurredAt, self::RAW_RESOLUTION_MINUTES));

        Craft::$app->getDb()->createCommand()->upsert(
            Table::BOUY_INCIDENT_OCCURRENCE_BUCKETS,
            [
                'incidentId' => $incidentId,
                'bucketStart' => $bucketStart,
                'resolutionMinutes' => self::RAW_RESOLUTION_MINUTES,
                'occurrenceCount' => 1,
            ],
            [
                'occurrenceCount' => new Expression('[[occurrenceCount]] + 1'),
            ],
        )->execute();
    }

    /**
     * One query across every incident on the report, rather than one
     * per incident — `bucketStart` is indexed alongside `incidentId`, so
     * this stays cheap even at the full `LIMIT` of 50 incidents. Reads
     * across every resolution tier with no filter: at any moment, a given
     * occurrence lives in exactly one tier's row (rollups delete the rows
     * they consume), so summing whatever's there for a calendar day is
     * correct regardless of whether that day has already been rolled up
     * to daily, is still sitting as hourly rows, or (for today) raw ones.
     *
     * @param int[] $incidentIds
     * @return array<int, array<int, array{date: string, count: int}>> Keyed by incident id.
     */
    private function occurrenceTrends(array $incidentIds): array {
        $incidentIds = array_values(array_unique(array_filter($incidentIds)));

        if (empty($incidentIds)) return [];

        $since = Db::prepareDateForDb(
            $this->floorToResolution(DateTimeHelper::currentUTCDateTime(), self::DAILY_RESOLUTION_MINUTES)->modify('-' . (self::TREND_DAYS - 1) . ' days')
        );

        $buckets = IncidentOccurrenceBucket::find()
            ->where(['incidentId' => $incidentIds])
            ->andWhere(['>=', 'bucketStart', $since])
            ->all();

        $countsByIncident = [];

        foreach ($buckets as $bucket) {
            $date = mb_substr($bucket->bucketStart, 0, 10);
            $countsByIncident[$bucket->incidentId][$date] = ($countsByIncident[$bucket->incidentId][$date] ?? 0) + $bucket->occurrenceCount;
        }

        $trends = [];

        foreach ($incidentIds as $incidentId) {
            $trends[$incidentId] = $this->zeroFilledTrend($countsByIncident[$incidentId] ?? []);
        }

        return $trends;
    }

    /**
     * @param array<string, int> $countsByDate Keyed by `Y-m-d`.
     * @return array<int, array{date: string, count: int}> Exactly
     * `TREND_DAYS` entries, oldest first, zero-filled for any day with
     * no bucket row.
     */
    private function zeroFilledTrend(array $countsByDate): array {
        $trend = [];
        $cursor = $this->floorToResolution(DateTimeHelper::currentUTCDateTime(), self::DAILY_RESOLUTION_MINUTES)->modify('-' . (self::TREND_DAYS - 1) . ' days');

        for ($i = 0; $i < self::TREND_DAYS; $i++) {
            $date = $cursor->format('Y-m-d');
            $trend[] = ['date' => $date, 'count' => $countsByDate[$date] ?? 0];
            $cursor = $cursor->modify('+1 day');
        }

        return $trend;
    }

    /**
     * One query across every incident on the report — same reasoning as
     * `occurrenceTrends()`. Filtered to the raw tier specifically: a row
     * this recent is always still raw — `rollupMinuteToHourly()` only
     * ever promotes rows past `RAW_RETENTION_MINUTES` (30), twice this
     * window — so no cross-tier summing is needed here.
     *
     * @param int[] $incidentIds
     * @return array<int, int> Keyed by incident id.
     */
    private function recentOccurrenceCounts(array $incidentIds): array {
        $incidentIds = array_values(array_unique(array_filter($incidentIds)));

        if (empty($incidentIds)) return [];

        $since = Db::prepareDateForDb(
            DateTimeHelper::currentUTCDateTime()->modify('-' . self::RECENT_WINDOW_MINUTES . ' minutes')
        );

        $buckets = IncidentOccurrenceBucket::find()
            ->where(['incidentId' => $incidentIds, 'resolutionMinutes' => self::RAW_RESOLUTION_MINUTES])
            ->andWhere(['>=', 'bucketStart', $since])
            ->all();

        $counts = [];

        foreach ($buckets as $bucket) {
            $counts[$bucket->incidentId] = ($counts[$bucket->incidentId] ?? 0) + $bucket->occurrenceCount;
        }

        return $counts;
    }

    /**
     * Classifies an incident's current activity, checked in this order
     * (first match wins) — a status is a lens on existing data, computed
     * fresh on every report, never stored.
     *
     *  - New: just showed up, not enough history to trend yet.
     *  - Escalating: recent rate well above its own baseline — the
     *    headline signal this exists for.
     *  - Quiet: gone silent for a while despite prior history.
     *    Deliberately not "Resolved" — nothing here confirms a fix, it's
     *    just stopped.
     *  - Declining: recent rate well below baseline, but still occurring.
     *  - Ongoing: ticking along near its own baseline — the fallback.
     *
     * @param Incident $incident
     * @param int $recentCount Occurrences in the last `RECENT_WINDOW_MINUTES`.
     * @param array<int, array{date: string, count: int}> $trend Zero-filled
     * daily trend, oldest first (see `occurrenceTrends()`).
     * @return string One of the `STATUS_*` constants.
     */
    private function statusFor(Incident $incident, int $recentCount, array $trend): string {
        if ($this->isWithin($incident->firstOccurredAt, self::NEW_WITHIN_HOURS)) {
            return self::STATUS_NEW;
        }

        $baselinePerWindow = $this->baselineRatePerWindow($trend);

        if ($recentCount >= self::ESCALATION_MIN_COUNT && $recentCount >= $baselinePerWindow * self::ESCALATION_MULTIPLIER) {
            return self::STATUS_ESCALATING;
        }

        if (!$this->isWithin($incident->lastOccurredAt, self::QUIET_AFTER_HOURS)) {
            return self::STATUS_QUIET;
        }

        if ($baselinePerWindow > 0 && $recentCount <= $baselinePerWindow * self::DECLINE_RATIO) {
            return self::STATUS_DECLINING;
        }

        return self::STATUS_ONGOING;
    }

    /**
     * @param string|null $dateTime
     * @param int $hours
     * @return bool Whether `$dateTime` is within the last `$hours`.
     */
    private function isWithin(?string $dateTime, int $hours): bool {
        if ($dateTime === null) return false;

        $cutoff = DateTimeHelper::currentUTCDateTime()->modify("-{$hours} hours");

        return new DateTime($dateTime, new DateTimeZone('UTC')) >= $cutoff;
    }

    /**
     * Average daily rate over the trailing `BASELINE_WINDOW_DAYS` complete
     * days (excluding today, which is still partial), scaled down to a
     * `RECENT_WINDOW_MINUTES`-wide rate — reuses the same zero-filled
     * trend `getReport()` already computes rather than running a second
     * query.
     *
     * @param array<int, array{date: string, count: int}> $trend Oldest first.
     * @return float
     */
    private function baselineRatePerWindow(array $trend): float {
        $completeDays = array_slice($trend, 0, -1);
        $baselineDays = array_slice($completeDays, -self::BASELINE_WINDOW_DAYS);

        if (empty($baselineDays)) return 0.0;

        $dailyAverage = array_sum(array_column($baselineDays, 'count')) / \count($baselineDays);

        return $dailyAverage / (24 * 60) * self::RECENT_WINDOW_MINUTES;
    }

    /**
     * Floors `$dateTime` down to the start of its enclosing
     * `$resolutionMinutes`-wide window, e.g. 13:07 -> 13:07 for minute-wide
     * buckets, 13:07 -> 13:00 for hourly, 13:07 -> 00:00 for daily — same
     * modulo arithmetic as `ResponseTimeService::floorToResolution()`.
     *
     * @param DateTime $dateTime
     * @param int $resolutionMinutes
     * @return DateTime
     */
    private function floorToResolution(DateTime $dateTime, int $resolutionMinutes): DateTime {
        $floored = clone $dateTime;
        $minutesSinceMidnight = ((int) $floored->format('H')) * 60 + (int) $floored->format('i');
        $flooredMinutes = $minutesSinceMidnight - ($minutesSinceMidnight % $resolutionMinutes);

        $floored->setTime(0, 0, 0)->modify("+{$flooredMinutes} minutes");

        return $floored;
    }
}
