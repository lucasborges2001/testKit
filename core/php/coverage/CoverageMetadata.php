<?php
declare(strict_types=1);

namespace Testkit\Core\Coverage;

use Testkit\Core\Common\Paths;

final class CoverageMetadata
{
    public const FILE = 'coverage_meta.json';

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $result
     * @param array<string,mixed> $diagnostics
     * @param array<string,mixed> $artifacts
     * @return array<string,mixed>
     */
    public static function build(array $config, array $result, string $reportRoot, array $diagnostics, array $artifacts = []): array
    {
        $suiteId = self::suiteId($config, $result);
        $coverageDir = Paths::normalize((string)($config['coverage_dir'] ?? Paths::coverageDirForSuite($suiteId)));
        $reportRoot = Paths::normalize($reportRoot !== '' ? $reportRoot : (string)($result['report_root'] ?? Paths::reportsRoot()));
        $runId = trim((string)($result['run_id'] ?? getenv('TEST_RUN_ID') ?: ''));
        $metaRunId = trim((string)($result['meta_run_id'] ?? getenv('TEST_META_RUN_ID') ?: $runId));
        $format = strtolower(trim((string)($config['coverage_format'] ?? 'lcov')));
        if ($format === '') {
            $format = 'lcov';
        }

        $diagnosticsFile = self::artifactBasename($artifacts, 'diagnostics_file', 'coverage_diagnostics.json');
        $reportFile = self::artifactBasename($artifacts, 'report_file', 'coverage_report.md');
        $coverageFile = self::artifactBasename($artifacts, 'coverage_json', 'coverage.json');
        $lcovFile = self::artifactBasename($artifacts, 'coverage_lcov', 'lcov.info');

        return [
            'schema_version' => 1,
            'suite_id' => $suiteId,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'coverage_dir' => $coverageDir,
            'coverage_dir_rel' => Paths::relativeToRepo($coverageDir),
            'report_root' => $reportRoot,
            'report_root_rel' => Paths::relativeToRepo($reportRoot),
            'run_id' => $runId,
            'meta_run_id' => $metaRunId,
            'coverage_enabled' => (bool)($config['coverage'] ?? false),
            'coverage_format' => $format,
            'source_dirs' => array_values(array_map('strval', (array)($diagnostics['source_dirs'] ?? CoverageFilter::sourceDirsFromEnv()))),
            'exclude_dirs' => array_values(array_map('strval', (array)($diagnostics['exclude_dirs'] ?? CoverageFilter::excludeDirsFromEnv()))),
            'diagnostics_file' => $diagnosticsFile,
            'diagnostics_file_rel' => Paths::relativeToRepo($coverageDir . '/' . $diagnosticsFile),
            'report_file' => $reportFile,
            'report_file_rel' => Paths::relativeToRepo($coverageDir . '/' . $reportFile),
            'coverage_file' => $coverageFile,
            'coverage_file_rel' => Paths::relativeToRepo($coverageDir . '/' . $coverageFile),
            'lcov_file' => $lcovFile,
            'lcov_file_rel' => Paths::relativeToRepo($coverageDir . '/' . $lcovFile),
            'diagnostics_summary' => self::diagnosticsSummary($diagnostics),
        ];
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $result
     * @param array<string,mixed> $diagnostics
     * @param array<string,mixed> $artifacts
     * @return array<string,mixed>
     */
    public static function write(array $config, array $result, string $reportRoot, array $diagnostics, array $artifacts = []): array
    {
        $metadata = self::build($config, $result, $reportRoot, $diagnostics, $artifacts);
        $coverageDir = Paths::normalize((string)$metadata['coverage_dir']);
        Paths::ensureDir($coverageDir);

        $metadataFile = $coverageDir . '/' . self::FILE;
        $metadata['metadata_file'] = $metadataFile;
        $metadata['metadata_file_rel'] = Paths::relativeToRepo($metadataFile);

        $json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            file_put_contents($metadataFile, $json);
        }

        return $metadata;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function readFromDir(string $coverageDir): ?array
    {
        return self::readFile(rtrim(Paths::normalize($coverageDir), '/') . '/' . self::FILE);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function readFile(string $path): ?array
    {
        $path = Paths::normalize($path);
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($json) ? $json : null;
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $diagnostics
     * @return array<string,mixed>
     */
    public static function suiteAttachment(array $metadata, array $diagnostics = []): array
    {
        $summary = is_array($metadata['diagnostics_summary'] ?? null)
            ? $metadata['diagnostics_summary']
            : self::diagnosticsSummary($diagnostics);

        return [
            'enabled' => true,
            'generated' => true,
            'status' => 'generated',
            'dir' => (string)($metadata['coverage_dir'] ?? ''),
            'dir_rel' => (string)($metadata['coverage_dir_rel'] ?? ''),
            'metadata_file' => (string)($metadata['metadata_file'] ?? ''),
            'metadata_file_rel' => (string)($metadata['metadata_file_rel'] ?? ''),
            'diagnostics_file' => self::resolveArtifactPath($metadata, 'diagnostics_file', 'coverage_diagnostics.json'),
            'diagnostics_file_rel' => (string)($metadata['diagnostics_file_rel'] ?? ''),
            'report_file' => self::resolveArtifactPath($metadata, 'report_file', 'coverage_report.md'),
            'report_file_rel' => (string)($metadata['report_file_rel'] ?? ''),
            'coverage_file' => self::resolveArtifactPath($metadata, 'coverage_file', 'coverage.json'),
            'coverage_file_rel' => (string)($metadata['coverage_file_rel'] ?? ''),
            'lcov_file' => self::resolveArtifactPath($metadata, 'lcov_file', 'lcov.info'),
            'lcov_file_rel' => (string)($metadata['lcov_file_rel'] ?? ''),
            'run_id' => (string)($metadata['run_id'] ?? ''),
            'meta_run_id' => (string)($metadata['meta_run_id'] ?? ''),
            'report_root' => (string)($metadata['report_root'] ?? ''),
            'report_root_rel' => (string)($metadata['report_root_rel'] ?? ''),
            'overall_percent' => (float)($summary['overall_percent'] ?? 0.0),
            'critical_missing_count' => (int)($summary['critical_missing_count'] ?? 0),
            'critical_low_count' => (int)($summary['critical_low_count'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $report
     */
    public static function matchesReport(array $metadata, array $report): bool
    {
        $suiteId = trim((string)($report['suite_id'] ?? ''));
        if ($suiteId === '' || trim((string)($metadata['suite_id'] ?? '')) !== $suiteId) {
            return false;
        }

        if (!((bool)($metadata['coverage_enabled'] ?? false))) {
            return false;
        }

        $reportRunId = trim((string)($report['run_id'] ?? ''));
        $metaRunId = trim((string)($metadata['run_id'] ?? ''));
        if ($reportRunId === '' || $metaRunId === '' || $reportRunId !== $metaRunId) {
            return false;
        }

        $reportRoot = trim((string)($report['report_root'] ?? ''));
        $metaReportRoot = trim((string)($metadata['report_root'] ?? ''));
        if ($reportRoot !== '' && $metaReportRoot !== '' && Paths::normalize($reportRoot) !== Paths::normalize($metaReportRoot)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public static function resolveArtifactPath(array $metadata, string $field, string $fallbackBasename, ?string $repoRoot = null): string
    {
        $repoRoot = self::repoRoot($repoRoot);
        $relativeField = $field . '_rel';

        $absoluteValue = trim((string)($metadata[$field] ?? ''));
        $relativeValue = trim((string)($metadata[$relativeField] ?? ''));
        $fallbackBasename = trim($fallbackBasename);

        $absoluteCandidate = null;
        if ($absoluteValue !== '' && self::isAbsolutePath($absoluteValue)) {
            $absoluteCandidate = Paths::normalize($absoluteValue);
        }

        $relativeCandidate = null;
        if ($relativeValue !== '' && self::isSafeRelativePath($relativeValue)) {
            $relativeCandidate = Paths::normalize($repoRoot . '/' . self::normalizeRelativePath($relativeValue));
        }

        if ($absoluteCandidate !== null && is_file($absoluteCandidate)) {
            return $absoluteCandidate;
        }
        if ($relativeCandidate !== null && is_file($relativeCandidate)) {
            return $relativeCandidate;
        }

        if ($absoluteValue === '') {
            $absoluteValue = $fallbackBasename;
        }
        if ($absoluteValue === '') {
            return $absoluteCandidate ?? $relativeCandidate ?? '';
        }

        if (self::isAbsolutePath($absoluteValue)) {
            return $absoluteCandidate ?? Paths::normalize($absoluteValue);
        }

        if (!self::isSafeRelativePath($absoluteValue)) {
            return $absoluteCandidate ?? $relativeCandidate ?? '';
        }

        $artifactRelative = self::normalizeRelativePath($absoluteValue);
        $repoRelativeCandidate = str_contains($artifactRelative, '/')
            ? Paths::normalize($repoRoot . '/' . $artifactRelative)
            : null;
        if ($repoRelativeCandidate !== null && is_file($repoRelativeCandidate)) {
            return $repoRelativeCandidate;
        }

        $coverageDir = self::resolveCoverageDir($metadata, $repoRoot);
        if ($coverageDir !== '') {
            $coverageCandidate = Paths::normalize($coverageDir . '/' . $artifactRelative);
            if (is_file($coverageCandidate)) {
                return $coverageCandidate;
            }

            if ($absoluteCandidate === null && $relativeCandidate === null) {
                return $coverageCandidate;
            }
        }

        return $absoluteCandidate ?? $relativeCandidate ?? $repoRelativeCandidate ?? Paths::normalize($artifactRelative);
    }

    /**
     * Resolve a path pair where one field may contain an absolute path from the
     * execution environment and the companion *_rel field contains a repo-relative
     * path valid from the current host checkout.
     *
     * @param array<string,mixed> $metadata
     */
    public static function resolvePathWithFallback(array $metadata, string $absoluteField, string $relativeField, string $repoRoot): ?string
    {
        $repoRoot = self::repoRoot($repoRoot);
        $absoluteValue = trim((string)($metadata[$absoluteField] ?? ''));
        $relativeValue = trim((string)($metadata[$relativeField] ?? ''));

        $absoluteCandidate = null;
        if ($absoluteValue !== '') {
            if (self::isAbsolutePath($absoluteValue)) {
                $absoluteCandidate = Paths::normalize($absoluteValue);
            } elseif (self::isSafeRelativePath($absoluteValue)) {
                $absoluteCandidate = Paths::normalize($repoRoot . '/' . self::normalizeRelativePath($absoluteValue));
            }
        }

        $relativeCandidate = null;
        if ($relativeValue !== '' && self::isSafeRelativePath($relativeValue)) {
            $relativeCandidate = Paths::normalize($repoRoot . '/' . self::normalizeRelativePath($relativeValue));
        }

        if ($absoluteCandidate !== null && self::pathExists($absoluteCandidate)) {
            return $absoluteCandidate;
        }
        if ($relativeCandidate !== null && self::pathExists($relativeCandidate)) {
            return $relativeCandidate;
        }

        return $absoluteCandidate ?? $relativeCandidate;
    }

    /**
     * @param array<string,mixed> $diagnostics
     * @return array<string,mixed>
     */
    public static function diagnosticsSummary(array $diagnostics): array
    {
        $criticalMissing = is_array($diagnostics['critical_missing'] ?? null) ? $diagnostics['critical_missing'] : [];
        $criticalLow = is_array($diagnostics['critical_low'] ?? null) ? $diagnostics['critical_low'] : [];

        return [
            'overall_percent' => (float)($diagnostics['overall']['percent'] ?? 0.0),
            'lines_total' => (int)($diagnostics['overall']['lines_total'] ?? 0),
            'lines_hit' => (int)($diagnostics['overall']['lines_hit'] ?? 0),
            'critical_missing_count' => count($criticalMissing),
            'critical_low_count' => count($criticalLow),
        ];
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $result
     */
    private static function suiteId(array $config, array $result): string
    {
        $suiteId = trim((string)($config['suite_id'] ?? $result['suite_id'] ?? 'suite'));
        return $suiteId !== '' ? $suiteId : 'suite';
    }

    /** @param array<string,mixed> $artifacts */
    private static function artifactBasename(array $artifacts, string $key, string $fallback): string
    {
        $value = trim((string)($artifacts[$key] ?? $fallback));
        if ($value === '') {
            return $fallback;
        }

        return basename(str_replace('\\', '/', $value));
    }

    /** @param array<string,mixed> $metadata */
    private static function resolveCoverageDir(array $metadata, string $repoRoot): string
    {
        $resolved = self::resolvePathWithFallback($metadata, 'coverage_dir', 'coverage_dir_rel', $repoRoot);
        if ($resolved !== null) {
            return $resolved;
        }

        $coverageDir = trim((string)($metadata['coverage_dir'] ?? ''));
        if ($coverageDir !== '') {
            return self::isAbsolutePath($coverageDir)
                ? Paths::normalize($coverageDir)
                : Paths::normalize($repoRoot . '/' . self::normalizeRelativePath($coverageDir));
        }

        return '';
    }

    private static function repoRoot(?string $repoRoot): string
    {
        $repoRoot = trim((string)$repoRoot);
        if ($repoRoot === '') {
            $repoRoot = Paths::repoRoot();
        }

        return Paths::normalize($repoRoot);
    }

    private static function pathExists(string $path): bool
    {
        return is_file($path) || is_dir($path);
    }

    private static function normalizeRelativePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }

    private static function isSafeRelativePath(string $path): bool
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '' || self::isAbsolutePath($path) || str_contains($path, "\0")) {
            return false;
        }

        foreach (explode('/', trim($path, '/')) as $part) {
            if ($part === '..') {
                return false;
            }
        }

        return true;
    }

    private static function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return strlen($path) >= 3
            && ctype_alpha($path[0])
            && $path[1] === ':'
            && ($path[2] === '\\' || $path[2] === '/');
    }
}
