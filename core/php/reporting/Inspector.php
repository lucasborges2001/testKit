<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Testkit\Core\Common\Paths;

final class Inspector
{
    /**
     * @param array<int,string> $argv
     */
    public static function runCli(array $argv): int
    {
        [$command, $positionals, $options] = self::parseArgs($argv);

        if ($command === '' || in_array($command, ['help', '--help', '-h'], true)) {
            self::printHelp();
            return 0;
        }

        try {
            $payload = match ($command) {
                'latest' => self::buildLatestInspection((string)($options['run'] ?? '')),
                'run' => self::buildRunInspection((string)($positionals[0] ?? '')),
                'failure' => self::buildFailureInspection((string)($options['run'] ?? ''), (bool)($options['latest'] ?? false)),
                'seed-state' => self::buildSeedStateInspection((string)($options['run'] ?? ''), self::normalizeSuiteId((string)($options['suite'] ?? ''))),
                'concurrency' => self::buildConcurrencyInspection((string)($options['run'] ?? '')),
                default => null,
            };
        } catch (\Throwable $e) {
            if ((bool)($options['json'] ?? false)) {
                self::printJson(['ok' => false, 'error' => $e->getMessage()]);
            } else {
                fwrite(STDERR, 'inspect error: ' . $e->getMessage() . PHP_EOL);
            }
            return 2;
        }

        if (!is_array($payload)) {
            if ((bool)($options['json'] ?? false)) {
                self::printJson(['ok' => false, 'error' => 'unknown inspect command']);
            } else {
                fwrite(STDERR, "inspect error: unknown command '{$command}'" . PHP_EOL);
                self::printHelp();
            }
            return 2;
        }

        if ((bool)($options['json'] ?? false)) {
            self::printJson($payload);
            return 0;
        }

        self::printText($command, $payload);
        return 0;
    }

    /**
     * @param array<int,string> $argv
     * @return array{0:string,1:array<int,string>,2:array<string,mixed>}
     */
    private static function parseArgs(array $argv): array
    {
        $args = array_values(array_slice($argv, 1));
        $options = ['json' => false, 'latest' => false, 'suite' => '', 'run' => ''];
        $positionals = [];

        foreach ($args as $arg) {
            if ($arg === '--json') {
                $options['json'] = true;
                continue;
            }
            if ($arg === '--latest') {
                $options['latest'] = true;
                continue;
            }
            if (str_starts_with($arg, '--suite=')) {
                $options['suite'] = substr($arg, strlen('--suite='));
                continue;
            }
            if (str_starts_with($arg, '--run=')) {
                $options['run'] = substr($arg, strlen('--run='));
                continue;
            }
            $positionals[] = $arg;
        }

        $command = strtolower(trim((string)($positionals[0] ?? '')));
        $positionals = array_values(array_slice($positionals, $command !== '' ? 1 : 0));
        return [$command, $positionals, $options];
    }

