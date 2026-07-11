<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling;

final class MysqlInstrumentationAudit
{
    /** @param array<string,mixed> $report @return array<string,mixed> */
    public static function analyze(array $report): array
    {
        $coverage = is_array($report['coverage'] ?? null) ? $report['coverage'] : self::legacyCoverage($report);
        $instrumentation = is_array($report['instrumentation'] ?? null)
            ? $report['instrumentation']
            : ['status' => 'unknown', 'findings' => [], 'limitations' => []];
        $connections = is_array($report['connections'] ?? null) ? $report['connections'] : [];
        $findings = is_array($instrumentation['findings'] ?? null) ? $instrumentation['findings'] : [];

        $partialConnections = [];
        foreach ($connections as $connection) {
            if (!is_array($connection)) {
                continue;
            }
            $caps = is_array($connection['capture_capabilities'] ?? null)
                ? $connection['capture_capabilities']
                : [];
            if (
                empty($caps['query'])
                || empty($caps['exec'])
                || empty($caps['prepare_execute'])
            ) {
                $partialConnections[] = [
                    'connection_id' => (string)($connection['connection_id'] ?? ''),
                    'adapter' => (string)($connection['adapter'] ?? 'unknown'),
                    'capture_capabilities' => $caps,
                ];
            }
        }

        $recommendations = [];
        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $recommendation = trim((string)($finding['recommendation'] ?? ''));
            if ($recommendation !== '') {
                $recommendations[] = [
                    'code' => (string)($finding['code'] ?? 'unknown'),
                    'recommendation' => $recommendation,
                ];
            }
        }
        if ($partialConnections !== []) {
            $recommendations[] = [
                'code' => 'partial_connections',
                'recommendation' => 'Migrar factories parciales a tk_profiled_pdo() cuando sea viable.',
            ];
        }

        return [
            'valid' => true,
            'report_version' => (int)($report['report_version'] ?? 1),
            'schema_version' => (string)($report['schema_version'] ?? 'legacy-v1'),
            'run_id' => (string)($report['run_id'] ?? ''),
            'status' => (string)($instrumentation['status'] ?? 'unknown'),
            'coverage' => $coverage,
            'capture_methods' => (array)($coverage['facts']['queries_by_capture_method'] ?? []),
            'partial_connections' => $partialConnections,
            'findings' => $findings,
            'recommendations' => self::dedupeRecommendations($recommendations),
            'limitations' => array_values(array_filter(
                (array)($instrumentation['limitations'] ?? $report['limitations'] ?? []),
                'is_string'
            )),
        ];
    }

    /** @param array<string,mixed> $report */
    public static function contractError(array $report): ?string
    {
        if (!isset($report['report_version']) || !is_numeric($report['report_version'])) {
            return 'missing_or_invalid_report_version';
        }
        if (!isset($report['engine']) || (string)$report['engine'] !== 'mysql') {
            return 'unsupported_or_missing_engine';
        }
        if (!isset($report['summary']) || !is_array($report['summary'])) {
            return 'missing_summary';
        }
        if ((int)$report['report_version'] >= 2) {
            if (($report['schema_version'] ?? '') !== MysqlProfileConfig::SCHEMA_VERSION) {
                return 'invalid_schema_version';
            }
            if (!isset($report['coverage']) || !is_array($report['coverage'])) {
                return 'missing_coverage';
            }
            if (!isset($report['instrumentation']) || !is_array($report['instrumentation'])) {
                return 'missing_instrumentation';
            }
        }
        return null;
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private static function legacyCoverage(array $report): array
    {
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        return [
            'facts' => [
                'captured_queries' => (int)($summary['total_queries'] ?? 0),
                'captured_unique_fingerprints' => (int)($summary['unique_fingerprints'] ?? 0),
                'instrumented_connections' => 0,
                'connections_with_queries' => 0,
                'queries_with_source' => 0,
                'queries_with_caller' => 0,
                'queries_with_test_context' => 0,
                'queries_with_connection' => 0,
                'queries_by_capture_method' => [],
            ],
            'calculable' => [
                'source_context_coverage_pct' => null,
                'caller_context_coverage_pct' => null,
                'test_context_coverage_pct' => null,
                'connection_context_coverage_pct' => null,
            ],
            'unknown' => [
                'total_application_queries' => null,
                'overall_capture_coverage_pct' => null,
                'overall_capture_coverage_status' => 'unknown',
                'reason' => 'Legacy report does not expose instrumentation coverage.',
            ],
        ];
    }

    /** @param array<int,array<string,string>> $items @return array<int,array<string,string>> */
    private static function dedupeRecommendations(array $items): array
    {
        $unique = [];
        foreach ($items as $item) {
            $key = (string)($item['code'] ?? '') . '|' . (string)($item['recommendation'] ?? '');
            $unique[$key] = $item;
        }
        ksort($unique);
        return array_values($unique);
    }
}
