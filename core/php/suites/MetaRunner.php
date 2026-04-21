<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use RuntimeException;
use Testkit\Core\Common\AgentMode;
use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;
use Testkit\Core\Reporting\AgentRun;
use Testkit\Core\Reporting\AgentRunArtifact;
use Testkit\Core\Reporting\CommandSuggestion;
use Testkit\Core\Reporting\ConsoleReporter;
use Testkit\Core\Reporting\ReportSummary;
use Testkit\Core\Reporting\ResultWriter;
use Testkit\Core\Execution\ParallelGuard;
use Testkit\Core\Config\RunnerConfig;

final class MetaRunner
{
    public static function run(string $targetArg): int
    {
        $config = RunnerConfig::meta();
        $target = strtolower(trim($targetArg !== '' ? $targetArg : (string)$config['target']));
        if ($target === '') {
            $target = 'all';
        }

        $categoryTargets = ['smoke', 'perf', 'stress', 'contract', 'critical', 'slow'];
        if (in_array($target, $categoryTargets, true) && Env::string('TEST_CATEGORY', '') === '') {
            putenv('TEST_CATEGORY=' . $target);
        }

        $selected = TargetResolver::resolve($target);
        if (!$selected) {
            fwrite(STDERR, 'TEST_TARGET invalido: ' . $target . ". Valores: all|back|front|back-php|back-py|front-php|front-js|php|js|smoke|perf|stress|contract|critical|slow|migration-contract\n");
            return 3;
        }

        $runId = Env::string('TEST_RUN_ID', self::buildRunId());
        $reportRunRoot = Paths::reportRunRoot($runId);
        Paths::ensureDir($reportRunRoot);

        putenv('TEST_RUN_ID=' . $runId);
        putenv('TEST_META_RUN_ID=' . $runId);
        putenv('TEST_REPORT_RUN_ROOT=' . $reportRunRoot);
        putenv('TEST_FAIL_FAST=' . ((bool)$config['child_fail_fast'] ? '1' : '0'));

        $metaStartedAt = gmdate('Y-m-d\TH:i:s\Z');
        $metaStart = self::nowMs();
        $suiteRows = [];
        $suiteReports = [];
        $overallFail = false;
        $currentPhase = 'admission';
        $resourcePolicy = ParallelGuard::evaluateRunResource($selected, Paths::repoRoot());
        $admission = ParallelGuard::runResourceAdmissionState($resourcePolicy);
        $resourceLease = null;

        try {
            try {
                $resourceLease = ParallelGuard::acquireRunResourceLock($resourcePolicy);
            } catch (\Throwable $e) {
                $admission = ParallelGuard::rejectedByRunLockState($resourcePolicy);
                throw $e;
            }

            $currentPhase = 'execution';
            foreach ($selected as $suiteId) {
                $start = self::nowMs();
                $code = self::runSuite($suiteId);
                $duration = max(0, self::nowMs() - $start);

                $suiteReportMissing = false;
                $suiteEffectiveCode = $code;
                $suiteRow = [
                    'suite_id' => $suiteId,
                    'exit_code' => $suiteEffectiveCode,
                    'duration_ms' => $duration,
                ];

                $suiteReport = ReportSummary::loadLatestSuiteReport($suiteId, array_filter([
                    Paths::reportRootForSuite($suiteId) ?? '',
                    Paths::aggregateMetaReportRoot(),
                ]));
                if (!is_array($suiteReport)) {
                    $suiteReportMissing = true;
                    $suiteEffectiveCode = 1;
                    $suiteRow['exit_code'] = $suiteEffectiveCode;
                    $suiteReport = self::synthesizeMissingSuiteReport($suiteId, $code, $duration, $runId);
                    $overallFail = true;
                }

                $suiteReports[] = $suiteReport;
                $suiteRow['report_root'] = (string)($suiteReport['report_root'] ?? '');
                $suiteRow['report_scope_rel'] = (string)($suiteReport['report_scope_rel'] ?? '');
                $suiteRow['selected_module_scope'] = (string)($suiteReport['selected_module_scope'] ?? '');
                $suiteRow['selected_test_count'] = (int)($suiteReport['selected_test_count'] ?? $suiteReport['tests_total'] ?? 0);
                $suiteRow['suite_status'] = (string)($suiteReport['suite_status'] ?? '');
                $suiteRow['outcome_status'] = (string)($suiteReport['outcome_status'] ?? '');
                $suiteRow['no_tests_reason'] = (string)($suiteReport['no_tests_reason'] ?? '');
                $suiteRow['runner_capabilities'] = is_array($suiteReport['runner_capabilities'] ?? null) ? $suiteReport['runner_capabilities'] : [];
                $suiteRow['summary'] = is_array($suiteReport['summary'] ?? null) ? $suiteReport['summary'] : [];
                $suiteRow['has_failures'] = !empty(ReportSummary::canonicalFailures($suiteReport));
                $suiteRow['run_id'] = (string)($suiteReport['run_id'] ?? '');
                $suiteRow['previous_run_id'] = $suiteReport['previous_run_id'] ?? null;
                $suiteRow['new_failures_count'] = (int)($suiteReport['new_failures_count'] ?? 0);
                $suiteRow['resolved_failures_count'] = (int)($suiteReport['resolved_failures_count'] ?? 0);
                $suiteRow['rerun_plan'] = self::suiteRerunPlanFromReport($suiteReport);

                $suiteRows[] = $suiteRow;

                if ($suiteReportMissing && (bool)$config['meta_fail_fast']) {
                    break;
                }

                if ($suiteEffectiveCode !== 0 && $suiteEffectiveCode !== 2) {
                    $overallFail = true;
                    if ((bool)$config['meta_fail_fast']) {
                        break;
                    }
                }
            }

            $reportRoot = Paths::aggregateMetaReportRoot();
            Paths::ensureDir($reportRoot);

            $meta = ReportSummary::buildMetaReport(
                $target,
                Env::string('TEST_CATEGORY', 'all'),
                $suiteRows,
                $suiteReports,
                $reportRoot,
                max(0, self::nowMs() - $metaStart),
                $metaStartedAt
            );

            $meta['suites'] = $suiteRows;
            $meta['run_id'] = $runId;
            $meta['meta_run_id'] = $runId;
            $meta['run_kind'] = 'meta';
            $meta['report_keep'] = (int)($config['report_keep'] ?? 5);
            $meta['runs_index_keep'] = (int)($config['runs_index_keep'] ?? $config['report_keep'] ?? 5);
            $meta['concurrency_admission'] = $admission;
            $meta['filters'] = [
                'target' => $target,
                'scope' => Env::string('TEST_SCOPE', 'all'),
                'category' => Env::string('TEST_CATEGORY', 'all'),
                'match' => Env::string('TEST_MATCH', ''),
            ];
            $meta['agent_mode'] = is_array($config['agent_mode'] ?? null)
                ? $config['agent_mode']
                : AgentMode::reportPayload();
            $meta = ReportSummary::enrichReport($meta);

            $currentPhase = 'reporting';
            ConsoleReporter::printMeta($meta);

            self::safeWriteMeta($meta, 'meta.run');
            self::safeRecordAgentDecision($config, $runId);

            if ($overallFail) {
                self::safePrintActionRequired($meta);
            }

            return $overallFail ? 1 : 0;
        } catch (\Throwable $e) {
            $meta = MetaOperationalFailureBuilder::build(
                target: $target,
                category: Env::string('TEST_CATEGORY', 'all'),
                reportRoot: Paths::aggregateMetaReportRoot(),
                durationMs: max(0, self::nowMs() - $metaStart),
                startedAt: $metaStartedAt,
                runId: $runId,
                admission: $admission,
                phase: $currentPhase,
                error: $e
            );
            $meta['agent_mode'] = is_array($config['agent_mode'] ?? null)
                ? $config['agent_mode']
                : AgentMode::reportPayload();
            ConsoleReporter::printMeta($meta);
            self::safeWriteMeta($meta, 'meta.operational_failure');
            self::safeRecordAgentDecision($config, $runId);
            return 1;
        } finally {
            $resourceLease?->release();
        }
    }

