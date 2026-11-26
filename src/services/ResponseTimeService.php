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

use digitalastronaut\craftbouy\helpers\Table;
use digitalastronaut\craftbouy\records\ResponseTimeBucket;

use DateTime;
use DateTimeZone;
use yii\db\Expression;

/**
 * Class ResponseTimeService
 *
 * Aggregates real visitor request timings into fixed-width time buckets
 * rather than storing one row per request — the structural fix for
 * response-time data being able to "get really out of hand": a bucket's
 * row count is bounded by *time* (one row per `BUCKET_MINUTES` window,
 * regardless of how many requests land in it), never by traffic volume.
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class ResponseTimeService extends Component {
    public const BUCKET_MINUTES = 5;

    /**
     * Upper bound (ms, exclusive) of each histogram bin except the last,
     * which is open-ended — e.g. with these edges, bin 0 is "<50ms", bin 1
     * is "50-99ms", ..., and the final bin is ">=5000ms". Fixed and shared
     * with Harbour's interpretation of `getReportForRange()`'s `histogram`
     * arrays, so don't resize this without also widening/narrowing the matching
     * `binXCount` columns on both plugins' bucket tables.
     *
     * @var int[]
     */
    public const BIN_EDGES_MS = [50, 100, 200, 300, 500, 1000, 2000, 5000];

    /**
     * Default window `ResponseTimesController` reports over when the
     * caller doesn't specify its own `start`/`end`.
     */
    public const REPORT_HOURS = 24;

    private const HOURLY_RESOLUTION_MINUTES = 60;
    private const DAILY_RESOLUTION_MINUTES = 1440;
    private const RAW_RETENTION_HOURS = 48;
    private const HOURLY_RETENTION_DAYS = 14;

    /**
     * @param int $durationMs
     * @return void
     */
    public function recordRequest(int $durationMs): void {
        $bucketStart = Db::prepareDateForDb($this->floorToResolution(DateTimeHelper::currentUTCDateTime(), self::BUCKET_MINUTES));
        $binIndex = $this->binIndexFor($durationMs);
        $binColumn = "bin{$binIndex}Count";

        $insert = [
            'bucketStart' => $bucketStart,
            'resolutionMinutes' => self::BUCKET_MINUTES,
            'sampleCount' => 1,
            'totalMs' => $durationMs,
            'minMs' => $durationMs,
            'maxMs' => $durationMs,
        ];

        foreach (range(0, \count(self::BIN_EDGES_MS)) as $i) {
            $insert["bin{$i}Count"] = 0;
        }
        $insert[$binColumn] = 1;

        Craft::$app->getDb()->createCommand()->upsert(
            Table::BOUY_RESPONSE_TIME_BUCKETS,
            $insert,
            [
                'sampleCount' => new Expression('[[sampleCount]] + 1'),
                'totalMs' => new Expression('[[totalMs]] + :durationMs', [':durationMs' => $durationMs]),
                'minMs' => new Expression('LEAST([[minMs]], :durationMsMin)', [':durationMsMin' => $durationMs]),
                'maxMs' => new Expression('GREATEST([[maxMs]], :durationMsMax)', [':durationMsMax' => $durationMs]),
                $binColumn => new Expression("[[{$binColumn}]] + 1"),
            ],
        )->execute();
    }

    /**
     * Reports over an arbitrary `[$start, $end)` window — the display
     * resolution is picked automatically from how old `$start` is, using
     * the exact same cutoffs storage already rolls up at
     * (`RAW_RETENTION_HOURS`/`HOURLY_RETENTION_DAYS`), so a caller never
     * has to know which tier a given range actually lives in. Rows at
     * finer resolutions than the chosen one are merged on the fly — the
     * same arithmetic `rollup()` persists, just not written back — and
     * every window in range gets a zero-filled point even where no
     * request ever landed, so a chart never has to treat "no data" as a
     * missing point.
     *
     * @param DateTime $start
     * @param DateTime $end
     * @return array{resolutionMinutes: int, points: array<int, array{bucketStart: string, sampleCount: int, avgMs: int, minMs: int, maxMs: int, histogram: int[]}>}
     */
    public function getReportForRange(DateTime $start, DateTime $end): array {
        $resolution = $this->resolutionFor($start);

        $rows = ResponseTimeBucket::find()
            ->where(['>=', 'bucketStart', Db::prepareDateForDb($start)])
            ->andWhere(['<', 'bucketStart', Db::prepareDateForDb($end)])
            ->all();

        $rowsByWindow = [];
        foreach ($rows as $row) {
            $rowsByWindow[$this->windowStartForRow($row, $resolution)][] = $row;
        }

        $points = [];
        foreach ($this->windowsBetween($start, $end, $resolution) as $windowStart) {
            $merged = $this->mergeRows($rowsByWindow[$windowStart] ?? []);

            $points[] = [
                'bucketStart' => $windowStart,
                'sampleCount' => $merged['sampleCount'],
                'avgMs' => $merged['sampleCount'] > 0 ? (int) round($merged['totalMs'] / $merged['sampleCount']) : 0,
                'minMs' => $merged['minMs'],
                'maxMs' => $merged['maxMs'],
                'histogram' => array_map(
                    static fn(int $i): int => $merged["bin{$i}Count"],
                    range(0, \count(self::BIN_EDGES_MS)),
                ),
            ];
        }

        return ['resolutionMinutes' => $resolution, 'points' => $points];
    }

    /**
     * Picks the coarsest resolution storage still guarantees is available
     * for a range starting at `$start` — raw if it's within
     * `RAW_RETENTION_HOURS`, hourly if within `HOURLY_RETENTION_DAYS`,
     * daily otherwise. Daily is kept forever, so it's always a safe
     * fallback.
     *
     * @param DateTime $start
     * @return int
     */
    private function resolutionFor(DateTime $start): int {
        $rawCutoff = DateTimeHelper::currentUTCDateTime()->modify('-' . self::RAW_RETENTION_HOURS . ' hours');
        $hourlyCutoff = DateTimeHelper::currentUTCDateTime()->modify('-' . self::HOURLY_RETENTION_DAYS . ' days');

        return match (true) {
            $start >= $rawCutoff => self::BUCKET_MINUTES,
            $start >= $hourlyCutoff => self::HOURLY_RESOLUTION_MINUTES,
            default => self::DAILY_RESOLUTION_MINUTES,
        };
    }

    /**
     * Every `$resolutionMinutes`-wide window start covering
     * `[$start, $end)`, as DB-formatted strings — the zero-filled shape
     * `getReportForRange()` plots against, so a quiet window still gets
     * a point rather than a gap.
     *
     * @param DateTime $start
     * @param DateTime $end
     * @param int $resolutionMinutes
     * @return string[]
     */
    private function windowsBetween(DateTime $start, DateTime $end, int $resolutionMinutes): array {
        $cursor = $this->floorToResolution($start, $resolutionMinutes);
        $windows = [];

        while ($cursor < $end) {
            $windows[] = Db::prepareDateForDb($cursor);
            $cursor = $cursor->modify("+{$resolutionMinutes} minutes");
        }

        return $windows;
    }

    /**
     * Merges raw (`BUCKET_MINUTES`-wide) buckets older than
     * `RAW_RETENTION_HOURS` into hourly buckets, one per enclosing hour.
     *
     * @return int Number of raw rows consumed.
     */
    public function rollupRawToHourly(): int {
        $cutoff = Db::prepareDateForDb(
            DateTimeHelper::currentUTCDateTime()->modify('-' . self::RAW_RETENTION_HOURS . ' hours')
        );

        return $this->rollup(self::BUCKET_MINUTES, self::HOURLY_RESOLUTION_MINUTES, $cutoff);
    }

    /**
     * Merges hourly buckets older than `HOURLY_RETENTION_DAYS` into daily
     * buckets, one per enclosing UTC day. Daily buckets are never rolled
     * up further or pruned — one row per day is negligible even after
     * years of history.
     *
     * @return int Number of hourly rows consumed.
     */
    public function rollupHourlyToDaily(): int {
        $cutoff = Db::prepareDateForDb(
            DateTimeHelper::currentUTCDateTime()->modify('-' . self::HOURLY_RETENTION_DAYS . ' days')
        );

        return $this->rollup(self::HOURLY_RESOLUTION_MINUTES, self::DAILY_RESOLUTION_MINUTES, $cutoff);
    }

    /**
     * Merges every `$fromResolution` row older than `$cutoff` into
     * coarser `$toResolution` rows, grouped by their enclosing
     * `$toResolution`-wide window. One transaction per window, so an
     * interrupted run just leaves the remaining windows for next time
     * rather than losing or double-counting anything.
     *
     * @param int $fromResolution
     * @param int $toResolution
     * @param string $cutoff
     * @return int Number of `$fromResolution` rows consumed.
     */
    private function rollup(int $fromResolution, int $toResolution, string $cutoff): int {
        $rows = ResponseTimeBucket::find()
            ->where(['resolutionMinutes' => $fromResolution])
            ->andWhere(['<', 'bucketStart', $cutoff])
            ->all();

        $rowsByWindow = [];
        foreach ($rows as $row) {
            $rowsByWindow[$this->windowStartForRow($row, $toResolution)][] = $row;
        }

        $consumed = 0;

        foreach ($rowsByWindow as $windowStart => $windowRows) {
            Craft::$app->getDb()->transaction(function() use ($windowStart, $toResolution, $windowRows): void {
                $this->mergeIntoBucket($windowStart, $toResolution, $windowRows);

                ResponseTimeBucket::deleteAll(['id' => array_map(static fn(ResponseTimeBucket $row): int => $row->id, $windowRows)]);
            });

            $consumed += \count($windowRows);
        }

        return $consumed;
    }

    /**
     * Upserts the merge of `$rows` as a single `$resolutionMinutes` row
     * starting at `$bucketStart` — adding into whatever's already there
     * for that window rather than overwriting it, the same
     * read-modify-write-free shape `recordRequest()` uses.
     *
     * @param string $bucketStart
     * @param int $resolutionMinutes
     * @param ResponseTimeBucket[] $rows
     * @return void
     */
    private function mergeIntoBucket(string $bucketStart, int $resolutionMinutes, array $rows): void {
        $merged = $this->mergeRows($rows);

        $update = [
            'sampleCount' => new Expression('[[sampleCount]] + :sampleCount', [':sampleCount' => $merged['sampleCount']]),
            'totalMs' => new Expression('[[totalMs]] + :totalMs', [':totalMs' => $merged['totalMs']]),
            'minMs' => new Expression('LEAST([[minMs]], :minMs)', [':minMs' => $merged['minMs']]),
            'maxMs' => new Expression('GREATEST([[maxMs]], :maxMs)', [':maxMs' => $merged['maxMs']]),
        ];

        foreach (range(0, \count(self::BIN_EDGES_MS)) as $i) {
            $update["bin{$i}Count"] = new Expression("[[bin{$i}Count]] + :bin{$i}Count", [":bin{$i}Count" => $merged["bin{$i}Count"]]);
        }

        Craft::$app->getDb()->createCommand()->upsert(
            Table::BOUY_RESPONSE_TIME_BUCKETS,
            array_merge([
                'bucketStart' => $bucketStart,
                'resolutionMinutes' => $resolutionMinutes,
            ], $merged),
            $update,
        )->execute();
    }

    /**
     * Sums/min/max-merges a set of bucket rows into one attribute array —
     * the arithmetic both a persisted rollup (`mergeIntoBucket()`) and a
     * read-only range report (`getReportForRange()`) need, kept in one
     * place so they can never drift apart. An empty `$rows` (a window
     * with no data at all) merges to a clean zero-filled result rather
     * than leaving the `min`/`max` sentinels used during accumulation.
     *
     * @param ResponseTimeBucket[] $rows
     * @return array{sampleCount: int, totalMs: int, minMs: int, maxMs: int}
     */
    private function mergeRows(array $rows): array {
        $merged = [
            'sampleCount' => 0,
            'totalMs' => 0,
            'minMs' => PHP_INT_MAX,
            'maxMs' => PHP_INT_MIN,
        ];

        foreach (range(0, \count(self::BIN_EDGES_MS)) as $i) {
            $merged["bin{$i}Count"] = 0;
        }

        foreach ($rows as $row) {
            $merged['sampleCount'] += $row->sampleCount;
            $merged['totalMs'] += $row->totalMs;
            $merged['minMs'] = min($merged['minMs'], $row->minMs);
            $merged['maxMs'] = max($merged['maxMs'], $row->maxMs);

            foreach (range(0, \count(self::BIN_EDGES_MS)) as $i) {
                $merged["bin{$i}Count"] += (int) $row->getAttribute("bin{$i}Count");
            }
        }

        if ($merged['sampleCount'] === 0) {
            $merged['minMs'] = 0;
            $merged['maxMs'] = 0;
        }

        return $merged;
    }

    /**
     * Floors `$row`'s `bucketStart` down to the start of its enclosing
     * `$toResolution`-wide window. Parsed with an explicit UTC timezone
     * (never `DateTimeHelper::toDateTime()`, which defaults to converting
     * into the system timezone) — `bucketStart` is always stored in UTC,
     * so this never risks shifting a row into the wrong window.
     *
     * @param ResponseTimeBucket $row
     * @param int $toResolution
     * @return string
     */
    private function windowStartForRow(ResponseTimeBucket $row, int $toResolution): string {
        $bucketStart = new DateTime($row->bucketStart, new DateTimeZone('UTC'));

        return Db::prepareDateForDb($this->floorToResolution($bucketStart, $toResolution));
    }

    /**
     * Floors `$dateTime` down to the start of its enclosing
     * `$resolutionMinutes`-wide window, e.g. 13:07 -> 13:05 for 5-minute
     * buckets, 13:07 -> 13:00 for hourly, 13:07 -> 00:00 for daily — one
     * piece of modulo arithmetic on minutes-since-midnight covers every
     * resolution this service uses.
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

    /**
     * @param int $durationMs
     * @return int Index into `BIN_EDGES_MS` (plus one for the open-ended
     *             final bin) that `$durationMs` falls into.
     */
    private function binIndexFor(int $durationMs): int {
        foreach (self::BIN_EDGES_MS as $i => $edge) {
            if ($durationMs < $edge) return $i;
        }

        return \count(self::BIN_EDGES_MS);
    }
}
