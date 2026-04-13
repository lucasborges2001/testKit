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

        if ($command === '' || $command === 'help' || $command === '--help' || $command === '-h') {
            self::printHelp();
            return 0;
        }

        try {
            $payload = match ($command) {
                'latest' => self::buildLatestInspection((string)($options['run'] ?? '')),
                'run' => self::buildRunInspection((string)($positionals[0] ?? '')),
                'failure' => self::buildFailureInspection(
                    (string)($options['run'] ?? ''),
                    (bool)($options['latest'] ?? false)
                ),
                'seed-state' => self::buildSeedStateInspection(
                    (string)($options['run'] ?? ''),
                    self::normalizeSuiteId((string)($options['suite'] ?? ''))
                ),
                'concurrency' => self::buildConcurrencyInspection((string)($options['run'] ?? '')),
                default => null,
            };
        } catch (\Throwable $e) {
            if ((bool)($options['json'] ?? false)) {
                self::printJson([
                    'ok' => false,
                    'error' => $e->getMessage(),
                ]);
            } else {
                fwrite(STDERR, 'inspect error: ' . $e->getMessage() . PHP_EOL);
            }
            return 2;
        }

        if (!is_array($payload)) {
            if ((bool)($options['json'] ?? false)) {
                self::printJson([
                    'ok' => false,
                    'error' => 'unknown inspect command',
                ]);
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
        $options = [
            'json' => false,
            'latest' => false,
            'suite' => '',
            'run' => '',
        ];
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

    
    /**
     * @return array<string,mixed>
     */
    private static function buildLatestInspection(string $runId = ''): array
    {
        $context = self::resolveRunContext($runId);
        $meta = self::loadMetaReport($context['report_root']);
        $suiteReports = self::loadCanonicalSuiteReports($context['report_root']);
        self::assertCanonicalInspectContext($meta, $suiteReports, $context['report_root']);

        return [
            'ok' => true,
            'command' => 'latest',
            'inspect_contract' => 'canonical_only',
            'run_id' => $context['run_id'],
            'report_root' => $context['report_root'],
            'report_scope_rel' => $context['report_scope_rel'],
            'meta_summary' => self::metaSummary($meta, $suiteReports),
            'suite_reports' => array_values(array_map(
                static fn(array $report): array => self::suiteSummary($report),
                $suiteReports
            )),
            'first_failure' => self::deriveFirstFailure($meta, $suiteReports),
            'artifacts' => [
                'latest_run_manifest' => Paths::relativeToRepo(Paths::latestRunManifestPath()),
                'runs_index' => Paths::relativeToRepo(Paths::reportsRoot() . '/runs_latest.json'),
            ],
        ];
    }
    
    /**
     * @return array<string,mixed>
     */
    private static function buildRunInspection(string $runId): array
    {
        $runId = trim($runId);
        if ($runId === '') {
            throw new \RuntimeException('inspect run requiere un run id');
        }

        $context = self::resolveRunContext($runId);
        $meta = self::loadMetaReport($context['report_root']);
        $suiteReports = self::loadCanonicalSuiteReports($context['report_root']);
        self::assertCanonicalInspectContext($meta, $suiteReports, $context['report_root']);

        return [
            'ok' => true,
            'command' => 'run',
            'inspect_contract' => 'canonical_only',
            'run_id' => $context['run_id'],
            'report_root' => $context['report_root'],
            'report_scope_rel' => $context['report_scope_rel'],
            'meta_report' => $meta,
            'suite_reports' => array_values(array_map(
                static fn(array $report): array => self::suiteSummary($report),
                $suiteReports
            )),
            'first_failure' => self::deriveFirstFailure($meta, $suiteReports),
        ];
    }
    
    /**
     * @return array<string,mixed>
     */
    private static function buildFailureInspection(string $runId = '', bool $latest = false): array
    {
        $context = self::resolveRunContext($runId);
        $meta = self::loadMetaReport($context['report_root']);
        $suiteReports = self::loadCanonicalSuiteReports($context['report_root']);
        self::assertCanonicalInspectContext($meta, $suiteReports, $context['report_root']);
        $firstFailure = self::deriveFirstFailure($meta, $suiteReports);

        return [
            'ok' => true,
            'command' => 'failure',
            'inspect_contract' => 'canonical_only',
            'requested_latest' => $latest,
            'run_id' => $context['run_id'],
            'report_root' => $context['report_root'],
            'report_scope_rel' => $context['report_scope_rel'],
            'evidence_valid' => self::deriveEvidenceValidity($meta, $suiteReports),
            'first_failure' => $firstFailure,
            'has_failure' => is_array($firstFailure),
        ];
    }
    
    /**
     * @return array<string,mixed>
     */
    private static function buildSeedStateInspection(string $runId = '', string $suiteId = ''): array
    {
        $context = self::resolveRunContext($runId);
        $meta = self::loadMetaReport($context['report_root']);
        $suiteReports = self::loadCanonicalSuiteReports($context['report_root']);
        self::assertCanonicalInspectContext($meta, $suiteReports, $context['report_root']);
        $manifests = self::loadBaselineManifests();
        $migrationContract = self::loadMigrationContractReport($context['report_root']);

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
            $canonical = self::requireCanonicalReport($migrationContract, 'migration contract report');
            $seedState = is_array($canonical['seed_state'] ?? null) ? $canonical['seed_state'] : [];
            $selection = is_array($canonical['selection'] ?? null) ? $canonical['selection'] : [];
            $migrationContractPayload = [
                'suite_id' => (string)($selection['suite_id'] ?? $migrationContract['suite_id'] ?? ''),
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
            'inspect_contract' => 'canonical_only',
            'run_id' => $context['run_id'],
            'report_root' => $context['report_root'],
            'report_scope_rel' => $context['report_scope_rel'],
            'meta_summary' => self::metaSummary($meta, $suiteReports),
            'baseline_manifests' => $manifests,
            'migration_contract' => $migrationContractPayload,
        ];
    }
    
    /**
     * @return array<string,mixed>
     */
    private static function buildConcurrencyInspection(string $runId = ''): array
    {
        $context = self::resolveRunContext($runId);
        $meta = self::loadMetaReport($context['report_root']);
        $suiteReports = self::loadCanonicalSuiteReports($context['report_root']);
        self::assertCanonicalInspectContext($meta, $suiteReports, $context['report_root']);

        $suitePolicies = [];
        foreach ($suiteReports as $report) {
            $suitePolicies[] = [
                'suite_id' => (string)($report['suite_id'] ?? ''),
                'parallel_policy' => is_array($report['parallel_policy'] ?? null) ? $report['parallel_policy'] : null,
                'concurrency_admission' => is_array($report['concurrency_admission'] ?? null) ? $report['concurrency_admission'] : null,
                'evidence_valid' => self::reportEvidenceValid($report),
            ];
        }

        return [
            'ok' => true,
            'command' => 'concurrency',
            'inspect_contract' => 'canonical_only',
            'run_id' => $context['run_id'],
            'report_root' => $context['report_root'],
            'report_scope_rel' => $context['report_scope_rel'],
            'evidence_valid' => self::deriveEvidenceValidity($meta, $suiteReports),
            'active_locks' => self::loadActiveLocks(),
            'suite_policies' => $suitePolicies,
        ];
    }
    /**
     * @return array<string,mixed>|null
     */
    private static function loadAgentRunArtifact(string $reportRoot): ?array
    {
        $artifact = AgentRunArtifact::loadLatestForRunRoot($reportRoot);
        if (!is_array($artifact)) {
            return null;
        }

        $execution = is_array($artifact['execution'] ?? null) ? $artifact['execution'] : [];
        $result = is_array($execution['result'] ?? null) ? $execution['result'] : [];

        return [
            'artifact_kind' => (string)($artifact['artifact_kind'] ?? ''),
            'generated_at' => (string)($artifact['generated_at'] ?? ''),
            'source_file' => Paths::relativeToRepo((string)($artifact['_source_file'] ?? '')),
            'latest_path' => (string)($artifact['artifact_paths']['latest'] ?? ''),
            'timestamped_path' => (string)($artifact['artifact_paths']['timestamped'] ?? ''),
            'executed' => (bool)($execution['executed'] ?? false),
            'kind' => (string)($execution['kind'] ?? ''),
            'reason' => (string)($execution['reason'] ?? ''),
            'exit_code' => (int)($result['exit_code'] ?? 0),
        ];
    }

    /**
     * @param mixed $artifact
     */
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
        if ((string)($artifact['generated_at'] ?? '') !== '') {
            echo '  generated_at: ' . (string)$artifact['generated_at'] . PHP_EOL;
        }
        if ((string)($artifact['latest_path'] ?? '') !== '') {
            echo '  latest_path: ' . (string)$artifact['latest_path'] . PHP_EOL;
        }
    }

    private static function printHelp(): void
    {
        echo "Usage:\n";
        echo "  php scripts/inspect.php latest [--json]\n";
        echo "  php scripts/inspect.php run <run_id> [--json]\n";
        echo "  php scripts/inspect.php failure [--latest] [--run=<id>] [--json]\n";
        echo "  php scripts/inspect.php seed-state [--run=<id>] [--suite=<suite>] [--json]\n";
        echo "  php scripts/inspect.php concurrency [--run=<id>] [--json]\n";
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function printJson(array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException('no se pudo serializar la salida JSON de inspect');
        }

        echo $json . PHP_EOL;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function printText(string $command, array $payload): void
    {
        echo "inspect {$command}" . PHP_EOL;
        echo str_repeat('=', 72) . PHP_EOL;
        echo 'run_id: ' . (string)($payload['run_id'] ?? '') . PHP_EOL;
        echo 'report_root: ' . (string)($payload['report_scope_rel'] ?? $payload['report_root'] ?? '') . PHP_EOL;

        switch ($command) {
            case 'latest':
            case 'run':
                self::printMetaSummaryText((array)($payload['meta_summary'] ?? []));
                self::printSuiteReportsText((array)($payload['suite_reports'] ?? []));
                self::printFirstFailureText($payload['first_failure'] ?? null);
                self::printAgentRunArtifactText($payload['agent_run_artifact'] ?? null);
                break;

            case 'failure':
                echo 'evidence_valid: ' . ((bool)($payload['evidence_valid'] ?? true) ? 'true' : 'false') . PHP_EOL;
                self::printFirstFailureText($payload['first_failure'] ?? null);
                break;

            case 'seed-state':
                self::printSeedStateText($payload);
                break;

            case 'concurrency':
                echo 'evidence_valid: ' . ((bool)($payload['evidence_valid'] ?? true) ? 'true' : 'false') . PHP_EOL;
                self::printConcurrencyText($payload);
                break;
        }
    }

    /**
     * @param array<string,mixed> $summary
     */
    private static function printMetaSummaryText(array $summary): void
    {
        if ($summary === []) {
            echo 'summary: unavailable' . PHP_EOL;
            return;
        }

        $row = is_array($summary['summary'] ?? null) ? $summary['summary'] : [];
        echo 'summary: total=' . (int)($row['total'] ?? 0)
            . ' pass=' . (int)($row['passed'] ?? 0)
            . ' fail=' . (int)($row['failed'] ?? 0)
            . ' skip=' . (int)($row['skipped'] ?? 0)
            . ' timeout=' . (int)($row['timeouts'] ?? 0)
            . ' time_ms=' . (int)($row['duration_ms'] ?? 0)
            . PHP_EOL;
    }

    /**
     * @param array<int,array<string,mixed>> $suiteReports
     */
    private static function printSuiteReportsText(array $suiteReports): void
    {
        if ($suiteReports === []) {
            echo 'suite_reports: none' . PHP_EOL;
            return;
        }

        echo 'suite_reports:' . PHP_EOL;
        foreach ($suiteReports as $report) {
            echo '  - ' . (string)($report['suite_id'] ?? 'suite')
                . ' status=' . (string)($report['suite_status'] ?? '')
                . ' outcome=' . (string)($report['outcome_status'] ?? '')
                . ' tests=' . (int)($report['selected_test_count'] ?? 0)
                . ' fail=' . (int)($report['fail'] ?? 0)
                . ' scope=' . (string)($report['selected_module_scope'] ?? 'global')
                . PHP_EOL;
        }
    }

    /**
     * @param mixed $failure
     */
    private static function printFirstFailureText(mixed $failure): void
    {
        if (!is_array($failure) || $failure === []) {
            echo 'first_failure: none' . PHP_EOL;
            return;
        }

        echo 'first_failure:' . PHP_EOL;
        echo '  suite_id: ' . (string)($failure['suite_id'] ?? '') . PHP_EOL;
        echo '  file: ' . (string)($failure['file'] ?? '') . PHP_EOL;
        echo '  case: ' . (string)($failure['case'] ?? '') . PHP_EOL;
        echo '  kind: ' . (string)($failure['kind'] ?? '') . PHP_EOL;
        echo '  phase: ' . (string)($failure['phase'] ?? '') . PHP_EOL;
        echo '  cause_code: ' . (string)($failure['cause_code'] ?? '') . PHP_EOL;
        echo '  exception_class: ' . (string)($failure['exception_class'] ?? '') . PHP_EOL;
        echo '  message: ' . (string)($failure['message'] ?? '') . PHP_EOL;
        $stack = is_array($failure['stack_excerpt'] ?? null) ? $failure['stack_excerpt'] : [];
        if ($stack !== []) {
            echo '  stack_excerpt:' . PHP_EOL;
            foreach ($stack as $line) {
                echo '    - ' . (string)$line . PHP_EOL;
            }
        }
        if ((string)($failure['artifact_path'] ?? '') !== '') {
            echo '  artifact_path: ' . (string)$failure['artifact_path'] . PHP_EOL;
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function printSeedStateText(array $payload): void
    {
        $contract = $payload['migration_contract'] ?? null;
        if (is_array($contract)) {
            echo 'migration_contract:' . PHP_EOL;
            echo '  baseline_mode: ' . (string)($contract['baseline_mode'] ?? '') . PHP_EOL;
            echo '  snapshot_file: ' . (string)($contract['snapshot_file'] ?? '') . PHP_EOL;
            echo '  manifest_path: ' . (string)($contract['manifest_path'] ?? '') . PHP_EOL;
            $state = is_array($contract['migration_state'] ?? null) ? $contract['migration_state'] : [];
            if ($state !== []) {
                echo '  migration_state_source: ' . (string)($state['source'] ?? '') . PHP_EOL;
                echo '  applied: ' . implode(', ', array_values((array)($state['applied'] ?? []))) . PHP_EOL;
                echo '  pending: ' . implode(', ', array_values((array)($state['pending'] ?? []))) . PHP_EOL;
            }
        }

        $manifests = is_array($payload['baseline_manifests'] ?? null) ? $payload['baseline_manifests'] : [];
        if ($manifests === []) {
            echo 'baseline_manifests: none' . PHP_EOL;
            return;
        }

        echo 'baseline_manifests:' . PHP_EOL;
        foreach ($manifests as $manifest) {
            if (!is_array($manifest)) {
                continue;
            }
            echo '  - driver=' . (string)($manifest['driver'] ?? '')
                . ' db=' . (string)($manifest['db_name'] ?? '')
                . ' mode=' . (string)($manifest['baseline_mode'] ?? '')
                . ' status=' . (string)($manifest['status'] ?? '')
                . PHP_EOL;
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function printConcurrencyText(array $payload): void
    {
        $locks = is_array($payload['active_locks'] ?? null) ? $payload['active_locks'] : [];
        if ($locks === []) {
            echo 'active_locks: none' . PHP_EOL;
        } else {
            echo 'active_locks:' . PHP_EOL;
            foreach ($locks as $lock) {
                if (!is_array($lock)) {
                    continue;
                }
                echo '  - ' . (string)($lock['name'] ?? '')
                    . ' run_id=' . (string)($lock['run_id'] ?? '')
                    . ' meta_run_id=' . (string)($lock['meta_run_id'] ?? '')
                    . ' acquired_at=' . (string)($lock['acquired_at'] ?? '')
                    . PHP_EOL;
            }
        }

        $policies = is_array($payload['suite_policies'] ?? null) ? $payload['suite_policies'] : [];
        if ($policies === []) {
            echo 'suite_policies: none' . PHP_EOL;
            return;
        }

        echo 'suite_policies:' . PHP_EOL;
        foreach ($policies as $policy) {
            if (!is_array($policy)) {
                continue;
            }

            $parallel = is_array($policy['parallel_policy'] ?? null) ? $policy['parallel_policy'] : [];
            echo '  - ' . (string)($policy['suite_id'] ?? '')
                . ' evidence_valid=' . ((bool)($policy['evidence_valid'] ?? true) ? 'true' : 'false')
                . ' db_strategy=' . (string)($parallel['db_strategy'] ?? '')
                . ' jobs=' . (int)($parallel['jobs'] ?? 0)
                . ' suite_lock_key=' . (string)($parallel['suite_lock_key'] ?? '')
                . ' admission_lock_scope=' . (string)(is_array($policy['concurrency_admission'] ?? null) ? (($policy['concurrency_admission']['lock_scope'] ?? '')) : '')
                . PHP_EOL;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function resolveRunContext(string $requestedRunId = ''): array
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

    /**
     * @return array<string,mixed>|null
     */
    private static function loadLatestRunManifest(): ?array
    {
        return self::loadJsonFile(Paths::latestRunManifestPath());
    }

    
    /**
     * @return array<string,mixed>|null
     */
    private static function loadMetaReport(string $reportRoot): ?array
    {
        $candidate = rtrim($reportRoot, '/\\') . '/meta_latest.json';
        $json = self::loadJsonFile($candidate);
        if (!is_array($json)) {
            return null;
        }

        return self::assertCanonicalEnvelope($json, $candidate);
    }
    
    /**
     * @return array<int,array<string,mixed>>
     */
    private static function loadCanonicalSuiteReports(string $reportRoot): array
    {
        if (!is_dir($reportRoot)) {
            return [];
        }

        $reports = [];
        foreach (glob(rtrim($reportRoot, '/\\') . '/*_latest.json') ?: [] as $file) {
            $name = basename($file);
            if ($name === 'meta_latest.json') {
                continue;
            }
            if (str_contains($name, '__')) {
                continue;
            }

            $json = self::loadJsonFile($file);
            if (!is_array($json) || !isset($json['suite_id'])) {
                continue;
            }

            $json = self::assertCanonicalEnvelope($json, $file);
            $json['_source_file'] = $file;
            $reports[] = $json;
        }

        usort($reports, static fn(array $a, array $b): int => strcmp((string)($a['suite_id'] ?? ''), (string)($b['suite_id'] ?? '')));
        return $reports;
    }
    
    /**
     * @return array<string,mixed>|null
     */
    private static function loadMigrationContractReport(string $reportRoot): ?array
    {
        $candidate = rtrim($reportRoot, '/\\') . '/migration_contract_latest.json';
        $json = self::loadJsonFile($candidate);
        if (!is_array($json)) {
            return null;
        }

        $json = self::assertCanonicalEnvelope($json, $candidate);
        $json['_source_file'] = $candidate;
        return $json;
    }
    /**
     * @return array<int,array<string,mixed>>
     */
    private static function loadBaselineManifests(): array
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

        usort($rows, static function (array $a, array $b): int {
            return strcmp((string)($a['path'] ?? ''), (string)($b['path'] ?? ''));
        });

        return $rows;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function loadActiveLocks(): array
    {
        $rows = [];
        $lockRoot = Paths::outRoot() . '/locks';
        if (!is_dir($lockRoot)) {
            return [];
        }

        foreach (glob($lockRoot . '/*/owner.json') ?: [] as $ownerFile) {
            $json = self::loadJsonFile($ownerFile);
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
            $canonical = self::requireCanonicalReport($meta, 'meta report');
            $selection = is_array($canonical['selection'] ?? null) ? $canonical['selection'] : [];
            return [
                'target' => (string)($selection['target'] ?? ''),
                'suite_status_counts' => $meta['suite_status_counts'] ?? [],
                'selected_test_count' => (int)($selection['selected_test_count'] ?? 0),
                'summary' => is_array($canonical['summary'] ?? null) ? $canonical['summary'] : [],
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
            $canonical = self::requireCanonicalReport($report, 'suite report');
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
            'suite_status_counts' => [],
            'selected_test_count' => $selected,
            'summary' => $summary,
        ];
    }
    
    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private static function suiteSummary(array $report): array
    {
        $canonical = self::requireCanonicalReport($report, 'suite report');
        $selection = is_array($canonical['selection'] ?? null) ? $canonical['selection'] : [];
        $summary = is_array($canonical['summary'] ?? null) ? $canonical['summary'] : [];
        $artifacts = is_array($canonical['artifacts'] ?? null) ? $canonical['artifacts'] : [];

        return [
            'suite_id' => (string)($selection['suite_id'] ?? ''),
            'suite_status' => (string)($canonical['final_status'] ?? ''),
            'selected_module_scope' => (string)($selection['selected_module_scope'] ?? ''),
            'selected_test_count' => (int)($selection['selected_test_count'] ?? 0),
            'pass' => (int)($summary['passed'] ?? 0),
            'fail' => (int)($summary['failed'] ?? 0),
            'skip' => (int)($summary['skipped'] ?? 0),
            'duration_ms' => (int)($summary['duration_ms'] ?? 0),
            'report_scope_rel' => (string)($artifacts['report_scope_rel'] ?? ''),
            'run_id' => (string)($report['run_id'] ?? ''),
            'first_failure' => self::normalizeFirstFailure($report),
        ];
    }
    
    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     * @return array<string,mixed>|null
     */
    private static function deriveFirstFailure(?array $meta, array $suiteReports): ?array
    {
        if (is_array($meta)) {
            $first = self::normalizeFirstFailure($meta);
            if (is_array($first)) {
                return $first;
            }
        }

        foreach ($suiteReports as $report) {
            $first = self::normalizeFirstFailure($report);
            if (is_array($first)) {
                return $first;
            }
        }

        return null;
    }
    
    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>|null
     */
    private static function normalizeFirstFailure(array $report): ?array
    {
        $canonical = self::requireCanonicalReport($report, 'report');
        $evidence = is_array($canonical['evidence'] ?? null) ? $canonical['evidence'] : [];
        $artifacts = is_array($canonical['artifacts'] ?? null) ? $canonical['artifacts'] : [];
        $selection = is_array($canonical['selection'] ?? null) ? $canonical['selection'] : [];
        $first = $evidence['first_failure'] ?? null;

        if (!is_array($first) || $first === []) {
            return null;
        }

        $first['suite_id'] = (string)($first['suite_id'] ?? $selection['suite_id'] ?? $report['suite_id'] ?? '');
        if (!isset($first['artifact_path']) || trim((string)$first['artifact_path']) === '') {
            $artifactPath = (string)($artifacts['report_scope_rel'] ?? self::reportArtifactPath($report));
            $first['artifact_path'] = $artifactPath;
        }
        if (!isset($first['stack_excerpt']) || !is_array($first['stack_excerpt'])) {
            $first['stack_excerpt'] = self::normalizeStackExcerpt($first['stack_excerpt'] ?? null);
        }

        return $first;
    }
    
    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     */
    private static function deriveEvidenceValidity(?array $meta, array $suiteReports): bool
    {
        if (is_array($meta)) {
            $canonical = self::requireCanonicalReport($meta, 'meta report');
            $evidence = is_array($canonical['evidence'] ?? null) ? $canonical['evidence'] : [];
            return (bool)($evidence['valid'] ?? true);
        }

        foreach ($suiteReports as $report) {
            if (!self::reportEvidenceValid($report)) {
                return false;
            }
        }

        return true;
    }
    
    /**
     * @param array<string,mixed> $report
     */
    private static function reportEvidenceValid(array $report): bool
    {
        $canonical = self::requireCanonicalReport($report, 'report');
        $evidence = is_array($canonical['evidence'] ?? null) ? $canonical['evidence'] : [];
        return (bool)($evidence['valid'] ?? true);
    }
    
    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>|null
     */
    private static function canonicalReport(array $report): ?array
    {
        $canonical = $report['canonical_report'] ?? null;
        return is_array($canonical) ? $canonical : null;
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private static function requireCanonicalReport(array $report, string $context): array
    {
        $canonical = self::canonicalReport($report);
        if (!is_array($canonical)) {
            throw new \RuntimeException("{$context} no contiene canonical_report");
        }

        if (!isset($canonical['report_version'])) {
            throw new \RuntimeException("{$context} tiene canonical_report sin report_version");
        }

        return $canonical;
    }

    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     */
    private static function assertCanonicalInspectContext(?array $meta, array $suiteReports, string $reportRoot): void
    {
        if (is_array($meta) || $suiteReports !== []) {
            return;
        }

        throw new \RuntimeException(
            'inspect no encontró reportes canónicos en ' . Paths::relativeToRepo($reportRoot) .
            '. Este corte ya no hace fallback a reportes legacy: corré un run generado con slices 01-03 aplicados.'
        );
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
                'inspect encontró un reporte sin canonical_report en ' . Paths::relativeToRepo($path) .
                '. Mezclar runs nuevos con JSON legacy deja de estar soportado en este slice.'
            );
        }

        if (!array_key_exists('report_version', $canonical)) {
            throw new \RuntimeException(
                'inspect encontró canonical_report incompleto en ' . Paths::relativeToRepo($path) .
                ' (falta report_version).'
            );
        }

        return $report;
    }
    
    private static function reportArtifactPath(array $report): string
    {
        $source = trim((string)($report['_source_file'] ?? ''));
        if ($source !== '') {
            return Paths::relativeToRepo($source);
        }

        $root = trim((string)($report['report_scope_rel'] ?? $report['report_root'] ?? ''));
        if ($root !== '') {
            return $root;
        }

        return '';
    }

    /**
     * @return array<int,string>
     */
    private static function normalizeStackExcerpt(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map(static fn(mixed $line): string => trim((string)$line), $value));
        }

        $text = trim((string)$value);
        if ($text === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $lines = array_values(array_filter(array_map(static fn(string $line): string => trim($line), $lines), static fn(string $line): bool => $line !== ''));
        return array_slice($lines, 0, 8);
    }

    private static function normalizeSuiteId(string $suiteId): string
    {
        $suiteId = strtolower(trim($suiteId));
        return str_replace('-', '_', $suiteId);
    }

    /**
     * @return array<string,mixed>|null
     */
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
}