    /** @return array<string,mixed> */
    private static function buildLatestInspection(string $runId = ''): array
    {
        [$context, $meta, $suiteReports, $decision] = self::loadInspectionContext($runId);

        return [
            'ok' => true,
            'command' => 'latest',
            'inspect_contract' => 'agent_decision_v1',
            'run_id' => (string)$context['run_id'],
            'report_root' => (string)$context['report_root'],
            'report_scope_rel' => (string)$context['report_scope_rel'],
            'meta_summary' => self::metaSummary($meta, $suiteReports),
            'suite_reports' => array_values(array_map(static fn(array $report): array => self::suiteSummary($report), $suiteReports)),
            'warnings' => self::collectWarnings($meta, $suiteReports),
            'first_failure' => $decision['first_actionable_failure'] ?? null,
            'first_actionable_failure' => $decision['first_actionable_failure'] ?? null,
            'agent_decision' => $decision['agent_decision'] ?? null,
            'next_action' => $decision['next_action'] ?? null,
            'decision_basis' => $decision['decision_basis'] ?? null,
            'agent_run_artifact' => self::loadAgentRunArtifact((string)$context['report_root']),
            'artifacts' => [
                'latest_run_manifest' => Paths::relativeToRepo(Paths::latestRunManifestPath()),
                'runs_index' => Paths::relativeToRepo(Paths::reportsRoot() . '/runs_latest.json'),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function buildRunInspection(string $runId): array
    {
        $runId = trim($runId);
        if ($runId === '') {
            throw new \RuntimeException('inspect run requiere un run id');
        }

        [$context, $meta, $suiteReports, $decision] = self::loadInspectionContext($runId);

        return [
            'ok' => true,
            'command' => 'run',
            'inspect_contract' => 'agent_decision_v1',
            'run_id' => (string)$context['run_id'],
            'report_root' => (string)$context['report_root'],
            'report_scope_rel' => (string)$context['report_scope_rel'],
            'meta_report' => $meta,
            'suite_reports' => array_values(array_map(static fn(array $report): array => self::suiteSummary($report), $suiteReports)),
            'warnings' => self::collectWarnings($meta, $suiteReports),
            'first_failure' => $decision['first_actionable_failure'] ?? null,
            'first_actionable_failure' => $decision['first_actionable_failure'] ?? null,
            'agent_decision' => $decision['agent_decision'] ?? null,
            'next_action' => $decision['next_action'] ?? null,
            'decision_basis' => $decision['decision_basis'] ?? null,
            'agent_run_artifact' => self::loadAgentRunArtifact((string)$context['report_root']),
        ];
    }

    /** @return array<string,mixed> */
    private static function buildFailureInspection(string $runId = '', bool $latest = false): array
    {
        [$context, $meta, $suiteReports, $decision] = self::loadInspectionContext($runId);
        $firstFailure = $decision['first_actionable_failure'] ?? null;

        return [
            'ok' => true,
            'command' => 'failure',
            'inspect_contract' => 'agent_decision_v1',
            'requested_latest' => $latest,
            'run_id' => (string)$context['run_id'],
            'report_root' => (string)$context['report_root'],
            'report_scope_rel' => (string)$context['report_scope_rel'],
            'evidence_valid' => (bool)($decision['evidence_valid'] ?? false),
            'outcome_status' => (string)($decision['outcome_status'] ?? ''),
            'warnings' => self::collectWarnings($meta, $suiteReports),
            'first_failure' => $firstFailure,
            'first_actionable_failure' => $firstFailure,
            'has_failure' => is_array($firstFailure),
            'agent_decision' => $decision['agent_decision'] ?? null,
            'next_action' => $decision['next_action'] ?? null,
            'decision_basis' => $decision['decision_basis'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private static function buildSeedStateInspection(string $runId = '', string $suiteId = ''): array
    {
        [$context, $meta, $suiteReports, $decision] = self::loadInspectionContext($runId);
        $manifests = self::loadBaselineManifests();
        $migrationContract = self::loadMigrationContractReport((string)$context['report_root']);

        if ($suiteId !== '') {
            foreach ($suiteReports as $report) {
                if ((string)($report['suite_id'] ?? '') === $suiteId) {
                    $migrationContract = $report;
                    break;
                }
            }
        }

        $migrationContractPayload = null;
        if (is_array($migrationContract)) {
            $canonical = is_array($migrationContract['canonical_report'] ?? null) ? $migrationContract['canonical_report'] : [];
            $seedState = is_array($canonical['seed_state'] ?? null) ? $canonical['seed_state'] : (is_array($migrationContract['seed_state'] ?? null) ? $migrationContract['seed_state'] : []);
            $selection = AgentDecisionBuilder::deriveSelection(null, [$migrationContract]);
            $migrationContractPayload = [
                'suite_id' => (string)($selection['primary_suite_id'] ?? $migrationContract['suite_id'] ?? ''),
                'baseline_mode' => (string)($seedState['baseline_mode'] ?? ''),
                'snapshot_file' => (string)($seedState['snapshot_file'] ?? ''),
                'manifest_path' => (string)($seedState['manifest_path'] ?? ''),
                'migration_state' => $seedState['migration_state'] ?? null,
                'applied_migrations' => array_values((array)($seedState['applied_migrations'] ?? [])),
                'pending_migrations' => array_values((array)($seedState['pending_migrations'] ?? [])),
            ];
        }

        return [
            'ok' => true,
            'command' => 'seed-state',
            'inspect_contract' => 'agent_decision_v1',
            'run_id' => (string)$context['run_id'],
            'report_root' => (string)$context['report_root'],
            'report_scope_rel' => (string)$context['report_scope_rel'],
            'meta_summary' => self::metaSummary($meta, $suiteReports),
            'warnings' => self::collectWarnings($meta, $suiteReports),
            'baseline_manifests' => $manifests,
            'migration_contract' => $migrationContractPayload,
            'agent_decision' => $decision['agent_decision'] ?? null,
            'next_action' => $decision['next_action'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private static function buildConcurrencyInspection(string $runId = ''): array
    {
        [$context, $meta, $suiteReports, $decision] = self::loadInspectionContext($runId);

        $suitePolicies = [];
        foreach ($suiteReports as $report) {
            $suitePolicies[] = [
                'suite_id' => (string)($report['suite_id'] ?? ''),
                'parallel_policy' => is_array($report['parallel_policy'] ?? null) ? $report['parallel_policy'] : null,
                'concurrency_admission' => is_array($report['concurrency_admission'] ?? null) ? $report['concurrency_admission'] : null,
                'warnings' => self::normalizeWarnings($report['warnings'] ?? ($report['parallel_policy']['warnings'] ?? [])),
                'evidence_valid' => self::reportEvidenceValid($report),
            ];
        }

        return [
            'ok' => true,
            'command' => 'concurrency',
            'inspect_contract' => 'agent_decision_v1',
            'run_id' => (string)$context['run_id'],
            'report_root' => (string)$context['report_root'],
            'report_scope_rel' => (string)$context['report_scope_rel'],
            'evidence_valid' => (bool)($decision['evidence_valid'] ?? false),
            'outcome_status' => (string)($decision['outcome_status'] ?? ''),
            'warnings' => self::collectWarnings($meta, $suiteReports),
            'active_locks' => self::loadActiveLocks(),
            'suite_policies' => $suitePolicies,
            'agent_decision' => $decision['agent_decision'] ?? null,
            'next_action' => $decision['next_action'] ?? null,
        ];
    }

    /**
     * @return array{0:array<string,mixed>,1:?array<string,mixed>,2:array<int,array<string,mixed>>,3:array<string,mixed>}
     */
    private static function loadInspectionContext(string $runId): array
    {
        $context = AgentDecisionBuilder::resolveRunContext($runId);
        $meta = AgentDecisionBuilder::loadMetaReport((string)$context['report_root']);
        $suiteReports = AgentDecisionBuilder::loadSuiteReports((string)$context['report_root']);
        if (!is_array($meta) && $suiteReports === []) {
            throw new \RuntimeException('inspect no encontró reportes en ' . Paths::relativeToRepo((string)$context['report_root']));
        }
        $decision = AgentDecisionBuilder::buildFromContext($context, $meta, $suiteReports);
        return [$context, $meta, $suiteReports, $decision];
    }

    /** @return array<string,mixed>|null */
    private static function loadMigrationContractReport(string $reportRoot): ?array
    {
        $candidate = rtrim($reportRoot, '/\\') . '/migration_contract_latest.json';
        $json = AgentDecisionBuilder::loadJsonFile($candidate);
        if (!is_array($json)) {
            return null;
        }
        $json['_source_file'] = $candidate;
        return $json;
    }

    /** @return array<int,array<string,mixed>> */
    private static function loadBaselineManifests(): array
    {
        $rows = [];
        foreach (glob(Paths::artifactsRoot() . '/baselines/*/*.manifest.json') ?: [] as $file) {
            $json = AgentDecisionBuilder::loadJsonFile($file);
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

    /** @return array<int,array<string,mixed>> */
    private static function loadActiveLocks(): array
    {
        $rows = [];
        $lockRoot = Paths::outRoot() . '/locks';
        if (!is_dir($lockRoot)) {
            return [];
        }

        foreach (glob($lockRoot . '/*/owner.json') ?: [] as $ownerFile) {
            $json = AgentDecisionBuilder::loadJsonFile($ownerFile);
            if (!is_array($json)) {
                continue;
            }
            $rows[] = [
                'name' => (string)($json['name'] ?? basename(dirname($ownerFile))),
                'path' => Paths::relativeToRepo(dirname($ownerFile)),
                'run_id' => (string)($json['run_id'] ?? ''),
                'meta_run_id' => (string)($json['meta_run_id'] ?? ''),
                'pid' => $json['pid'] ?? null,
                'hostname' => (string)($json['hostname'] ?? ''),
                'acquired_at' => (string)($json['acquired_at'] ?? ''),
            ];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
        return $rows;
    }

    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     * @return array<string,mixed>
     */
    private static function metaSummary(?array $meta, array $suiteReports): array
    {
        if (is_array($meta)) {
            $canonical = is_array($meta['canonical_report'] ?? null) ? $meta['canonical_report'] : [];
            $selection = AgentDecisionBuilder::deriveSelection($meta, $suiteReports);
            return [
                'final_status' => (string)($canonical['final_status'] ?? $meta['final_status'] ?? ''),
                'outcome_status' => (string)($meta['outcome_status'] ?? ''),
                'summary' => is_array($canonical['summary'] ?? null) ? $canonical['summary'] : (is_array($meta['summary'] ?? null) ? $meta['summary'] : []),
                'selection' => $selection,
            ];
        }

        $total = ['total' => 0, 'passed' => 0, 'failed' => 0, 'skipped' => 0, 'timeouts' => 0, 'duration_ms' => 0];
        foreach ($suiteReports as $report) {
            $summary = self::summaryFromReport($report);
            $total['total'] += (int)($summary['total'] ?? 0);
            $total['passed'] += (int)($summary['passed'] ?? 0);
            $total['failed'] += (int)($summary['failed'] ?? 0);
            $total['skipped'] += (int)($summary['skipped'] ?? 0);
            $total['timeouts'] += (int)($summary['timeouts'] ?? 0);
            $total['duration_ms'] += (int)($summary['duration_ms'] ?? 0);
        }

        return ['summary' => $total, 'selection' => AgentDecisionBuilder::deriveSelection(null, $suiteReports)];
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private static function suiteSummary(array $report): array
    {
        $canonical = is_array($report['canonical_report'] ?? null) ? $report['canonical_report'] : [];
        $selection = AgentDecisionBuilder::deriveSelection(null, [$report]);
        $summary = self::summaryFromReport($report);
        $evidence = is_array($canonical['evidence'] ?? null) ? $canonical['evidence'] : [];

        return [
            'suite_id' => (string)($report['suite_id'] ?? $selection['primary_suite_id'] ?? ''),
            'suite_status' => (string)($report['suite_status'] ?? $canonical['final_status'] ?? ''),
            'outcome_status' => (string)($report['outcome_status'] ?? ''),
            'final_status' => (string)($canonical['final_status'] ?? ''),
            'selected_test_count' => (int)($selection['selected_test_count'] ?? 0),
            'selected_module_scope' => (string)($selection['selected_module_scope'] ?? ''),
            'pass' => (int)($report['pass'] ?? $summary['passed'] ?? 0),
            'fail' => (int)($report['fail'] ?? $summary['failed'] ?? 0),
            'skip' => (int)($report['skip'] ?? $summary['skipped'] ?? 0),
            'duration_ms' => (int)($summary['duration_ms'] ?? $report['duration_ms'] ?? 0),
            'warnings' => self::normalizeWarnings($canonical['warnings'] ?? $report['warnings'] ?? []),
            'evidence_valid' => (bool)($evidence['valid'] ?? $report['evidence_valid'] ?? true),
            'artifact_path' => Paths::relativeToRepo((string)($report['_source_file'] ?? '')),
        ];
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private static function summaryFromReport(array $report): array
    {
        $canonical = is_array($report['canonical_report'] ?? null) ? $report['canonical_report'] : [];
        $summary = is_array($canonical['summary'] ?? null) ? $canonical['summary'] : (is_array($report['summary'] ?? null) ? $report['summary'] : []);
        return [
            'total' => (int)($summary['total'] ?? $report['tests_total'] ?? 0),
            'passed' => (int)($summary['passed'] ?? $report['pass'] ?? 0),
            'failed' => (int)($summary['failed'] ?? $report['fail'] ?? 0),
            'skipped' => (int)($summary['skipped'] ?? $report['skip'] ?? 0),
            'timeouts' => (int)($summary['timeouts'] ?? $report['timeout'] ?? 0),
            'duration_ms' => (int)($summary['duration_ms'] ?? $report['duration_ms'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     * @return array<int,array<string,mixed>>
     */
    private static function collectWarnings(?array $meta, array $suiteReports): array
    {
        $warnings = [];
        $reports = [];
        if (is_array($meta)) {
            $reports[] = $meta;
        }
        foreach ($suiteReports as $report) {
            $reports[] = $report;
        }
        foreach ($reports as $report) {
            $canonical = is_array($report['canonical_report'] ?? null) ? $report['canonical_report'] : [];
            foreach ([$canonical['warnings'] ?? null, $report['warnings'] ?? null] as $source) {
                if (!is_array($source)) {
                    continue;
                }
                foreach ($source as $row) {
                    if (is_array($row)) {
                        $warnings[] = $row;
                    } elseif (is_scalar($row)) {
                        $warnings[] = ['code' => 'GENERIC_WARNING', 'summary' => (string)$row];
                    }
                }
            }
        }
        return self::normalizeWarnings($warnings);
    }

    /** @return array<string,mixed>|null */
    private static function loadAgentRunArtifact(string $reportRoot): ?array
    {
        $artifact = AgentRunArtifact::loadLatestForRunRoot($reportRoot);
        if (!is_array($artifact)) {
            return null;
        }

        $execution = is_array($artifact['execution'] ?? null) ? $artifact['execution'] : [];
        $result = is_array($execution['result'] ?? null) ? $execution['result'] : [];
        $decision = is_array($artifact['decision'] ?? null) ? $artifact['decision'] : [];

        return [
            'artifact_kind' => (string)($artifact['artifact_kind'] ?? ''),
            'generated_at' => (string)($artifact['generated_at'] ?? ''),
            'source_file' => Paths::relativeToRepo((string)($artifact['_source_file'] ?? '')),
            'latest_path' => (string)($artifact['artifact_paths']['latest'] ?? ''),
            'timestamped_path' => (string)($artifact['artifact_paths']['timestamped'] ?? ''),
            'executed' => (bool)($execution['executed'] ?? false),
            'kind' => (string)($execution['kind'] ?? ($decision['next_action']['kind'] ?? '')),
            'reason' => (string)($execution['reason'] ?? ($decision['next_action']['reason'] ?? '')),
            'exit_code' => (int)($result['exit_code'] ?? 0),
            'agent_decision' => $artifact['agent_decision'] ?? ($decision['agent_decision'] ?? null),
            'next_action' => $decision['next_action'] ?? null,
        ];
    }

    /** @param array<string,mixed> $report */
    private static function reportEvidenceValid(array $report): bool
    {
        $canonical = is_array($report['canonical_report'] ?? null) ? $report['canonical_report'] : [];
        $evidence = is_array($canonical['evidence'] ?? null) ? $canonical['evidence'] : [];
        return (bool)($evidence['valid'] ?? $report['evidence_valid'] ?? true);
    }

    /** @return array<int,array<string,mixed>> */
    private static function normalizeWarnings(mixed $warnings): array
    {
        if (class_exists(StructuredWarnings::class)) {
            return StructuredWarnings::canonicalize($warnings);
        }
        return is_array($warnings) ? array_values(array_filter($warnings, 'is_array')) : [];
    }

    private static function normalizeSuiteId(string $suiteId): string
    {
        return str_replace('-', '_', strtolower(trim($suiteId)));
    }

    private static function printHelp(): void
    {
        echo "Usage:\n";
        echo "  php scripts/inspect.php latest [--run=<id>] [--json]\n";
        echo "  php scripts/inspect.php run <run_id> [--json]\n";
        echo "  php scripts/inspect.php failure [--latest] [--run=<id>] [--json]\n";
        echo "  php scripts/inspect.php seed-state [--run=<id>] [--suite=<suite>] [--json]\n";
        echo "  php scripts/inspect.php concurrency [--run=<id>] [--json]\n";
    }

    /** @param array<string,mixed> $payload */
    private static function printJson(array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException('no se pudo serializar la salida JSON de inspect');
        }
        echo $json . PHP_EOL;
    }

    /** @param array<string,mixed> $payload */
    private static function printText(string $command, array $payload): void
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
