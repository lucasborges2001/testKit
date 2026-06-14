<?php
declare(strict_types=1);

namespace Testkit\Core\Cleanup;

use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;

final class CleanupPlanner
{
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function buildPlan(array $options): array
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
                'max_runs' => $options['max_runs'],
                'max_artifacts' => $options['max_artifacts'] ?? null,
                'prune_to_latest' => $options['prune_to_latest'] ?? false,
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
            'run_dirs_delete_by_max_runs' => 0,
            'timestamped_json_scanned' => 0,
            'timestamped_json_delete' => 0,
        ];

        $runsRoot = $reportsRoot . '/runs';
        $runDirs = CleanupFilesystem::listChildDirs($runsRoot);
        $group['run_dirs_scanned'] = count($runDirs);
        self::addScanned($payload, count($runDirs));

        $runDirs = CleanupFilesystem::sortByMtimeDesc($runDirs);
        $now = time();
        $maxRuns = CleanupFilesystem::nullableInt($options['max_runs'] ?? null);
        foreach ($runDirs as $index => $dir) {
            if ($maxRuns !== null && $index >= $maxRuns) {
                if (self::addCandidate($payload, 'reports', 'run_dir', $dir, 'report run directory beyond hard cap')) {
                    $group['run_dirs_delete']++;
                    $group['run_dirs_delete_by_max_runs']++;
                }
                continue;
            }

            $ageDays = CleanupFilesystem::ageDays($dir, $now);
            $protectedByCount = $index < (int)$options['keep_runs'];
            $protectedByAge = ((int)$options['keep_days'] > 0) && $ageDays <= (int)$options['keep_days'];
            if ($protectedByCount || $protectedByAge) {
                continue;
            }

            if (self::addCandidate($payload, 'reports', 'run_dir', $dir, 'old report run directory')) {
                $group['run_dirs_delete']++;
            }
        }

        $timestamped = CleanupFilesystem::findTimestampedJson($reportsRoot, true);
        $group['timestamped_json_scanned'] = count($timestamped);
        self::addScanned($payload, count($timestamped));

        $byPrefix = [];
        foreach ($timestamped as $file) {
            $prefix = CleanupFilesystem::jsonPrefix($file);
            $byPrefix[$prefix][] = $file;
        }

        $maxArtifacts = CleanupFilesystem::nullableInt($options['max_artifacts'] ?? null);
        foreach ($byPrefix as $files) {
            $files = CleanupFilesystem::sortByMtimeDesc($files);
            foreach ($files as $index => $file) {
                $ageDays = CleanupFilesystem::ageDays($file, $now);
                $keepCount = (int)$options['keep_runs'];
                if ($maxArtifacts !== null && self::isCleanupAuditFile($file)) {
                    // CleanupAuditWriter writes a fresh cleanup_<timestamp>.json after execution.
                    // Keep one slot free so --prune-to-latest leaves only that final audit file.
                    $keepCount = max(0, $maxArtifacts - 1);
                }
                $protectedByCount = $index < $keepCount;
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
            'shard_dirs_delete_by_max_runs' => 0,
        ];

        $maxRuns = CleanupFilesystem::nullableInt($options['max_runs'] ?? null);
        foreach (['mysql_profile', 'influx_profile'] as $profileRootName) {
            $shardsRoot = $artifactsRoot . '/' . $profileRootName . '/shards';
            $shards = CleanupFilesystem::sortByMtimeDesc(CleanupFilesystem::listChildDirs($shardsRoot));
            $group['shard_dirs_scanned'] += count($shards);
            self::addScanned($payload, count($shards));

            $now = time();
            foreach ($shards as $index => $dir) {
                if ($maxRuns !== null && $index >= $maxRuns) {
                    if (self::addCandidate($payload, 'profiles', 'profile_shard_dir', $dir, 'profiling shard directory beyond hard cap')) {
                        $group['shard_dirs_delete']++;
                        $group['shard_dirs_delete_by_max_runs']++;
                    }
                    continue;
                }

                $ageDays = CleanupFilesystem::ageDays($dir, $now);
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
        $group = [
            'paths_scanned' => 0,
            'paths_delete' => 0,
            'skipped_unsafe' => 0,
        ];

        /** @var array<string,bool> $paths */
        $paths = [];
        self::appendCoveragePath($paths, Paths::repoRoot() . '/test/coverage');

        $artifactCoverage = Paths::artifactsRoot() . '/coverage';
        $pruneCoverageRoot = (bool)($options['prune_to_latest'] ?? false) || CleanupFilesystem::nullableInt($options['max_artifacts'] ?? null) !== null;
        if ($pruneCoverageRoot) {
            self::appendCoveragePath($paths, $artifactCoverage);
        } else {
            self::appendCoverageChildren($paths, $artifactCoverage);
        }

        foreach (['TEST_COVERAGE_ROOT', 'TEST_COVERAGE_DIR'] as $key) {
            $fromEnv = Env::string($key);
            if ($fromEnv !== '') {
                self::appendCoveragePath($paths, $fromEnv);
            }
        }

        foreach (array_keys($paths) as $path) {
            if (!file_exists($path)) {
                continue;
            }
            $group['paths_scanned']++;
            self::addScanned($payload, 1);

            if (!CleanupSafety::isSafeCoveragePath($path)) {
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
     * @param array<string,bool> $paths
     */
    private static function appendCoveragePath(array &$paths, string $path): void
    {
        $resolved = CleanupFilesystem::resolvePath($path);
        if ($resolved !== '') {
            $paths[$resolved] = true;
        }
    }

    /**
     * @param array<string,bool> $paths
     */
    private static function appendCoverageChildren(array &$paths, string $coverageRoot): void
    {
        $coverageRoot = Paths::normalize($coverageRoot);
        if (!is_dir($coverageRoot)) {
            return;
        }

        $items = @scandir($coverageRoot);
        if (!is_array($items)) {
            return;
        }

        $foundChild = false;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = Paths::normalize($coverageRoot . '/' . $item);
            if (file_exists($child) || is_link($child)) {
                $foundChild = true;
                $paths[$child] = true;
            }
        }

        if (!$foundChild) {
            $paths[$coverageRoot] = true;
        }
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

        $locks = CleanupFilesystem::listChildDirs($artifactsRoot . '/locks');
        $group['lock_dirs_scanned'] = count($locks);
        self::addScanned($payload, count($locks));

        foreach ($locks as $dir) {
            $stale = CleanupLockInspector::isStaleLock($dir);
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

        $files = CleanupFilesystem::listFiles($artifactsRoot . '/history', '/\.json$/');
        $group['history_json_scanned'] = count($files);
        self::addScanned($payload, count($files));

        $now = time();
        $maxArtifacts = CleanupFilesystem::nullableInt($options['max_artifacts'] ?? null);
        if ($maxArtifacts !== null) {
            $byPrefix = [];
            foreach ($files as $file) {
                $byPrefix[CleanupFilesystem::jsonPrefix($file)][] = $file;
            }
            foreach ($byPrefix as $groupedFiles) {
                $groupedFiles = CleanupFilesystem::sortByMtimeDesc($groupedFiles);
                foreach ($groupedFiles as $index => $file) {
                    if ($index < $maxArtifacts) {
                        continue;
                    }
                    if (self::addCandidate($payload, 'history', 'history_json', $file, 'history json beyond --max-artifacts hard cap')) {
                        $group['history_json_delete']++;
                    }
                }
            }
            $payload['groups']['history'] = $group;
            return;
        }

        $files = CleanupFilesystem::sortByMtimeDesc($files);
        foreach ($files as $index => $file) {
            $ageDays = CleanupFilesystem::ageDays($file, $now);
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

        $files = CleanupFilesystem::listFiles($artifactsRoot . '/baselines', '/\.manifest\.json$/');
        $group['manifest_json_scanned'] = count($files);
        self::addScanned($payload, count($files));

        foreach ($files as $file) {
            if (self::addCandidate($payload, 'baselines', 'baseline_manifest', $file, 'baseline manifest; DB/store is not touched')) {
                $group['manifest_json_delete']++;
            }
        }

        $payload['groups']['baselines'] = $group;
    }

    private static function isCleanupAuditFile(string $file): bool
    {
        return basename(dirname($file)) === 'cleanup' && preg_match('/^cleanup_\d{8}_\d{6}\.json$/', basename($file)) === 1;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function addCandidate(array &$payload, string $group, string $type, string $path, string $reason): bool
    {
        $path = Paths::normalize($path);
        if (!CleanupSafety::isSafeDeletePath($path, $group)) {
            self::addError($payload, 'unsafe delete candidate rejected: ' . Paths::relativeToRepo($path));
            return false;
        }

        $bytes = CleanupFilesystem::pathSize($path);
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
    private static function addScanned(array &$payload, int $count): void
    {
        $payload['summary']['scanned'] += max(0, $count);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function addError(array &$payload, string $message): void
    {
        $payload['summary']['errors']++;
        $payload['ok'] = false;
        $payload['errors'][] = $message;
    }
}
