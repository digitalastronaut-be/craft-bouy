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
use craft\helpers\App;
use craft\models\Update;
use craft\models\UpdateRelease;

use digitalastronaut\craftbouy\Bouy;

use DateTime;

/**
 * Class StatusService
 *
 * Builds the JSON report Harbour pulls from this site: installed Craft/
 * plugin versions, and whether Craft's own update data flags any pending
 * release as critical (security) — the same data source the control
 * panel's own Updates page uses. Reads Craft's cached update info only
 * ({@see craft\services\Updates::getUpdates()} with `$refresh = false`) so
 * a poll never triggers its own outbound call to Craft's update server.
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class StatusService extends Component {
    /**
     * @return array
     */
    public function getReport(): array {
        $updates = Craft::$app->getUpdates()->getUpdates(false);

        return [
            'site' => [
                'name' => Craft::$app->getSystemName(),
                'baseUrl' => App::env('PRIMARY_SITE_URL') ?? Craft::$app->getSites()->getPrimarySite()->getBaseUrl(),
            ],
            'craft' => $this->_updateReport($updates->cms, Craft::$app->getVersion()),
            'phpVersion' => PHP_VERSION,
            'plugins' => $this->_pluginsReport($updates->plugins),
            'database' => [
                'driver' => Craft::$app->getDb()->getDriverLabel(),
                'version' => App::normalizeVersion(Craft::$app->getDb()->getSchema()->getServerVersion()),
            ],
            // Counts only — the full package/vulnerability list this is
            // derived from is deliberately *not* eagerly carried here (see
            // `PackagesService`'s own docblock); it's served on demand
            // from `actions/bouy/packages` instead.
            'packages' => Bouy::getInstance()->getPackages()->getSummaryCounts(),
            'checkedAt' => (new DateTime())->format(DateTime::ATOM),
        ];
    }

    /**
     * Craft's own per-plugin update info (critical flag, pending releases),
     * re-keyed by composer package name instead of plugin handle — the join
     * key `PackagesService::refresh()` uses to merge this onto the matching
     * composer package row rather than showing a plugin twice (once here,
     * once as a plain composer dependency) with two potentially-disagreeing
     * "latest version" numbers. `craftcms/cms` is included the same way,
     * keyed by its own package name — it's just a package whose "plugin" is
     * Craft itself.
     *
     * @return array<string, array{version: string, critical: bool, releases: array}>
     */
    public function updatesByPackageName(): array {
        $updates = Craft::$app->getUpdates()->getUpdates(false);
        $result = ['craftcms/cms' => $this->_updateReport($updates->cms, Craft::$app->getVersion())];

        foreach (Craft::$app->getPlugins()->getAllPlugins() as $handle => $plugin) {
            $result[$plugin->packageName] = $this->_updateReport($updates->plugins[$handle] ?? null, $plugin->getVersion());
        }

        return $result;
    }

    /**
     * @param array<string, Update> $pluginUpdates keyed by plugin handle
     * @return array
     */
    private function _pluginsReport(array $pluginUpdates): array {
        $report = [];

        foreach (Craft::$app->getPlugins()->getAllPlugins() as $handle => $plugin) {
            $report[] = array_merge(
                [
                    'handle' => $handle,
                    'name' => $plugin->name,
                ],
                $this->_updateReport($pluginUpdates[$handle] ?? null, $plugin->getVersion())
            );
        }

        return $report;
    }

    /**
     * @param Update|null $update
     * @param string $installedVersion
     * @return array{version: string, critical: bool, releases: array<int, array{version: string, critical: bool, notes: string|null}>}
     */
    private function _updateReport(?Update $update, string $installedVersion): array {
        $releases = $update?->releases ?? [];

        return [
            'version' => $installedVersion,
            'critical' => $update?->getHasCritical() ?? false,
            'releases' => array_map(
                static fn(UpdateRelease $release): array => [
                    'version' => $release->version,
                    'critical' => $release->critical,
                    'notes' => $release->notes,
                ],
                $releases
            ),
        ];
    }
}
