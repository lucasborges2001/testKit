<?php
declare(strict_types=1);

namespace Testkit\Core\Cleanup;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;

/**
 * Conservative artifact cleanup for host projects that use testKit.
 *
 * Safety model:
 * - dry-run is the default;
 * - destructive execution requires --apply;
 * - paths are constrained to .testkit or to the known test coverage directory;
 * - baseline manifest cleanup requires --force;
 * - database/store cleanup is intentionally out of scope for this command.
 */
final class CleanupCommand
{
    private const DEFAULT_KEEP_RUNS = 10;
    private const DEFAULT_KEEP_DAYS = 14;

    /** @var array<int,string> */
    private const GROUPS = [
        'all',
        'reports',
        'profiles',
        'coverage',
        'locks',
        'history',
        'baselines',
    ];

    /**
     * @param array<int,string> $argv
     */
    public static function runCli(array $argv): int
    {
        try {
            $options = self::parseArgs(array_slice($argv, 1));
            $payload = self::buildPlan($options);

            if ($options['apply']) {
                self::executePlan($payload);
                $payload['mode'] = 'apply';
            }

            self::writeAuditArtifact($payload, $options);

            if ($options['json']) {
                echo self::encodeJson($payload) . PHP_EOL;
            } else {
                self::printText($payload, $options);
            }

            return ((int)($payload['summary']['errors'] ?? 0)) > 0 ? 1 : 0;
        } catch (\InvalidArgumentException $e) {
            fwrite(STDERR, 'cleanup error: ' . $e->getMessage() . PHP_EOL);
            fwrite(STDERR, PHP_EOL . self::usage() . PHP_EOL);
            return 2;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'cleanup error: ' . $e->getMessage() . PHP_EOL);
            return 1;
        }
    }

    /**
     * @param array<int,string> $args
     * @return array<string,mixed>
     */
    private static function parseArgs(array $args): array
    {
        $group = 'all';
        $seenGroup = false;
        $options = [
            'group' => 'all',
            'apply' => false,
            'dry_run' => true,
            'json' => false,
            'force' => false,
            'keep_runs' => self::DEFAULT_KEEP_RUNS,
            'keep_days' => self::DEFAULT_KEEP_DAYS,
            'include_history' => false,
            'include_baselines' => false,
            'all_locks' => false,
            'help' => false,
        ];

        foreach ($args as $arg) {
            if ($arg === '' || $arg === '--dry-run') {
                continue;
            }
            if ($arg === '--help' || $arg === '-h') {
                throw new \InvalidArgumentException(self::usage());
            }
            if ($arg === '--apply') {
                $options['apply'] = true;
                $options['dry_run'] = false;
                continue;
            }
            if ($arg === '--json') {
                $options['json'] = true;
                continue;
            }
            if ($arg === '--force') {
                $options['force'] = true;
                continue;
            }
            if ($arg === '--include-history') {
                $options['include_history'] = true;
                continue;
            }
            if ($arg === '--include-baselines') {
                $options['include_baselines'] = true;
                continue;
            }
            if ($arg === '--all-locks') {
                $options['all_locks'] = true;
                continue;
            }
            if (preg_match('/^--keep-runs=(\d+)$/', $arg, $m) === 1) {
                $options['keep_runs'] = max(0, (int)$m[1]);
                continue;
            }
            if (preg_match('/^--keep-days=(\d+)$/', $arg, $m) === 1) {
                $options['keep_days'] = max(0, (int)$m[1]);
                continue;
            }

            if (!$seenGroup && in_array($arg, self::GROUPS, true)) {
                $group = $arg;
                $seenGroup = true;
                continue;
            }

            throw new \InvalidArgumentException('argumento no reconocido: ' . $arg);
        }

        $options['group'] = $group;

        if ($options['all_locks'] && !$options['force']) {
            throw new \InvalidArgumentException('--all-locks requiere --force');
        }
        if (($group === 'baselines' || $options['include_baselines']) && !$options['force']) {
            throw new \InvalidArgumentException('la limpieza de baselines requiere --force');
        }

        return $options;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private static function buildPlan(array $options): array
    {
        $startedAt = gmdate('Y-m-d\TH:i:s\Z');
        $artifactsRoot = Paths::artifactsRoot();
        $reportsRoot = Paths::reportsRoot();
        $repoRoot = Paths::repoRoot();

        $payload = [
            'ok' => true,
            'command' => 'cleanup',
            'mode' => $options['apply'] ? 'apply' : 'dry_run',
            'started_at' => $startedAt,
            'repo_root' => Paths::relativeToRepo($repoRoot),
            'artifacts_root' => Paths::relativeToRepo($artifactsRoot),
            'options' => [
                'group' => $options['group'],
                'keep_runs' => $options['keep_runs'],
                'keep_days' => $options['keep_days'],
                'include_history' => $options['include_history'],
                'include_baselines' => $options['include_baselines'],
                'force' => $options['force'],
            ],
            'groups' => [],
            'summary' => [
                'scanned' => 0,
                'delete_candidates' => 0,
                'bytes_reclaimable' => 0,
                'deleted' => 0,
                'bytes_deleted' => 0,
                'errors' => 0,
            ],
            'candidates' => [],
            'errors' => [],
        ];

        $group = (string)$options['group'];

        if ($group === 'all' || $group === 'reports') {
            self::appendReportCandidates($payload, $reportsRoot, $options);
        }
        if ($group === 'all' || $group === 'profiles') {
            self::appendProfileCandidates($payload, $artifactsRoot, $options);
        }
        if ($group === 'all' || $group === 'coverage') {
            self::appendCoverageCandidates($payload, $options);
        }
        if ($group === 'all' || $group === 'locks') {
            self::appendLockCandidates($payload, $artifactsRoot, $options);
        }
        if ($group === 'history' || ($group === 'all' && $options['include_history'])) {
            self::appendHistoryCandidates($payload, $artifactsRoot, $options);
        }
        if ($group === 'baselines' || ($group === 'all' && $options['include_baselines'])) {
            self::appendBaselineCandidates($payload, $artifactsRoot, $options);
        }

        $payload['finished_at'] = gmdate('Y-m-d\TH:i:s\Z');
        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     */
    private static function appendReportCandidates(array &$payload, string $reportsRoot, array $options): void
    {
        $group = [
            'run_dirs_scanned' => 0,
            'run_dirs_delete' => 0,
            'timestamped_json_scanned' => 0,
            'timestamped_json_delete' => 0,
        ];

        $runsRoot = $reportsRoot . '/runs';
        $runDirs = self::listChildDirs($runsRoot);
        $group['run_dirs_scanned'] = count($runDirs);
        self::addScanned($payload, count($runDirs));

        $runDirs = self::sortByMtimeDesc($runDirs);
        $now = time();
        foreach ($runDirs as $index => $dir) {
            $ageDays = self::ageDays($dir, $now);
            $protectedByCount = $index < (int)$options['keep_runs'];
            $protectedByAge = ((int)$options['keep_days'] > 0) && $ageDays <= (int)$options['keep_days'];
            if ($protectedByCount || $protectedByAge) {
                continue;
            }

            if (self::addCandidate($payload, 'reports', 'run_dir', $dir, 'old report run directory')) {
                $group['run_dirs_delete']++;
            }
        }

        $timestamped = self::findTimestampedJson($reportsRoot, true);
        $group['timestamped_json_scanned'] = count($timestamped);
        self::addScanned($payload, count($timestamped));

        $byPrefix = [];
        foreach ($timestamped as $file) {
            $prefix = self::jsonPrefix($file);
            $byPrefix[$prefix][] = $file;
        }

        foreach ($byPrefix as $files) {
            $files = self::sortByMtimeDesc($files);
            foreach ($files as $index => $file) {
                $ageDays = self::ageDays($file, $now);
                $protectedByCount = $index < (int)$options['keep_runs'];
                $protectedByAge = ((int)$options['keep_days'] > 0) && $ageDays <= (int)$options['keep_days'];
                if ($protectedByCount || $protectedByAge) {
                    continue;
                }

                if (self::addCandidate($payload, 'reports', 'timestamped_json', $file, 'old timestamped report json')) {
                    $group['timestamped_json_delete']++;
                }
            }
        }

        $payload['groups']['reports'] = $group;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     */
    private static function appendProfileCandidates(array &$payload, string $artifactsRoot, array $options): void
    {
        $group = [
            'shard_dirs_scanned' => 0,
            'shard_dirs_delete' => 0,
        ];

        foreach (['mysql_profile', 'influx_profile'] as $profileRootName) {
            $shardsRoot = $artifactsRoot . '/' . $profileRootName . '/shards';
            $shards = self::sortByMtimeDesc(self::listChildDirs($shardsRoot));
            $group['shard_dirs_scanned'] += count($shards);
            self::addScanned($payload, count($shards));

            $now = time();
            foreach ($shards as $index => $dir) {
                $ageDays = self::ageDays($dir, $now);
                $protectedByCount = $index < (int)$options['keep_runs'];
                $protectedByAge = ((int)$options['keep_days'] > 0) && $ageDays <= (int)$options['keep_days'];
                if ($protectedByCount || $protectedByAge) {
                    continue;
                }

                if (self::addCandidate($payload, 'profiles', 'profile_shard_dir', $dir, 'old profiling shard directory')) {
                    $group['shard_dirs_delete']++;
                }
            }
        }

        $payload['groups']['profiles'] = $group;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     */
    private static function appendCoverageCandidates(array &$payload, array $options): void
    {
        unset($options);
        $group = [
            'paths_scanned' => 0,
            'paths_delete' => 0,
            'skipped_unsafe' => 0,
        ];

        $paths = [];
        $defaultCoverage = Paths::repoRoot() . '/test/coverage';
        $paths[$defaultCoverage] = true;

        $fromEnv = Env::string('TEST_COVERAGE_DIR');
        if ($fromEnv !== '') {
            $paths[self::resolvePath($fromEnv)] = true;
        }

        foreach (array_keys($paths) as $path) {
            if (!file_exists($path)) {
                continue;
            }
            $group['paths_scanned']++;
            self::addScanned($payload, 1);

            if (!self::isSafeCoveragePath($path)) {
                $group['skipped_unsafe']++;
                self::addError($payload, 'coverage path rejected by safety policy: ' . Paths::relativeToRepo($path));
                continue;
            }

            if (self::addCandidate($payload, 'coverage', is_dir($path) ? 'coverage_dir' : 'coverage_file', $path, 'coverage artifact')) {
                $group['paths_delete']++;
            }
        }

        $payload['groups']['coverage'] = $group;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     */
    private static function appendLockCandidates(array &$payload, string $artifactsRoot, array $options): void
    {
        $group = [
            'lock_dirs_scanned' => 0,
            'stale' => 0,
            'active' => 0,
            'lock_dirs_delete' => 0,
        ];

        $locks = self::listChildDirs($artifactsRoot . '/locks');
        $group['lock_dirs_scanned'] = count($locks);
        self::addScanned($payload, count($locks));

        foreach ($locks as $dir) {
            $stale = self::isStaleLock($dir);
            if ($stale) {
                $group['stale']++;
            } else {
                $group['active']++;
            }

            if (!$stale && !$options['all_locks']) {
                continue;
            }

            if (self::addCandidate($payload, 'locks', $stale ? 'stale_lock_dir' : 'forced_lock_dir', $dir, $stale ? 'stale lock directory' : 'forced lock cleanup')) {
                $group['lock_dirs_delete']++;
            }
        }

        $payload['groups']['locks'] = $group;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     */
    private static function appendHistoryCandidates(array &$payload, string $artifactsRoot, array $options): void
    {
        $group = [
            'history_json_scanned' => 0,
            'history_json_delete' => 0,
        ];

        $files = self::listFiles($artifactsRoot . '/history', '/\.json$/');
        $files = self::sortByMtimeDesc($files);
        $group['history_json_scanned'] = count($files);
        self::addScanned($payload, count($files));

        $now = time();
        foreach ($files as $index => $file) {
            $ageDays = self::ageDays($file, $now);
            $protectedByCount = $index < (int)$options['keep_runs'];
            $protectedByAge = ((int)$options['keep_days'] > 0) && $ageDays <= (int)$options['keep_days'];
            if ($protectedByCount || $protectedByAge) {
                continue;
            }

            if (self::addCandidate($payload, 'history', 'history_json', $file, 'old history json')) {
                $group['history_json_delete']++;
            }
        }

        $payload['groups']['history'] = $group;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     */
    private static function appendBaselineCandidates(array &$payload, string $artifactsRoot, array $options): void
    {
        unset($options);
        $group = [
            'manifest_json_scanned' => 0,
            'manifest_json_delete' => 0,
        ];

        $files = self::listFiles($artifactsRoot . '/baselines', '/\.manifest\.json$/');
        $group['manifest_json_scanned'] = count($files);
        self::addScanned($payload, count($files));

        foreach ($files as $file) {
            if (self::addCandidate($payload, 'baselines', 'baseline_manifest', $file, 'baseline manifest; DB/store is not touched')) {
                $group['manifest_json_delete']++;
            }
        }

        $payload['groups']['baselines'] = $group;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function addCandidate(array &$payload, string $group, string $type, string $path, string $reason): bool
    {
        $path = Paths::normalize($path);
        if (!self::isSafeDeletePath($path, $group)) {
            self::addError($payload, 'unsafe delete candidate rejected: ' . Paths::relativeToRepo($path));
            return false;
        }

        $bytes = self::pathSize($path);
        $candidate = [
            'group' => $group,
            'type' => $type,
            'path' => Paths::relativeToRepo($path),
            'bytes' => $bytes,
            'reason' => $reason,
        ];

        $payload['candidates'][] = $candidate;
        $payload['summary']['delete_candidates']++;
        $payload['summary']['bytes_reclaimable'] += $bytes;
        return true;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function executePlan(array &$payload): void
    {
        foreach ($payload['candidates'] as $idx => $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $relativePath = (string)($candidate['path'] ?? '');
            $absolute = self::resolvePath($relativePath);
            $group = (string)($candidate['group'] ?? '');

            if (!self::isSafeDeletePath($absolute, $group)) {
                $payload['candidates'][$idx]['deleted'] = false;
                $payload['candidates'][$idx]['error'] = 'unsafe path rejected at execution';
                self::addError($payload, 'unsafe path rejected at execution: ' . $relativePath);
                continue;
            }

            $bytes = self::pathSize($absolute);
            $ok = self::deletePath($absolute);
            $payload['candidates'][$idx]['deleted'] = $ok;

            if ($ok) {
                $payload['summary']['deleted']++;
                $payload['summary']['bytes_deleted'] += $bytes;
            } else {
                $payload['candidates'][$idx]['error'] = 'delete failed';
                self::addError($payload, 'delete failed: ' . $relativePath);
            }
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     */
    private static function writeAuditArtifact(array $payload, array $options): void
    {
        unset($options);
        $root = Paths::reportsRoot() . '/cleanup';
        Paths::ensureDir($root);

        $timestamp = gmdate('Ymd_His');
        $json = self::encodeJson($payload);
        @file_put_contents($root . '/cleanup_latest.json', $json . PHP_EOL);
        @file_put_contents($root . '/cleanup_' . $timestamp . '.json', $json . PHP_EOL);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     */
    private static function printText(array $payload, array $options): void
    {
        $mode = (string)$payload['mode'];
        $summary = $payload['summary'];

        echo 'testkit cleanup ' . $mode . PHP_EOL;
        echo str_repeat('=', 72) . PHP_EOL;
        echo 'artifacts_root: ' . (string)$payload['artifacts_root'] . PHP_EOL;
        echo 'group:          ' . (string)$options['group'] . PHP_EOL;
        echo 'keep_runs:      ' . (int)$options['keep_runs'] . PHP_EOL;
        echo 'keep_days:      ' . (int)$options['keep_days'] . PHP_EOL;
        echo PHP_EOL;

        foreach ($payload['groups'] as $name => $group) {
            if (!is_array($group)) {
                continue;
            }
            echo $name . ':' . PHP_EOL;
            foreach ($group as $key => $value) {
                echo '  ' . str_pad((string)$key . ':', 28) . (string)$value . PHP_EOL;
            }
            echo PHP_EOL;
        }

        echo 'summary:' . PHP_EOL;
        echo '  scanned:                    ' . (int)$summary['scanned'] . PHP_EOL;
        echo '  delete_candidates:          ' . (int)$summary['delete_candidates'] . PHP_EOL;
        echo '  bytes_reclaimable:          ' . self::formatBytes((int)$summary['bytes_reclaimable']) . PHP_EOL;
        if ($mode === 'apply') {
            echo '  deleted:                    ' . (int)$summary['deleted'] . PHP_EOL;
            echo '  bytes_deleted:              ' . self::formatBytes((int)$summary['bytes_deleted']) . PHP_EOL;
        } else {
            echo PHP_EOL . 'Nothing deleted. Re-run with --apply to delete candidates.' . PHP_EOL;
        }

        if ((int)$summary['errors'] > 0) {
            echo PHP_EOL . 'errors:' . PHP_EOL;
            foreach ($payload['errors'] as $error) {
                echo '  - ' . (string)$error . PHP_EOL;
            }
        }
    }

    private static function usage(): string
    {
        return <<<'TXT'
Usage:
  testkit cleanup [all|reports|profiles|coverage|locks|history|baselines] [options]

Safe defaults:
  testkit cleanup reports --dry-run
  testkit cleanup reports --apply
  testkit cleanup all --dry-run

Options:
  --dry-run              Plan only. This is the default.
  --apply                Delete the selected candidates.
  --json                 Print JSON output.
  --keep-runs=N          Keep the newest N run/shard/history artifacts. Default: 10.
  --keep-days=N          Keep artifacts newer than N days. Default: 14.
  --include-history      Include .testkit/history when group is all.
  --include-baselines    Include baseline manifests when group is all. Requires --force.
  --all-locks            Delete active and stale lock dirs. Requires --force.
  --force                Required for baselines and --all-locks.

Notes:
  - cleanup never drops databases or docker volumes.
  - cleanup never deletes test seeds or test source files.
  - baseline cleanup only removes .manifest.json files, not database state.
TXT;
    }

    private static function encodeJson(mixed $payload): string
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || $encoded === '') {
            throw new \RuntimeException('no se pudo serializar JSON');
        }
        return $encoded;
    }

    /**
     * @return array<int,string>
     */
    private static function listChildDirs(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $dirs = [];
        $items = @scandir($root);
        if (!is_array($items)) {
            return [];
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = Paths::normalize($root . '/' . $item);
            if (is_dir($path)) {
                $dirs[] = $path;
            }
        }

        return $dirs;
    }

    /**
     * @return array<int,string>
     */
    private static function listFiles(string $root, string $pattern): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $info) {
            $path = Paths::normalize($info->getPathname());
            if ($info->isFile() && preg_match($pattern, basename($path)) === 1) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * @return array<int,string>
     */
    private static function findTimestampedJson(string $root, bool $skipRunDirs): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $runsRoot = Paths::normalize($root . '/runs');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $info) {
            $path = Paths::normalize($info->getPathname());
            if ($skipRunDirs && str_starts_with($path . '/', $runsRoot . '/')) {
                continue;
            }
            if (!$info->isFile()) {
                continue;
            }
            $base = basename($path);
            if (str_ends_with($base, '_latest.json') || $base === 'latest_run.json') {
                continue;
            }
            if (preg_match('/_(?:\d{8}_\d{6}|\d{8}T\d{6}Z(?:_[a-z0-9]+)?).*\.json$/i', $base) === 1) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * @param array<int,string> $paths
     * @return array<int,string>
     */
    private static function sortByMtimeDesc(array $paths): array
    {
        usort($paths, static function (string $a, string $b): int {
            return ((int)@filemtime($b)) <=> ((int)@filemtime($a));
        });
        return $paths;
    }

    private static function ageDays(string $path, int $now): int
    {
        $mtime = @filemtime($path);
        if ($mtime === false) {
            return PHP_INT_MAX;
        }
        return (int)floor(max(0, $now - $mtime) / 86400);
    }

    private static function jsonPrefix(string $file): string
    {
        $base = basename($file);
        $prefix = preg_replace('/_(?:\d{8}_\d{6}|\d{8}T\d{6}Z(?:_[a-z0-9]+)?).*\.json$/i', '', $base);
        return is_string($prefix) && $prefix !== '' ? dirname($file) . '/' . $prefix : dirname($file) . '/' . $base;
    }

    private static function resolvePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') {
            return '';
        }
        if (str_starts_with($path, '/')) {
            return Paths::normalize($path);
        }
        return Paths::normalize(Paths::repoRoot() . '/' . $path);
    }

    private static function isSafeDeletePath(string $path, string $group): bool
    {
        $path = Paths::normalize($path);
        if ($path === '' || $path === '/' || $path === '.') {
            return false;
        }

        $repoRoot = Paths::repoRoot();
        $testkitRoot = Paths::testkitRoot();
        $testRoot = Paths::testRoot();
        $artifactsRoot = Paths::artifactsRoot();

        foreach ([$repoRoot, $testkitRoot, $testRoot, $artifactsRoot] as $protected) {
            if ($path === Paths::normalize($protected)) {
                return false;
            }
        }

        if ($group === 'coverage') {
            return self::isSafeCoveragePath($path);
        }

        return self::isDescendant($path, $artifactsRoot);
    }

    private static function isSafeCoveragePath(string $path): bool
    {
        $path = Paths::normalize($path);
        $defaultCoverage = Paths::normalize(Paths::repoRoot() . '/test/coverage');
        if ($path === $defaultCoverage || self::isDescendant($path, $defaultCoverage)) {
            return true;
        }

        $artifactsRoot = Paths::artifactsRoot();
        if (self::isDescendant($path, $artifactsRoot) && str_contains('/' . $path . '/', '/coverage/')) {
            return true;
        }

        return false;
    }

    private static function isDescendant(string $path, string $root): bool
    {
        $path = Paths::normalize($path);
        $root = Paths::normalize($root);
        return $path !== $root && str_starts_with($path . '/', $root . '/');
    }

    private static function pathSize(string $path): int
    {
        if (!file_exists($path)) {
            return 0;
        }
        if (is_file($path) || is_link($path)) {
            $size = @filesize($path);
            return $size === false ? 0 : max(0, (int)$size);
        }

        $size = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $info) {
            if ($info->isFile() || $info->isLink()) {
                $fileSize = @filesize($info->getPathname());
                if ($fileSize !== false) {
                    $size += max(0, (int)$fileSize);
                }
            }
        }
        return $size;
    }

    private static function deletePath(string $path): bool
    {
        if (!file_exists($path) && !is_link($path)) {
            return true;
        }
        if (is_file($path) || is_link($path)) {
            return @unlink($path);
        }
        if (!is_dir($path)) {
            return false;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $info) {
            $item = $info->getPathname();
            if ($info->isDir() && !$info->isLink()) {
                if (!@rmdir($item)) {
                    return false;
                }
            } else {
                if (!@unlink($item)) {
                    return false;
                }
            }
        }

        return @rmdir($path);
    }

    private static function isStaleLock(string $lockPath): bool
    {
        $ownerFile = $lockPath . '/owner.json';
        if (!is_file($ownerFile)) {
            $mtime = @filemtime($lockPath);
            if ($mtime === false) {
                return false;
            }
            $ttlSec = max(60, Env::int('TEST_LOCK_STALE_TTL_SEC', 3600));
            return (time() - $mtime) > $ttlSec;
        }

        $raw = @file_get_contents($ownerFile);
        if (!is_string($raw) || trim($raw) === '') {
            $mtime = @filemtime($lockPath);
            if ($mtime === false) {
                return false;
            }
            $ttlSec = max(60, Env::int('TEST_LOCK_STALE_TTL_SEC', 3600));
            return (time() - $mtime) > $ttlSec;
        }

        $owner = json_decode($raw, true);
        if (!is_array($owner)) {
            return false;
        }

        $ownerPid = isset($owner['pid']) ? (int)$owner['pid'] : 0;
        $ownerHost = (string)($owner['hostname'] ?? '');
        $currentHost = function_exists('gethostname') ? (string)@gethostname() : '';

        if (
            $ownerHost !== ''
            && $currentHost !== ''
            && $ownerHost === $currentHost
            && $ownerPid > 0
            && function_exists('posix_kill')
        ) {
            $alive = @posix_kill($ownerPid, 0);
            if ($alive) {
                return false;
            }
            $errno = function_exists('posix_get_last_error') ? posix_get_last_error() : 0;
            return $errno === 3;
        }

        $acquiredAt = (string)($owner['acquired_at'] ?? '');
        if ($acquiredAt !== '') {
            $ts = strtotime($acquiredAt);
            $ageSec = $ts !== false ? (time() - $ts) : PHP_INT_MAX;
        } else {
            $mtime = @filemtime($ownerFile);
            $ageSec = $mtime !== false ? (time() - $mtime) : PHP_INT_MAX;
        }

        $ttlSec = max(60, Env::int('TEST_LOCK_STALE_TTL_SEC', 3600));
        return $ageSec > $ttlSec;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function addScanned(array &$payload, int $count): void
    {
        $payload['summary']['scanned'] += max(0, $count);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function addError(array &$payload, string $message): void
    {
        $payload['summary']['errors']++;
        $payload['ok'] = false;
        $payload['errors'][] = $message;
    }

    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float)max(0, $bytes);
        $unit = 0;
        while ($value >= 1024.0 && $unit < count($units) - 1) {
            $value /= 1024.0;
            $unit++;
        }
        if ($unit === 0) {
            return (string)((int)$value) . ' ' . $units[$unit];
        }
        return number_format($value, 2, '.', '') . ' ' . $units[$unit];
    }
}
