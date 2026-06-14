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
            'report_file' => $reportFile,
            'coverage_file' => $coverageFile,
            'lcov_file' => $lcovFile,
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
            'report_file' => self::resolveArtifactPath($metadata, 'report_file', 'coverage_report.md'),
            'coverage_file' => self::resolveArtifactPath($metadata, 'coverage_file', 'coverage.json'),
            'lcov_file' => self::resolveArtifactPath($metadata, 'lcov_file', 'lcov.info'),
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
    public static function resolveArtifactPath(array $metadata, string $field, string $fallbackBasename): string
    {
        $coverageDir = Paths::normalize((string)($metadata['coverage_dir'] ?? ''));
        $value = trim((string)($metadata[$field] ?? ''));
        if ($value === '') {
            $value = trim($fallbackBasename);
        }
        if ($value === '') {
            return '';
        }

        if (self::isAbsolutePath($value)) {
            return Paths::normalize($value);
        }

        if ($coverageDir === '') {
            return Paths::normalize($value);
        }

        return Paths::normalize($coverageDir . '/' . $value);
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
