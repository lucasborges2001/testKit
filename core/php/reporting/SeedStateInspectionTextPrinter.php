<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

final class SeedStateInspectionTextPrinter
{
    /** @param array<string,mixed> $payload */
    public static function print(array $payload): void
    {
        echo 'inspect seed-state' . PHP_EOL;
        echo str_repeat('=', 72) . PHP_EOL;
        echo 'run_id: ' . (string)($payload['run_id'] ?? '') . PHP_EOL;
        echo 'report_root: ' . (string)($payload['report_scope_rel'] ?? $payload['report_root'] ?? '') . PHP_EOL;

        $metaSummary = is_array($payload['meta_summary'] ?? null) ? $payload['meta_summary'] : [];
        $summary = is_array($metaSummary['summary'] ?? null) ? $metaSummary['summary'] : [];
        echo 'summary: total=' . (int)($summary['total'] ?? 0)
            . ' pass=' . (int)($summary['passed'] ?? 0)
            . ' fail=' . (int)($summary['failed'] ?? 0)
            . ' skip=' . (int)($summary['skipped'] ?? 0)
            . ' time_ms=' . (int)($summary['duration_ms'] ?? 0)
            . PHP_EOL;

        $selected = $payload['selected_seed_state'] ?? null;
        if (is_array($selected)) {
            echo PHP_EOL . 'selected_seed_state:' . PHP_EOL;
            self::printSeedStateBlock($selected, '  ');
        }

        self::printSuiteSeedStates($payload['suite_seed_states'] ?? []);
        self::printBaselineManifests($payload['baseline_manifests'] ?? []);
    }

    /** @param mixed $suiteSeedStates */
    private static function printSuiteSeedStates(mixed $suiteSeedStates): void
    {
        $rows = is_array($suiteSeedStates) ? $suiteSeedStates : [];
        if ($rows === []) {
            echo PHP_EOL . 'suite_seed_states: none' . PHP_EOL;
            return;
        }

        echo PHP_EOL . 'suite_seed_states:' . PHP_EOL;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            echo '  - suite_id=' . (string)($row['suite_id'] ?? '')
                . ' final_status=' . (string)($row['final_status'] ?? '')
                . ' profile=' . (string)($row['profile'] ?? '')
                . ' baseline_mode=' . (string)($row['baseline_mode'] ?? '')
                . ' strategy=' . (string)($row['store_strategy'] ?? '')
                . PHP_EOL;
            if ((string)($row['manifest_path'] ?? '') !== '') {
                echo '    manifest_path: ' . (string)$row['manifest_path'] . PHP_EOL;
            }
            if ((string)($row['snapshot_file'] ?? '') !== '') {
                echo '    snapshot_file: ' . (string)$row['snapshot_file'] . PHP_EOL;
            }
            echo '    requested: ' . implode(', ', SeedStateInspectionPayloadNormalizer::stringList($row['requested_migrations'] ?? [])) . PHP_EOL;
            echo '    applied: ' . implode(', ', SeedStateInspectionPayloadNormalizer::stringList($row['applied_migrations'] ?? [])) . PHP_EOL;
            echo '    pending: ' . implode(', ', SeedStateInspectionPayloadNormalizer::stringList($row['pending_migrations'] ?? [])) . PHP_EOL;
        }
    }

    /** @param mixed $manifests */
    private static function printBaselineManifests(mixed $manifests): void
    {
        $rows = is_array($manifests) ? $manifests : [];
        if ($rows === []) {
            echo PHP_EOL . 'baseline_manifests: none' . PHP_EOL;
            return;
        }

        echo PHP_EOL . 'baseline_manifests:' . PHP_EOL;
        foreach ($rows as $manifest) {
            if (!is_array($manifest)) {
                continue;
            }
            echo '  - driver=' . (string)($manifest['driver'] ?? '')
                . ' db=' . (string)($manifest['db_name'] ?? '')
                . ' mode=' . (string)($manifest['baseline_mode'] ?? '')
                . ' status=' . (string)($manifest['status'] ?? '')
                . ' path=' . (string)($manifest['path'] ?? '')
                . PHP_EOL;
        }
    }

    /** @param array<string,mixed> $row */
    private static function printSeedStateBlock(array $row, string $indent): void
    {
        echo $indent . 'suite_id: ' . (string)($row['suite_id'] ?? '') . PHP_EOL;
        echo $indent . 'baseline: ' . (string)($row['baseline'] ?? '') . PHP_EOL;
        echo $indent . 'baseline_mode: ' . (string)($row['baseline_mode'] ?? '') . PHP_EOL;
        echo $indent . 'profile: ' . (string)($row['profile'] ?? '') . PHP_EOL;
        echo $indent . 'source_kind: ' . (string)($row['source_kind'] ?? '') . PHP_EOL;
        echo $indent . 'store_strategy: ' . (string)($row['store_strategy'] ?? '') . PHP_EOL;
        if ((string)($row['manifest_path'] ?? '') !== '') {
            echo $indent . 'manifest_path: ' . (string)$row['manifest_path'] . PHP_EOL;
        }
        if ((string)($row['snapshot_file'] ?? '') !== '') {
            echo $indent . 'snapshot_file: ' . (string)$row['snapshot_file'] . PHP_EOL;
        }
        echo $indent . 'requested_migrations: ' . implode(', ', SeedStateInspectionPayloadNormalizer::stringList($row['requested_migrations'] ?? [])) . PHP_EOL;
        echo $indent . 'applied_migrations: ' . implode(', ', SeedStateInspectionPayloadNormalizer::stringList($row['applied_migrations'] ?? [])) . PHP_EOL;
        echo $indent . 'pending_migrations: ' . implode(', ', SeedStateInspectionPayloadNormalizer::stringList($row['pending_migrations'] ?? [])) . PHP_EOL;
        echo $indent . 'historical_absorbed: ' . implode(', ', SeedStateInspectionPayloadNormalizer::stringList($row['historical_absorbed'] ?? [])) . PHP_EOL;
    }
}
