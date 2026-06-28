<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Testkit\Core\Common\Paths;

final class SeedStateInspectionReportLoader
{
    /** @return array<string,mixed> */
    public static function resolveRunContext(string $requestedRunId = ''): array
    {
        $requestedRunId = trim($requestedRunId);
        if ($requestedRunId !== '') {
            $root = Paths::reportRunRoot($requestedRunId);
            if (!is_dir($root)) {
                throw new \RuntimeException('run id no encontrado: ' . $requestedRunId);
            }

            return [
                'run_id' => $requestedRunId,
                'report_root' => Paths::normalize($root),
                'report_scope_rel' => Paths::relativeToRepo($root),
            ];
        }

        $latestManifest = self::loadJsonFile(Paths::latestRunManifestPath());
        if (is_array($latestManifest)) {
            $root = trim((string)($latestManifest['report_root'] ?? ''));
            if ($root !== '' && is_dir($root)) {
                return [
                    'run_id' => (string)($latestManifest['run_id'] ?? ''),
                    'report_root' => Paths::normalize($root),
                    'report_scope_rel' => (string)($latestManifest['report_scope_rel'] ?? Paths::relativeToRepo($root)),
                ];
            }
        }

        return [
            'run_id' => '',
            'report_root' => Paths::reportsRoot(),
            'report_scope_rel' => Paths::relativeToRepo(Paths::reportsRoot()),
        ];
    }

    /** @return array<string,mixed>|null */
    public static function loadMetaReport(string $reportRoot): ?array
    {
        $candidate = rtrim($reportRoot, '/\\') . '/meta_latest.json';
        $json = self::loadJsonFile($candidate);
        if (!is_array($json)) {
            return null;
        }

        $json['_source_file'] = $candidate;
        return self::assertCanonicalEnvelope($json, $candidate);
    }

    /** @return array<int,array<string,mixed>> */
    public static function loadCanonicalSuiteReports(string $reportRoot): array
    {
        if (!is_dir($reportRoot)) {
            return [];
        }

        $reports = [];
        foreach (glob(rtrim($reportRoot, '/\\') . '/*_latest.json') ?: [] as $file) {
            $name = basename($file);
            if ($name === 'meta_latest.json' || str_contains($name, '__')) {
                continue;
            }

            $json = self::loadJsonFile($file);
            if (!is_array($json) || !isset($json['suite_id'])) {
                continue;
            }

            $json['_source_file'] = $file;
            $reports[] = self::assertCanonicalEnvelope($json, $file);
        }

        usort($reports, static fn(array $a, array $b): int => strcmp((string)($a['suite_id'] ?? ''), (string)($b['suite_id'] ?? '')));
        return $reports;
    }

    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     */
    public static function assertCanonicalContext(?array $meta, array $suiteReports, string $reportRoot): void
    {
        if (is_array($meta) || $suiteReports !== []) {
            return;
        }

        throw new \RuntimeException(
            'inspect seed-state no encontró reportes canónicos en ' . Paths::relativeToRepo($reportRoot)
            . '. Corré un run reciente antes de inspeccionar seed_state.'
        );
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    public static function requireCanonicalReport(array $report, string $context): array
    {
        $canonical = $report['canonical_report'] ?? null;
        if (!is_array($canonical)) {
            throw new \RuntimeException($context . ' no contiene canonical_report');
        }
        if (!array_key_exists('report_version', $canonical)) {
            throw new \RuntimeException($context . ' tiene canonical_report sin report_version');
        }

        return $canonical;
    }

    /** @return array<int,array<string,mixed>> */
    public static function loadBaselineManifests(): array
    {
        $rows = [];
        foreach (glob(Paths::artifactsRoot() . '/baselines/*/*.manifest.json') ?: [] as $file) {
            $json = self::loadJsonFile($file);
            if (!is_array($json)) {
                continue;
            }

            $rows[] = [
                'path' => Paths::relativeToRepo($file),
                'status' => (string)($json['status'] ?? ''),
                'driver' => (string)($json['driver'] ?? ''),
                'db_name' => (string)($json['db_name'] ?? ''),
                'baseline_mode' => (string)($json['baseline_mode'] ?? ''),
                'generated_at' => (string)($json['generated_at'] ?? ''),
                'baseline_fingerprint' => (string)($json['baseline_fingerprint'] ?? ''),
                'migration_state' => $json['migration_state'] ?? null,
            ];
        }

        usort($rows, static fn(array $a, array $b): int => strcmp((string)($a['path'] ?? ''), (string)($b['path'] ?? '')));
        return $rows;
    }

    /** @return array<string,mixed>|null */
    private static function loadJsonFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $json = json_decode($raw, true);
        return is_array($json) ? $json : null;
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private static function assertCanonicalEnvelope(array $report, string $path): array
    {
        $canonical = $report['canonical_report'] ?? null;
        if (!is_array($canonical)) {
            throw new \RuntimeException(
                'inspect seed-state encontró un reporte sin canonical_report en ' . Paths::relativeToRepo($path)
                . '. Mezclar runs nuevos con JSON legacy ya no está soportado.'
            );
        }
        if (!array_key_exists('report_version', $canonical)) {
            throw new \RuntimeException(
                'inspect seed-state encontró canonical_report incompleto en ' . Paths::relativeToRepo($path)
                . ' (falta report_version).'
            );
        }

        return $report;
    }
}