    public static function suiteRerunPlanFromReport(array $suiteReport): array
    {
        $plan = is_array($suiteReport['rerun_plan'] ?? null)
            ? array_values(array_filter($suiteReport['rerun_plan'], 'is_array'))
            : [];
        if ($plan !== []) {
            return $plan;
        }

        $actions = is_array($suiteReport['recommended_actions'] ?? null)
            ? array_values(array_filter($suiteReport['recommended_actions'], 'is_array'))
            : [];
        foreach ($actions as $action) {
            $kind = trim((string)($action['kind'] ?? ''));
            $command = trim((string)($action['command'] ?? ''));
            if ($kind !== 'rerun_filtered' || $command === '') {
                continue;
            }

            return [[
                'command' => $command,
                'reason' => trim((string)($action['reason'] ?? '')),
            ]];
        }

        $firstFailure = is_array($suiteReport['first_failure'] ?? null)
            ? $suiteReport['first_failure']
            : ReportSummary::firstFailure($suiteReport);
        if (is_array($firstFailure)) {
            $file = trim((string)($firstFailure['file'] ?? ''));
            $suiteId = trim((string)($firstFailure['suite_id'] ?? $suiteReport['suite_id'] ?? ''));
            if ($file !== '' && $suiteId !== '') {
                return [[
                    'command' => CommandSuggestion::rerun(str_replace('_', '-', $suiteId), $file),
                    'reason' => 'aislar el primer archivo fallido',
                ]];
            }
        }

        return [];
    }

