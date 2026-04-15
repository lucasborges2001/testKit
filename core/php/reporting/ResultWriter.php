<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Testkit\Core\Common\Paths;

final class ResultWriter
{
    /**
     * @param array<string,mixed> $result
     */
    public static function writeSuite(array $result): void
    {
        $suiteId = (string)($result['suite_id'] ?? 'suite');
        $reportsRoot = (string)($result['report_root'] ?? '');
        if ($reportsRoot === '') {
            $reportsRoot = Paths::reportsRoot();
        }
        Paths::ensureDir($reportsRoot);

        $safeSuite = ReportFileNamer::safeSlug($suiteId, 'suite');
        $scopeKey = ReportFileNamer::scopeKey((string)($result['selected_module_scope'] ?? ''));
        $baseName = ReportFileNamer::suiteBaseName($suiteId, (string)($result['selected_module_scope'] ?? ''));
        $timestamp = gmdate('Ymd_His');

        $latestPath = $reportsRoot . '/' . $baseName . '_latest.json';
        $tsPath = $reportsRoot . '/' . $baseName . '_' . $timestamp . '.json';
        $canonicalLatestPath = $reportsRoot . '/' . $safeSuite . '_latest.json';

        $reportKeep = self::resolveKeep($result['report_keep'] ?? null, 5);
        $runsIndexKeep = self::resolveKeep($result['runs_index_keep'] ?? null, $reportKeep);
        $previous = AtomicJsonWriter::loadJsonFile($latestPath);

        $report = ReportDecorator::decorate(
            $result,
            $previous,
            $latestPath,
            $tsPath,
            $reportKeep,
            $runsIndexKeep,
            'suite'
        );

        $report['report_scope_key'] = $scopeKey;
        $report['report_key'] = $baseName;
        $report['report_links']['canonical_latest'] = basename($canonicalLatestPath);

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        AtomicJsonWriter::writeFileAtomic($latestPath, $json);
        AtomicJsonWriter::writeFileAtomic($tsPath, $json);
        if ($canonicalLatestPath !== $latestPath) {
            AtomicJsonWriter::writeFileAtomic($canonicalLatestPath, $json);
        }

        self::pruneOldRuns($reportsRoot, $baseName, $reportKeep);
        RunIndexWriter::updateRunsIndex(
            $reportsRoot,
            RunIndexWriter::buildRunsIndexEntry($report, 'suite', basename($latestPath), basename($tsPath), basename($canonicalLatestPath)),
            $runsIndexKeep
        );
    }

    /**
     * @param array<string,mixed> $meta
     */
    public static function writeMeta(array $meta): void
    {
        $reportsRoot = (string)($meta['report_root'] ?? '');
        if ($reportsRoot === '') {
            $reportsRoot = Paths::reportsRoot();
        }
        Paths::ensureDir($reportsRoot);

        $target = (string)($meta['target'] ?? 'all');
        $scope = (string)($meta['selected_module_scope'] ?? '');
        $baseName = ReportFileNamer::metaBaseName($target, $scope);
        $scopeKey = ReportFileNamer::scopeKey($scope);
        $timestamp = gmdate('Ymd_His');

        $latestPath = $reportsRoot . '/' . $baseName . '_latest.json';
        $tsPath = $reportsRoot . '/' . $baseName . '_' . $timestamp . '.json';
        $canonicalLatestPath = $reportsRoot . '/meta_latest.json';

        $reportKeep = self::resolveKeep($meta['report_keep'] ?? null, 5);
        $runsIndexKeep = self::resolveKeep($meta['runs_index_keep'] ?? null, $reportKeep);
        $previous = AtomicJsonWriter::loadJsonFile($latestPath);

        $report = ReportDecorator::decorate(
            $meta,
            $previous,
            $latestPath,
            $tsPath,
            $reportKeep,
            $runsIndexKeep,
            'meta'
        );

        $report['report_scope_key'] = $scopeKey;
        $report['report_key'] = $baseName;
        $report['report_links']['canonical_latest'] = basename($canonicalLatestPath);

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        AtomicJsonWriter::writeFileAtomic($latestPath, $json);
        AtomicJsonWriter::writeFileAtomic($tsPath, $json);
        if ($canonicalLatestPath !== $latestPath) {
            AtomicJsonWriter::writeFileAtomic($canonicalLatestPath, $json);
        }

        self::pruneOldRuns($reportsRoot, $baseName, $reportKeep);
        RunIndexWriter::updateRunsIndex(
            $reportsRoot,
            RunIndexWriter::buildRunsIndexEntry($report, 'meta', basename($latestPath), basename($tsPath), basename($canonicalLatestPath)),
            $runsIndexKeep
        );

        if (self::isRunScopedReportRoot($reportsRoot)) {
            self::publishLatestRunManifest($reportsRoot, $report);
        }
    }

    /**
     * Files matching <prefix>_YYYYmmdd_HHmmss.json are pruned; *_latest.json is never touched.
     */
    private static function pruneOldRuns(string $dir, string $prefix, int $keep): void
    {
        $safePfx = preg_replace('/[^a-z0-9._-]+/i', '_', strtolower($prefix)) ?: 'run';
        $pattern = $dir . '/' . $safePfx . '_[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]_[0-9][0-9][0-9][0-9][0-9][0-9].json';
        $files = glob($pattern) ?: [];
        sort($files);

        $excess = count($files) - $keep;
        for ($i = 0; $i < $excess; $i++) {
            @unlink($files[$i]);
        }
    }

    /**
     * @param mixed $value
     */
    private static function resolveKeep(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return max(1, $value);
        }

        if (is_string($value) && ctype_digit($value)) {
            return max(1, (int)$value);
        }

        return max(1, $default);
    }

    private static function isRunScopedReportRoot(string $reportsRoot): bool
    {
        $reportsRoot = Paths::normalize($reportsRoot);
        $runsPrefix = Paths::normalize(Paths::reportsRoot() . '/runs');

        return $reportsRoot !== Paths::reportsRoot() && str_starts_with($reportsRoot, $runsPrefix . '/');
    }

    /**
     * @param array<string,mixed> $report
     */
    private static function publishLatestRunManifest(string $reportsRoot, array $report): void
    {
        $manifest = [
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'run_id' => (string)($report['run_id'] ?? ''),
            'meta_run_id' => (string)($report['meta_run_id'] ?? ''),
            'target' => (string)($report['target'] ?? ''),
            'report_root' => Paths::normalize($reportsRoot),
            'report_scope_rel' => (string)($report['report_scope_rel'] ?? Paths::relativeToRepo($reportsRoot)),
        ];

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        AtomicJsonWriter::writeFileAtomic(Paths::latestRunManifestPath(), $json);
    }
}
