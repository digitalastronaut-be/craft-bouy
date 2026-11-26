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
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;

use digitalastronaut\craftbouy\Bouy;
use digitalastronaut\craftbouy\helpers\Table;

use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;

use Throwable;
use yii\db\JsonExpression;

/**
 * Class PackagesService
 *
 * Audits this site's installed composer and npm packages against OSV.dev's
 * vulnerability database, and checks direct dependencies for newer
 * available versions. All the outbound work happens in `refresh()`, run
 * from a daily cron (`MaintenanceController::actionRefreshDependencies()`);
 * `getReport()` only ever reads back what that last wrote, the same split
 * `StatusService::getReport()` keeps between a web request and Craft's own
 * update check.
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class PackagesService extends Component {
    public const ECOSYSTEM_COMPOSER = 'Packagist';
    public const ECOSYSTEM_NPM = 'npm';

    /**
     * The only place this plugin makes outbound calls to OSV/Packagist/npm
     * — parses both lockfiles, batch-checks every package against OSV,
     * looks up the latest version for direct ones, computes each direct
     * package's transitive dependency count, merges Craft's own update
     * info onto whichever composer packages are also installed plugins
     * (or Craft itself — see `StatusService::updatesByPackageName()`), and
     * writes the merged result as the single cached row
     * `getReport()`/`getSummaryCounts()` read back. Intended for a daily
     * cron (`MaintenanceController::actionRefreshDependencies()`), never a
     * web request — a full run across a few hundred packages still only
     * takes low single-digit seconds, but there's no reason a status poll
     * should ever wait on it.
     *
     * @return void
     */
    public function refresh(): void {
        $composerPackages = $this->parseComposerLock();
        $npmPackages = $this->parseNpmLock();

        $composerGraph = $this->buildRequireGraph($composerPackages);
        $npmGraph = $this->buildRequireGraph($npmPackages);

        $packages = array_merge($composerPackages, $npmPackages);
        $packages = $this->queryVulnerabilities($packages);
        $latestVersions = $this->latestVersions($packages);
        $craftUpdates = Bouy::getInstance()->getStatus()->updatesByPackageName();

        $packages = array_map(function(array $package) use ($latestVersions, $craftUpdates, $composerGraph, $npmGraph): array {
            if ($package['direct']) {
                $graph = $package['ecosystem'] === self::ECOSYSTEM_COMPOSER ? $composerGraph : $npmGraph;
                $package['transitiveDependencyCount'] = $this->transitiveDependencyCount($package['name'], $graph);
            }

            $craftUpdate = $craftUpdates[$package['name']] ?? null;
            $package['isPlugin'] = $craftUpdate !== null;
            $package['craftCritical'] = $craftUpdate['critical'] ?? false;

            // Craft's own update feed is more authoritative for plugin/
            // Craft-version compatibility than a raw "newest tag on
            // Packagist" check, so a pending release there wins over the
            // Packagist-derived lookup. Falls back to that lookup when
            // Craft reports nothing pending (still useful for catching a
            // newer tag Craft's own feed hasn't surfaced, e.g. on a
            // `dev-main` install).
            $package['latestVersion'] = !empty($craftUpdate['releases'])
                ? self::stripVPrefix($craftUpdate['releases'][0]['version'])
                : ($latestVersions["{$package['ecosystem']}|{$package['name']}"] ?? null);

            return $package;
        }, $packages);

        $report = ['packages' => $packages];
        $refreshedAt = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());
        // Wrapped explicitly for the same reason `IncidentsService::
        // recordOccurrence()` wraps its own JSON columns before an
        // `upsert()` call — the query builder's automatic JSON typecast
        // only fires on `insert()`'s column list, not on `upsert()`'s
        // separate `ON DUPLICATE KEY UPDATE` one, so a raw PHP array
        // there crashes query logging with "Array to string conversion".
        $reportParam = new JsonExpression($report);

        Craft::$app->getDb()->createCommand()->upsert(
            Table::BOUY_DEPENDENCY_REPORTS,
            ['id' => 1, 'report' => $reportParam, 'refreshedAt' => $refreshedAt],
            ['report' => $reportParam, 'refreshedAt' => $refreshedAt],
        )->execute();
    }

    /**
     * Reads back whatever `refresh()` last wrote — never makes an
     * outbound call itself, the same split `StatusService::getReport()`
     * keeps against Craft's own (separately-triggered) update check.
     *
     * @return array{packages: array<int, array{name: string, version: string, ecosystem: string, direct: bool, latestVersion: string|null, vulnerabilities: array}>, refreshedAt?: string}
     */
    public function getReport(): array {
        $row = (new Query())
            ->select(['report', 'refreshedAt'])
            ->from([Table::BOUY_DEPENDENCY_REPORTS])
            ->where(['id' => 1])
            ->one();

        if ($row === false || $row['report'] === null) return ['packages' => []];

        $report = Json::decode($row['report']);

        return [
            'packages' => $report['packages'] ?? [],
            'refreshedAt' => $row['refreshedAt'],
        ];
    }

    /**
     * Small, bounded counts folded into `StatusService::getReport()` —
     * cheap enough to carry on every poll of every monitor, unlike the
     * full package list those counts are derived from (see this plugin's
     * `actions/bouy/packages` endpoint for that). Only `severity ===
     * 'critical'` counts toward `criticalVulnerabilityCount` — see
     * `severityFor()` for why a missing severity is never assumed
     * critical.
     *
     * @return array{vulnerablePackageCount: int, criticalVulnerabilityCount: int, outdatedDirectCount: int}
     */
    public function getSummaryCounts(): array {
        $packages = $this->getReport()['packages'];

        $vulnerablePackageCount = 0;
        $criticalVulnerabilityCount = 0;
        $outdatedDirectCount = 0;

        foreach ($packages as $package) {
            $vulnerabilities = $package['vulnerabilities'] ?? [];

            if (!empty($vulnerabilities)) $vulnerablePackageCount++;

            foreach ($vulnerabilities as $vulnerability) {
                if (($vulnerability['severity'] ?? null) === 'critical') $criticalVulnerabilityCount++;
            }

            if ($this->isOutdatedDirect($package)) $outdatedDirectCount++;
        }

        return [
            'vulnerablePackageCount' => $vulnerablePackageCount,
            'criticalVulnerabilityCount' => $criticalVulnerabilityCount,
            'outdatedDirectCount' => $outdatedDirectCount,
        ];
    }

    /**
     * @param array{direct: bool, version: string, latestVersion: string|null} $package
     * @return bool
     */
    private function isOutdatedDirect(array $package): bool {
        if (!$package['direct'] || $package['latestVersion'] === null) return false;

        return version_compare($package['latestVersion'], $package['version'], '>');
    }

    /**
     * Reads `composer.lock`'s `packages`/`packages-dev` entries, cross-
     * referenced against `composer.json`'s `require`/`require-dev` to flag
     * which are direct dependencies rather than transitive ones — only
     * direct dependencies are worth an outdated-version check (see
     * `latestVersions()`), nobody acts on bumping a transitive one by hand.
     * Each entry's own `require` names are carried along as `requires` too
     * — not used here, but exactly what `buildRequireGraph()` needs to
     * compute `transitiveDependencyCount` without re-reading the lockfile.
     *
     * @return array<int, array{name: string, version: string, ecosystem: string, direct: bool, requires: string[]}>
     */
    public function parseComposerLock(): array {
        $lockPath = Craft::getAlias('@root') . '/composer.lock';
        $manifestPath = Craft::getAlias('@root') . '/composer.json';

        if (!is_file($lockPath) || !is_file($manifestPath)) return [];

        $lock = Json::decode(file_get_contents($lockPath));
        $manifest = Json::decode(file_get_contents($manifestPath));

        $directNames = $this->directComposerNames($manifest);

        $packages = array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);

        return array_map(static fn(array $package): array => [
            'name' => $package['name'],
            'version' => self::stripVPrefix($package['version']),
            'ecosystem' => self::ECOSYSTEM_COMPOSER,
            'direct' => isset($directNames[$package['name']]),
            // Prod-only `require`, never `require-dev` — composer never
            // installs a dependency's own dev requirements when it's
            // pulled in as someone else's dependency, so those aren't
            // really part of the installed tree below this package.
            'requires' => self::filterPlatformPackageNames(array_keys($package['require'] ?? [])),
        ], $packages);
    }

    /**
     * Reads `package-lock.json`'s `packages` map (lockfile version 2/3 —
     * the format every currently-supported npm version writes), skipping
     * the root project's own `""` entry. A package's name isn't stored on
     * most entries (only workspace-aliased ones carry an explicit `name`)
     * — the normal case derives it from the key itself, the path segment
     * after the last `node_modules/`. Direct dependencies are detected by
     * looking for a top-level `node_modules/<name>` key for each name in
     * the root entry's `dependencies`/`devDependencies` — exactly how npm
     * itself resolves a direct dependency's installed copy.
     *
     * Each entry's own (prod) `dependencies` names are carried along as
     * `requires` too, for `buildRequireGraph()` — resolved purely by bare
     * name against the rest of this same flat list, not by walking nested
     * `node_modules/` paths the way npm's own resolver would for a
     * genuine version conflict. That's a deliberate simplification: real
     * per-dependency version forking is rare, and `transitiveDependencyCount`
     * only needs to be a good approximation, not an exact `npm ls` match.
     *
     * @return array<int, array{name: string, version: string, ecosystem: string, direct: bool, requires: string[]}>
     */
    public function parseNpmLock(): array {
        $lockPath = Craft::getAlias('@root') . '/package-lock.json';

        if (!is_file($lockPath)) return [];

        $lock = Json::decode(file_get_contents($lockPath));
        $packages = $lock['packages'] ?? [];
        $root = $packages[''] ?? [];

        $directNames = array_keys(array_merge($root['dependencies'] ?? [], $root['devDependencies'] ?? []));
        $directKeys = array_flip(array_map(static fn(string $name): string => "node_modules/{$name}", $directNames));

        $result = [];

        foreach ($packages as $key => $package) {
            if ($key === '' || !isset($package['version'])) continue;

            $result[] = [
                'name' => $package['name'] ?? $this->npmNameFromKey($key),
                'version' => $package['version'],
                'ecosystem' => self::ECOSYSTEM_NPM,
                'direct' => isset($directKeys[$key]),
                'requires' => array_keys($package['dependencies'] ?? []),
            ];
        }

        return $result;
    }

    /**
     * Batch-matches `$packages` against OSV.dev's vulnerability database —
     * one `querybatch` call covers every package regardless of ecosystem,
     * then a concurrent pool of `/v1/vulns/{id}` calls (see
     * `fetchVulnerabilityDetails()`) pulls summary/severity/fixed-version
     * detail for the distinct matched ids, deduplicated across packages
     * that happen to share an advisory. A failed batch call degrades to
     * "no known vulnerabilities" for every package rather than throwing —
     * `refresh()` should still write whatever it *did* manage to learn
     * (e.g. latest versions) rather than discarding the whole run.
     *
     * @param array<int, array{name: string, version: string, ecosystem: string, direct: bool}> $packages
     * @return array<int, array{name: string, version: string, ecosystem: string, direct: bool, vulnerabilities: array<int, array{id: string, summary: string|null, severity: string|null, fixedIn: string|null, url: string}>}>
     */
    public function queryVulnerabilities(array $packages): array {
        if (empty($packages)) return [];

        $queries = array_map(static fn(array $p): array => [
            'package' => ['name' => $p['name'], 'ecosystem' => $p['ecosystem']],
            'version' => $p['version'],
        ], $packages);

        try {
            $response = Craft::createGuzzleClient()->post('https://api.osv.dev/v1/querybatch', [
                'json' => ['queries' => $queries],
                'timeout' => 30,
            ]);

            $results = Json::decode((string) $response->getBody())['results'] ?? [];
        } catch (Throwable $e) {
            Craft::warning("Couldn't batch-query OSV for vulnerabilities: {$e->getMessage()}", __METHOD__);

            return array_map(static fn(array $p): array => [...$p, 'vulnerabilities' => []], $packages);
        }

        $ids = [];
        foreach ($results as $result) {
            foreach ($result['vulns'] ?? [] as $vuln) $ids[$vuln['id']] = true;
        }

        $details = $this->fetchVulnerabilityDetails(array_keys($ids));
        $packages = array_values($packages);

        return array_map(function(array $package, int $i) use ($results, $details): array {
            $matchedIds = array_column($results[$i]['vulns'] ?? [], 'id');

            $vulnerabilities = array_values(array_filter(array_map(
                fn(string $id): ?array => isset($details[$id]) ? $this->buildVulnerabilitySummary($details[$id], $package) : null,
                $matchedIds
            )));

            return [...$package, 'vulnerabilities' => $vulnerabilities];
        }, $packages, array_keys($packages));
    }

    /**
     * Fetches full detail for each of `$ids` concurrently — a batch can
     * easily span a few dozen distinct advisories, and OSV has no
     * "multi-get" endpoint for this part, only the single-id one.
     *
     * @param string[] $ids
     * @return array<string, array> Keyed by vulnerability id; an id whose
     * fetch failed is simply absent rather than null, so callers can use
     * `isset()` directly.
     */
    private function fetchVulnerabilityDetails(array $ids): array {
        if (empty($ids)) return [];

        $client = Craft::createGuzzleClient();
        $details = [];

        $requests = (static function() use ($ids) {
            foreach ($ids as $id) {
                yield $id => new Request('GET', "https://api.osv.dev/v1/vulns/{$id}");
            }
        })();

        (new Pool($client, $requests, [
            'concurrency' => 10,
            'fulfilled' => function($response, $id) use (&$details): void {
                $details[$id] = Json::decode((string) $response->getBody());
            },
            'rejected' => function($reason, $id): void {
                Craft::warning("Couldn't fetch OSV vulnerability {$id}: {$reason}", __METHOD__);
            },
        ]))->promise()->wait();

        return $details;
    }

    /**
     * @param array $detail Decoded OSV vulnerability record.
     * @param array{name: string, version: string, ecosystem: string} $package
     * @return array{id: string, summary: string|null, severity: string|null, fixedIn: string|null, url: string}
     */
    private function buildVulnerabilitySummary(array $detail, array $package): array {
        return [
            'id' => $detail['id'],
            'summary' => $detail['summary'] ?? null,
            'severity' => $this->severityFor($detail),
            'fixedIn' => $this->fixedVersionFor($detail, $package),
            'url' => $this->advisoryUrl($detail),
        ];
    }

    /**
     * OSV only reliably carries a human severity tier
     * (`database_specific.severity`, one of `LOW`/`MODERATE`/`HIGH`/
     * `CRITICAL`) for GitHub-reviewed advisories — the common case for
     * both Packagist and npm. Entries from other sources often carry only
     * a raw CVSS vector string (`severity[].score`) instead, which would
     * need a CVSS parser to turn into a tier; rather than guess, this
     * leaves `severity` null for those, which `PackagesService::
     * getSummaryCounts()` simply doesn't count toward `criticalVulnerabilityCount`.
     *
     * @param array $detail
     * @return string|null
     */
    private function severityFor(array $detail): ?string {
        $severity = $detail['database_specific']['severity'] ?? null;

        return $severity !== null ? strtolower($severity) : null;
    }

    /**
     * The lowest fixed version above `$package['version']` across every
     * range OSV lists for this package — e.g. psr7 1.7.0 is vulnerable in
     * both the 1.x and 2.x lines (two separate ranges, two separate fixed
     * versions); the 2.x line's fix isn't a usable answer for someone on
     * 1.x, so only fixed versions actually reachable from the installed
     * one are considered.
     *
     * @param array $detail
     * @param array{name: string, ecosystem: string, version: string} $package
     * @return string|null
     */
    private function fixedVersionFor(array $detail, array $package): ?string {
        $fixedVersions = [];

        foreach ($detail['affected'] ?? [] as $affected) {
            if (($affected['package']['name'] ?? null) !== $package['name']) continue;
            if (($affected['package']['ecosystem'] ?? null) !== $package['ecosystem']) continue;

            foreach ($affected['ranges'] ?? [] as $range) {
                foreach ($range['events'] ?? [] as $event) {
                    if (isset($event['fixed'])) $fixedVersions[] = self::stripVPrefix($event['fixed']);
                }
            }
        }

        $fixedVersions = array_values(array_filter(
            $fixedVersions,
            fn(string $fixed): bool => version_compare($fixed, $package['version'], '>')
        ));

        if (empty($fixedVersions)) return null;

        usort($fixedVersions, 'version_compare');

        return $fixedVersions[0];
    }

    /**
     * @param array $detail
     * @return string
     */
    private function advisoryUrl(array $detail): string {
        foreach ($detail['references'] ?? [] as $reference) {
            if (($reference['type'] ?? null) === 'ADVISORY') return $reference['url'];
        }

        return "https://osv.dev/vulnerability/{$detail['id']}";
    }

    /**
     * Looks up the latest available version for every **direct**
     * dependency in `$packages` — transitive ones are skipped entirely,
     * since nobody acts on bumping one of those by hand, and skipping them
     * keeps this to tens of registry calls rather than hundreds. Packagist's
     * `p2` metadata and npm's abbreviated registry metadata (the same
     * reduced payload `npm outdated` itself requests, via the
     * `application/vnd.npm.install-v1+json` `Accept` header) are both
     * small, cacheable, unauthenticated endpoints meant for exactly this.
     *
     * @param array<int, array{name: string, version: string, ecosystem: string, direct: bool}> $packages
     * @return array<string, string|null> Keyed by `"{$ecosystem}|{$name}"`; a package whose lookup failed or has no stable release is simply absent.
     */
    public function latestVersions(array $packages): array {
        $directPackages = array_values(array_filter($packages, static fn(array $p): bool => $p['direct']));

        if (empty($directPackages)) return [];

        $results = [];

        $requests = (static function() use ($directPackages) {
            foreach ($directPackages as $i => $package) {
                $url = $package['ecosystem'] === self::ECOSYSTEM_COMPOSER
                    ? "https://repo.packagist.org/p2/{$package['name']}.json"
                    : "https://registry.npmjs.org/{$package['name']}";

                $headers = $package['ecosystem'] === self::ECOSYSTEM_COMPOSER
                    ? []
                    : ['Accept' => 'application/vnd.npm.install-v1+json'];

                yield $i => new Request('GET', $url, $headers);
            }
        })();

        (new Pool(Craft::createGuzzleClient(), $requests, [
            'concurrency' => 10,
            'fulfilled' => function($response, $i) use (&$results, $directPackages): void {
                $package = $directPackages[$i];
                $body = Json::decode((string) $response->getBody());
                $key = "{$package['ecosystem']}|{$package['name']}";

                $results[$key] = $package['ecosystem'] === self::ECOSYSTEM_COMPOSER
                    ? $this->latestStableComposerVersion($body, $package['name'])
                    : ($body['dist-tags']['latest'] ?? null);
            },
            'rejected' => function($reason, $i) use ($directPackages): void {
                Craft::warning("Couldn't fetch latest version for {$directPackages[$i]['name']}: {$reason}", __METHOD__);
            },
        ]))->promise()->wait();

        return $results;
    }

    /**
     * Packagist's `p2` endpoint only lists tagged releases (no `dev-main`-
     * style branch aliases — those live behind a separate `~dev` suffixed
     * endpoint this never queries), but those releases still include
     * pre-releases (`-alpha`, `-beta`, `-rc`), which "latest version"
     * shouldn't recommend jumping to. Computed via `version_compare`
     * rather than trusting the response's own ordering.
     *
     * @param array $body Decoded `p2` response.
     * @param string $name
     * @return string|null
     */
    private function latestStableComposerVersion(array $body, string $name): ?string {
        $versions = array_map(
            static fn(array $v): string => self::stripVPrefix($v['version']),
            $body['packages'][$name] ?? []
        );

        $stable = array_values(array_filter(
            $versions,
            static fn(string $v): bool => !str_starts_with($v, 'dev-') && !preg_match('/-(dev|alpha|beta|rc)/i', $v)
        ));

        if (empty($stable)) return null;

        usort($stable, 'version_compare');

        return end($stable);
    }

    /**
     * @param array $manifest Decoded `composer.json`.
     * @return array<string, true> Set of package names (lowercased keys
     * aren't needed — composer package names are already lowercase by
     * convention) from `require`/`require-dev`, minus the `php`/`ext-*`
     * platform pseudo-packages composer.lock never lists anyway.
     */
    private function directComposerNames(array $manifest): array {
        $names = array_merge(array_keys($manifest['require'] ?? []), array_keys($manifest['require-dev'] ?? []));

        return array_flip(self::filterPlatformPackageNames($names));
    }

    /**
     * Drops the `php`/`ext-*` platform pseudo-packages from `$names` —
     * shared by `directComposerNames()` (composer.json's own
     * `require`/`require-dev`) and `parseComposerLock()` (each lock entry's
     * own `require`), since composer.lock never lists these as real
     * installable packages either way.
     *
     * @param string[] $names
     * @return string[]
     */
    private static function filterPlatformPackageNames(array $names): array {
        return array_values(array_filter(
            $names,
            static fn(string $name): bool => !str_starts_with($name, 'php') && !str_starts_with($name, 'ext-')
        ));
    }

    /**
     * Strips a leading `v` (e.g. `v3.1.1`) some packages' git tags carry
     * straight through into both `composer.lock`'s `version` field and
     * Packagist's own `p2` metadata, unchanged. This isn't cosmetic:
     * PHP's `version_compare()` has no notion of a `v` prefix and silently
     * mis-orders anything carrying one — `version_compare('v5.8.1', '1.0.6')`
     * returns `-1`, ranking a years-old `1.0.6` tag above a current
     * `v5.8.1` one, which is exactly the mix Packagist's `p2` response for
     * `vlucas/phpdotenv` actually contains. Every version string this
     * service hands to `version_compare()` (installed, latest-available,
     * and OSV's `fixed` field) goes through this first so comparisons are
     * never skewed by an inconsistently-tagged package.
     *
     * @param string $version
     * @return string
     */
    private static function stripVPrefix(string $version): string {
        return preg_replace('/^v(?=\d)/i', '', $version);
    }

    /**
     * `name => [direct child names]`, built from `$packages`' own
     * `requires` (see `parseComposerLock()`/`parseNpmLock()`) — composer and
     * npm are built as two separate graphs by calling this once per
     * ecosystem's own package list, since the two trees never cross.
     *
     * @param array<int, array{name: string, requires: string[]}> $packages
     * @return array<string, string[]>
     */
    private function buildRequireGraph(array $packages): array {
        $graph = [];

        foreach ($packages as $package) {
            $graph[$package['name']] = $package['requires'];
        }

        return $graph;
    }

    /**
     * Every package reachable from `$name` by following `$graph`'s edges,
     * excluding `$name` itself — a plain BFS, cycle-safe via `$visited` (a
     * genuine dependency cycle is rare but not impossible, and would
     * infinite-loop an unguarded traversal). A name missing from `$graph`
     * entirely (e.g. a `require` on a package this project doesn't actually
     * have installed — a `replace`d or `provide`d virtual package) is
     * simply a dead end, not an error.
     *
     * @param string $name
     * @param array<string, string[]> $graph
     * @return int
     */
    private function transitiveDependencyCount(string $name, array $graph): int {
        $visited = [];
        $queue = $graph[$name] ?? [];

        while (!empty($queue)) {
            $current = array_shift($queue);

            if (isset($visited[$current])) continue;

            $visited[$current] = true;

            foreach ($graph[$current] ?? [] as $child) {
                if (!isset($visited[$child])) $queue[] = $child;
            }
        }

        return count($visited);
    }

    /**
     * @param string $key e.g. `node_modules/@scope/name` or `node_modules/foo/node_modules/bar`.
     * @return string e.g. `@scope/name` or `bar`.
     */
    private function npmNameFromKey(string $key): string {
        $segments = explode('node_modules/', $key);

        return end($segments);
    }
}
