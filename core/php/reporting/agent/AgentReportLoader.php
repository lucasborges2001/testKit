<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting\Agent;

use Testkit\Core\Common\Paths;

final class AgentReportLoader
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

        $latestManifest = self::loadLatestRunManifest();
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
    public static function loadLatestRunManifest(): ?array
    {
        return self::loadJsonFile(Paths::latestRunManifestPath());
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
        return $json;
    }

    /** @return array<int,array<string,mixed>> */
    public static function loadSuiteReports(string $reportRoot): array
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
            $reports[] = $json;
        }

        usort($reports, static fn(array $a, array $b): int => strcmp((string)($a['suite_id'] ?? ''), (string)($b['suite_id'] ?? '')));
        return $reports;
    }

    /** @return array<string,mixed>|null */
    public static function loadJsonFile(string $path): ?array
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
}