    private static function runSuite(string $suiteId): int
    {
        return match ($suiteId) {
            'back_php' => BackPhpSuite::run(),
            'back_python' => BackPythonSuite::run(),
            'front_php' => FrontPhpSuite::run(),
            'front_js' => FrontJsSuite::run(),
            'migration_contract' => MigrationContractSuite::run(),
            default => 3,
        };
    }

    private static function nowMs(): int
    {
        return (int)round(microtime(true) * 1000);
    }

    private static function buildRunId(): string
    {
        try {
            $suffix = bin2hex(random_bytes(3));
        } catch (\Throwable) {
            $suffix = substr((string)sha1(uniqid('', true)), 0, 6);
        }

        return gmdate('Ymd\THis\Z') . '_' . $suffix;
    }

    private static function safeWriteMeta(array $meta, string $context): void
    {
        try {
            ResultWriter::writeMeta($meta);
        } catch (\Throwable $e) {
            $root = trim((string)($meta['report_root'] ?? ''));
            $scope = $root !== '' ? ' root=' . $root : '';
            fwrite(STDERR, 'WARN[REPORT_WRITE_FAILED] ' . $context . $scope . ': ' . $e->getMessage() . PHP_EOL);
        }
    }

    private static function safePrintActionRequired(array $meta): void
    {
        try {
            MetaActionRequiredRenderer::render($meta);
        } catch (\Throwable $e) {
            $runId = trim((string)($meta['run_id'] ?? ''));
            $scope = $runId !== '' ? ' run=' . $runId : '';
            fwrite(STDERR, 'WARN[ACTION_REQUIRED_RENDER_FAILED] meta.run' . $scope . ': ' . $e->getMessage() . PHP_EOL);
        }
    }

