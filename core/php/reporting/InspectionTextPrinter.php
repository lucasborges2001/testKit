<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

final class InspectionTextPrinter
{
    public static function printHelp(): void
    {
        echo "Usage:\n";
        echo "  php scripts/inspect.php latest [--run=<id>] [--json]\n";
        echo "  php scripts/inspect.php run <run_id> [--json]\n";
        echo "  php scripts/inspect.php failure [--latest] [--run=<id>] [--json]\n";
        echo "  php scripts/inspect.php seed-state [--run=<id>] [--suite=<suite>] [--json]\n";
        echo "  php scripts/inspect.php concurrency [--run=<id>] [--json]\n";
    }

    /** @param array<string,mixed> $payload */
    public static function print(string $command, array $payload): void
    {
        echo "inspect {$command}" . PHP_EOL;
        echo str_repeat('=', 72) . PHP_EOL;
        echo 'run_id: ' . (string)($payload['run_id'] ?? '') . PHP_EOL;
        echo 'report_root: ' . (string)($payload['report_scope_rel'] ?? $payload['report_root'] ?? '') . PHP_EOL;

        $agentDecision = is_array($payload['agent_decision'] ?? null) ? $payload['agent_decision'] : [];
        if ($agentDecision !== []) {
            echo 'outcome_status: ' . (string)($agentDecision['outcome_status'] ?? '') . PHP_EOL;
            echo 'evidence_valid: ' . ((bool)($agentDecision['evidence_valid'] ?? false) ? 'true' : 'false') . PHP_EOL;
        }

        if (in_array($command, ['latest', 'run'], true)) {
            self::printMetaSummaryText((array)($payload['meta_summary'] ?? []));
            self::printSuiteReportsText((array)($payload['suite_reports'] ?? []));
            self::printAgentRunArtifactText($payload['agent_run_artifact'] ?? null);
        }

        if (in_array($command, ['latest', 'run', 'failure'], true)) {
            self::printFirstFailureText($payload['first_actionable_failure'] ?? $payload['first_failure'] ?? null);
        }

        if ($command === 'seed-state') {
            self::printSeedStateText($payload);
        }
        if ($command === 'concurrency') {
            self::printConcurrencyText($payload);
        }

        self::printNextActionText($payload['next_action'] ?? ($agentDecision['next_action'] ?? null));
    }

    /** @param array<string,mixed> $summary */
    private static function printMetaSummaryText(array $summary): void
    {
        $row = is_array($summary['summary'] ?? null) ? $summary['summary'] : [];
        echo 'summary: total=' . (int)($row['total'] ?? 0)
            . ' pass=' . (int)($row['passed'] ?? 0)
            . ' fail=' . (int)($row['failed'] ?? 0)
            . ' skip=' . (int)($row['skipped'] ?? 0)
            . ' timeout=' . (int)($row['timeouts'] ?? 0)
            . ' time_ms=' . (int)($row['duration_ms'] ?? 0)
            . PHP_EOL;
    }

    /** @param array<int,array<string,mixed>> $suiteReports */
    private static function printSuiteReportsText(array $suiteReports): void
    {
        if ($suiteReports === []) {
            echo 'suite_reports: none' . PHP_EOL;
            return;
        }
        echo 'suite_reports:' . PHP_EOL;
        foreach ($suiteReports as $report) {
            echo '  - ' . (string)($report['suite_id'] ?? 'suite')
                . ' status=' . (string)($report['suite_status'] ?? $report['final_status'] ?? '')
                . ' outcome=' . (string)($report['outcome_status'] ?? '')
                . ' tests=' . (int)($report['selected_test_count'] ?? 0)
                . ' fail=' . (int)($report['fail'] ?? 0)
                . ' warnings=' . count((array)($report['warnings'] ?? []))
                . ' scope=' . (string)($report['selected_module_scope'] ?? 'global')
                . PHP_EOL;
        }
    }

    private static function printFirstFailureText(mixed $failure): void
    {
        if (!is_array($failure) || $failure === []) {
            echo 'first_actionable_failure: none' . PHP_EOL;
            return;
        }
        echo 'first_actionable_failure:' . PHP_EOL;
        foreach (['suite_id', 'file', 'case', 'kind', 'phase', 'failure_domain', 'cause_code', 'message', 'artifact_path'] as $key) {
            echo '  ' . $key . ': ' . (string)($failure[$key] ?? '') . PHP_EOL;
        }
    }

    private static function printNextActionText(mixed $action): void
    {
        if (!is_array($action) || $action === []) {
            echo 'next_action: unavailable' . PHP_EOL;
            return;
        }
        echo 'next_action: ' . (string)($action['kind'] ?? '')
            . ' confidence=' . (string)($action['confidence'] ?? '') . PHP_EOL;
        echo 'reason: ' . (string)($action['reason'] ?? '') . PHP_EOL;
        if ((string)($action['command'] ?? '') !== '') {
            echo 'command: ' . (string)$action['command'] . PHP_EOL;
        }
    }

    private static function printAgentRunArtifactText(mixed $artifact): void
    {
        if (!is_array($artifact) || $artifact === []) {
            echo 'agent_run_artifact: none' . PHP_EOL;
            return;
        }
        echo 'agent_run_artifact:' . PHP_EOL;
        echo '  kind: ' . (string)($artifact['kind'] ?? '') . PHP_EOL;
        echo '  reason: ' . (string)($artifact['reason'] ?? '') . PHP_EOL;
        echo '  executed: ' . ((bool)($artifact['executed'] ?? false) ? 'true' : 'false') . PHP_EOL;
        echo '  exit_code: ' . (int)($artifact['exit_code'] ?? 0) . PHP_EOL;
        if ((string)($artifact['latest_path'] ?? '') !== '') {
            echo '  latest_path: ' . (string)$artifact['latest_path'] . PHP_EOL;
        }
    }

    /** @param array<string,mixed> $payload */
    private static function printSeedStateText(array $payload): void
    {
        $contract = is_array($payload['migration_contract'] ?? null) ? $payload['migration_contract'] : null;
        if (!is_array($contract)) {
            echo 'migration_contract: none' . PHP_EOL;
            return;
        }
        echo 'migration_contract:' . PHP_EOL;
        echo '  baseline_mode: ' . (string)($contract['baseline_mode'] ?? '') . PHP_EOL;
        echo '  snapshot_file: ' . (string)($contract['snapshot_file'] ?? '') . PHP_EOL;
        echo '  manifest_path: ' . (string)($contract['manifest_path'] ?? '') . PHP_EOL;
    }

    /** @param array<string,mixed> $payload */
    private static function printConcurrencyText(array $payload): void
    {
        $locks = is_array($payload['active_locks'] ?? null) ? $payload['active_locks'] : [];
        echo 'active_locks: ' . count($locks) . PHP_EOL;
        $policies = is_array($payload['suite_policies'] ?? null) ? $payload['suite_policies'] : [];
        echo 'suite_policies: ' . count($policies) . PHP_EOL;
    }
}
