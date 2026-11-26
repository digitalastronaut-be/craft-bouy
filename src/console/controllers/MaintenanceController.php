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

namespace digitalastronaut\craftbouy\console\controllers;

use digitalastronaut\craftbouy\Bouy;

use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Class MaintenanceController
 *
 * Intended to run on a daily cron: `php craft bouy/maintenance/prune`.
 * Keeps the incidents table (bounded by distinct bugs already, but stale
 * resolved-by-deploy ones shouldn't accumulate forever), the response-time
 * buckets table, and the slower two tiers of the incident-occurrence
 * buckets table (all bounded by time, via tiered rollup rather than a hard
 * cutoff — see `ResponseTimeService::rollupRawToHourly()`/
 * `rollupHourlyToDaily()` and `IncidentsService::rollupHourlyToDaily()`)
 * from growing without limit.
 *
 * The incident-occurrence buckets' *raw* (minute-wide) tier is handled
 * separately, by `actionRollupRecent()` — see that method for why a daily
 * cadence isn't frequent enough for it.
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class MaintenanceController extends Controller {
    public $defaultAction = 'prune';

    /**
     * @return int
     */
    public function actionPrune(): int {
        $rolledUpHourly = Bouy::getInstance()->getResponseTime()->rollupRawToHourly();
        $rolledUpDaily = Bouy::getInstance()->getResponseTime()->rollupHourlyToDaily();
        $rolledUpIncidentsDaily = Bouy::getInstance()->getIncidents()->rollupHourlyToDaily();
        $prunedIncidents = Bouy::getInstance()->getIncidents()->pruneOld();
        $prunedOccurrenceBuckets = Bouy::getInstance()->getIncidents()->pruneOldOccurrenceBuckets();

        $this->stdout(sprintf("Rolled up %d raw response-time bucket(s) into hourly buckets\n", $rolledUpHourly));
        $this->stdout(sprintf("Rolled up %d hourly response-time bucket(s) into daily buckets\n", $rolledUpDaily));
        $this->stdout(sprintf("Rolled up %d hourly incident-occurrence bucket(s) into daily buckets\n", $rolledUpIncidentsDaily));
        $this->stdout(sprintf("Pruned %d old incident(s)\n", $prunedIncidents));
        $this->stdout(sprintf("Pruned %d old incident-occurrence bucket(s)\n", $prunedOccurrenceBuckets));

        return ExitCode::OK;
    }

    /**
     * Intended for a much more frequent cron than `actionPrune()`'s daily
     * one — e.g. every 5 minutes: `php craft bouy/maintenance/rollup-recent`.
     * Rolls up only the incident-occurrence buckets' raw (minute-wide)
     * tier, which is retained for just `IncidentsService::
     * RAW_RETENTION_MINUTES` (30) minutes — far too short a window for a
     * daily cron to keep bounded, unlike every other tier this plugin
     * rolls up, whose retention is measured in hours/days rather than
     * minutes.
     *
     * @return int
     */
    public function actionRollupRecent(): int {
        $rolledUpHourly = Bouy::getInstance()->getIncidents()->rollupMinuteToHourly();

        $this->stdout(sprintf("Rolled up %d raw incident-occurrence bucket(s) into hourly buckets\n", $rolledUpHourly));

        return ExitCode::OK;
    }

    /**
     * Intended for a daily cron, same cadence as `actionPrune()`:
     * `php craft bouy/maintenance/refresh-dependencies`. The only trigger
     * for `PackagesService::refresh()` — OSV/Packagist/npm are never
     * queried from a web request, only from this command, so `getReport()`/
     * `getSummaryCounts()` (read by `actions/bouy/packages` and
     * `StatusService::getReport()` respectively) are always serving
     * whatever this last wrote.
     *
     * @return int
     */
    public function actionRefreshDependencies(): int {
        Bouy::getInstance()->getPackages()->refresh();

        $summary = Bouy::getInstance()->getPackages()->getSummaryCounts();

        $this->stdout(sprintf(
            "Refreshed dependency audit: %d vulnerable package(s) (%d critical), %d outdated direct dependency/dependencies\n",
            $summary['vulnerablePackageCount'],
            $summary['criticalVulnerabilityCount'],
            $summary['outdatedDirectCount'],
        ));

        return ExitCode::OK;
    }
}