    private static function safeRecordAgentDecision(array $config, string $runId): void
    {
        $agentMode = is_array($config['agent_mode'] ?? null)
            ? $config['agent_mode']
            : AgentMode::reportPayload();

        if (!(bool)($agentMode['enabled'] ?? false)) {
            return;
        }

        try {
            $decision = AgentRun::buildLatestDecision($runId, 'post_run');
            $nextAction = is_array($decision['next_action'] ?? null) ? $decision['next_action'] : [];
            AgentRunArtifact::record($decision, [
                'executed' => false,
                'kind' => (string)($nextAction['kind'] ?? 'decision_only'),
                'reason' => 'auto_recorded_after_meta_run',
                'command' => [
                    'argv' => [],
                    'cwd' => Paths::relativeToRepo(Paths::testkitRoot()),
                    'env_overrides' => ['TESTKIT_MODE' => (string)($agentMode['mode'] ?? 'agent')],
                    'display' => (string)($nextAction['command'] ?? '') !== '' ? (string)$nextAction['command'] : null,
                ],
                'result' => [
                    'exit_code' => 0,
                    'duration_ms' => 0,
                    'stdout_excerpt' => null,
                    'stderr_excerpt' => null,
                ],
                'child_payload' => null,
            ]);
        } catch (\Throwable $e) {
            $scope = $runId !== '' ? ' run=' . $runId : '';
            fwrite(STDERR, 'WARN[AGENT_DECISION_RECORD_FAILED] meta.run' . $scope . ': ' . $e->getMessage() . PHP_EOL);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function synthesizeMissingSuiteReport(string $suiteId, int $exitCode, int $durationMs, string $runId): array
    {
        $reportRoot = Paths::reportRootForSuite($suiteId) ?? Paths::aggregateMetaReportRoot();
        Paths::ensureDir($reportRoot);

        $roots = array_values(array_filter([
            Paths::reportRootForSuite($suiteId) ?? '',
            Paths::aggregateMetaReportRoot(),
            Paths::reportsRoot(),
        ]));

        $message = sprintf(
            'Suite %s termino con exit=%d pero no se pudo cargar un suite report. roots=%s',
            $suiteId,
            $exitCode,
            implode(',', array_unique($roots))
        );

        $failure = ReportSummary::buildThrowableFailure(
            new RuntimeException($message),
            [
                'test_id' => $suiteId . '.report',
                'test_name' => $suiteId . '.report',
                'case' => $suiteId . '.report',
                'suite_id' => $suiteId,
                'suite' => $suiteId,
                'scope' => Env::string('TEST_SCOPE', 'all'),
                'category' => Env::string('TEST_CATEGORY', 'all'),
                'kind' => 'reporting_failure',
                'phase' => 'reporting',
                'failure_domain' => 'reporting',
                'cause_code' => 'suite_report_missing',
                'artifact_path' => Paths::relativeToRepo($reportRoot),
            ]
        );

        $report = [
            'suite_id' => $suiteId,
            'language' => '',
            'scope' => Env::string('TEST_SCOPE', 'all'),
            'category' => Env::string('TEST_CATEGORY', 'all'),
            'tests_total' => 0,
            'pass' => 0,
            'fail' => 1,
            'skip' => 0,
            'timeout' => 0,
            'tests' => [],
            'failed_tests' => [],
            'slow_tests' => [],
            'perf_violations' => [],
            'exit_code' => $exitCode,
            'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'finished_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'duration_ms' => $durationMs,
            'list_only' => false,
            'require_tests' => false,
            'jobs' => 1,
            'module_summary' => [],
            'report_root' => $reportRoot,
            'report_scope_rel' => Paths::relativeToRepo($reportRoot),
            'selected_common_dir' => '',
            'selected_module_scope' => '',
            'selected_test_count' => 0,
            'selected_test_files' => [],
            'suite_status' => 'failed',
            'no_tests_reason' => null,
            'run_id' => $runId,
            'meta_run_id' => $runId,
            'run_kind' => 'suite',
            'summary' => [
                'total' => 0,
                'passed' => 0,
                'failed' => 1,
                'skipped' => 0,
                'duration_ms' => $durationMs,
                'suite_status' => 'failed',
            ],
            'failures' => [$failure],
            'failure_contract' => [
                'canonical' => 'failures',
                'legacy_fallback' => 'failed_tests',
            ],
            'first_failure' => ReportSummary::summarizeFailure($failure),
            'evidence_valid' => false,
            'evidence_invalid_reason' => 'suite_report_missing',
            'filters' => [
                'suite' => $suiteId,
                'scope' => Env::string('TEST_SCOPE', 'all'),
                'category' => Env::string('TEST_CATEGORY', 'all'),
                'match' => Env::string('TEST_MATCH', ''),
            ],
            'selection_manifest' => [
                'selected_test_count' => 0,
                'selected_test_files' => [],
                'selected_module_scope' => '',
                'selected_common_dir' => '',
                'match' => Env::string('TEST_MATCH', ''),
                'source' => 'meta_missing_suite_report',
            ],
        ];

        return ReportSummary::enrichReport($report);
    }
}
