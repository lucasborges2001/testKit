<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

final class SeedStateInspectionPayloadNormalizer
{
    /**
     * @param array<int,array<string,mixed>> $suiteReports
     * @return array<int,array<string,mixed>>
     */
    public static function suiteSeedStates(array $suiteReports): array
    {
        $rows = [];

        foreach ($suiteReports as $report) {
            $canonical = SeedStateInspectionReportLoader::requireCanonicalReport($report, 'suite report');
            $seedState = $canonical['seed_state'] ?? null;
            if (!is_array($seedState) || $seedState === []) {
                continue;
            }

            $selection = is_array($canonical['selection'] ?? null) ? $canonical['selection'] : [];
            $summary = is_array($canonical['summary'] ?? null) ? $canonical['summary'] : [];
            $evidence = is_array($canonical['evidence'] ?? null) ? $canonical['evidence'] : [];
            $artifacts = is_array($canonical['artifacts'] ?? null) ? $canonical['artifacts'] : [];

            $rows[] = [
                'suite_id' => (string)($selection['suite_id'] ?? $report['suite_id'] ?? ''),
                'final_status' => (string)($canonical['final_status'] ?? ''),
                'evidence_valid' => (bool)($evidence['valid'] ?? true),
                'selected_test_count' => (int)($selection['selected_test_count'] ?? $summary['total'] ?? 0),
                'report_scope_rel' => (string)($artifacts['report_scope_rel'] ?? $report['report_scope_rel'] ?? ''),
                'baseline' => (string)($seedState['baseline'] ?? ''),
                'baseline_mode' => (string)($seedState['baseline_mode'] ?? ''),
                'profile' => (string)($seedState['profile'] ?? ''),
                'source_kind' => (string)($seedState['source_kind'] ?? ''),
                'store_strategy' => (string)($seedState['store_strategy'] ?? ''),
                'manifest_path' => (string)($seedState['manifest_path'] ?? ''),
                'snapshot_file' => (string)($seedState['snapshot_file'] ?? ''),
                'requested_migrations' => self::stringList($seedState['requested_migrations'] ?? []),
                'applied_migrations' => self::stringList($seedState['applied_migrations'] ?? []),
                'pending_migrations' => self::stringList($seedState['pending_migrations'] ?? []),
                'historical_absorbed' => self::stringList($seedState['historical_absorbed'] ?? []),
                'migration_state' => is_array($seedState['migration_state'] ?? null) ? $seedState['migration_state'] : null,
                'resolved_snapshot' => is_array($seedState['resolved_snapshot'] ?? null) ? $seedState['resolved_snapshot'] : null,
            ];
        }

        usort($rows, static fn(array $a, array $b): int => strcmp((string)($a['suite_id'] ?? ''), (string)($b['suite_id'] ?? '')));
        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $suiteSeedStates
     * @return array<string,mixed>|null
     */
    public static function selectSeedState(array $suiteSeedStates, string $suiteId): ?array
    {
        if ($suiteId !== '') {
            foreach ($suiteSeedStates as $row) {
                if ((string)($row['suite_id'] ?? '') === $suiteId) {
                    return $row;
                }
            }
            return null;
        }

        return count($suiteSeedStates) === 1 ? $suiteSeedStates[0] : null;
    }

    /**
     * @param array<int,array<string,mixed>> $suiteReports
     * @return array<string,mixed>|null
     */
    public static function migrationContractPayload(array $suiteReports): ?array
    {
        foreach ($suiteReports as $report) {
            $suiteId = (string)($report['suite_id'] ?? '');
            if ($suiteId !== 'migration_contract') {
                continue;
            }

            $canonical = SeedStateInspectionReportLoader::requireCanonicalReport($report, 'migration contract report');
            $seedState = $canonical['seed_state'] ?? null;
            if (!is_array($seedState) || $seedState === []) {
                return null;
            }

            $selection = is_array($canonical['selection'] ?? null) ? $canonical['selection'] : [];

            return [
                'suite_id' => (string)($selection['suite_id'] ?? $suiteId),
                'baseline' => (string)($seedState['baseline'] ?? ''),
                'baseline_mode' => (string)($seedState['baseline_mode'] ?? ''),
                'profile' => (string)($seedState['profile'] ?? ''),
                'source_kind' => (string)($seedState['source_kind'] ?? ''),
                'store_strategy' => (string)($seedState['store_strategy'] ?? ''),
                'snapshot_file' => (string)($seedState['snapshot_file'] ?? ''),
                'manifest_path' => (string)($seedState['manifest_path'] ?? ''),
                'migration_state' => is_array($seedState['migration_state'] ?? null) ? $seedState['migration_state'] : null,
                'requested_migrations' => self::stringList($seedState['requested_migrations'] ?? []),
                'applied_migrations' => self::stringList($seedState['applied_migrations'] ?? []),
                'pending_migrations' => self::stringList($seedState['pending_migrations'] ?? []),
                'historical_absorbed' => self::stringList($seedState['historical_absorbed'] ?? []),
                'resolved_snapshot' => is_array($seedState['resolved_snapshot'] ?? null) ? $seedState['resolved_snapshot'] : null,
            ];
        }

        return null;
    }

    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     * @return array<string,mixed>
     */
    public static function metaSummary(?array $meta, array $suiteReports): array
    {
        if (is_array($meta)) {
            $canonical = SeedStateInspectionReportLoader::requireCanonicalReport($meta, 'meta report');
            $selection = is_array($canonical['selection'] ?? null) ? $canonical['selection'] : [];
            return [
                'target' => (string)($selection['target'] ?? ''),
                'selected_test_count' => (int)($selection['selected_test_count'] ?? 0),
                'summary' => is_array($canonical['summary'] ?? null) ? $canonical['summary'] : [],
                'suite_status_counts' => is_array($meta['suite_status_counts'] ?? null) ? $meta['suite_status_counts'] : [],
            ];
        }

        $summary = [
            'total' => 0,
            'passed' => 0,
            'failed' => 0,
            'skipped' => 0,
            'duration_ms' => 0,
        ];
        $selected = 0;

        foreach ($suiteReports as $report) {
            $canonical = SeedStateInspectionReportLoader::requireCanonicalReport($report, 'suite report');
            $selection = is_array($canonical['selection'] ?? null) ? $canonical['selection'] : [];
            $row = is_array($canonical['summary'] ?? null) ? $canonical['summary'] : [];
            $summary['total'] += (int)($row['total'] ?? 0);
            $summary['passed'] += (int)($row['passed'] ?? 0);
            $summary['failed'] += (int)($row['failed'] ?? 0);
            $summary['skipped'] += (int)($row['skipped'] ?? 0);
            $summary['duration_ms'] += (int)($row['duration_ms'] ?? 0);
            $selected += (int)($selection['selected_test_count'] ?? 0);
        }

        return [
            'target' => '',
            'selected_test_count' => $selected,
            'summary' => $summary,
            'suite_status_counts' => [],
        ];
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    public static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $rows[$item] = true;
            }
        }

        $out = array_keys($rows);
        natcasesort($out);
        return array_values($out);
    }
}
