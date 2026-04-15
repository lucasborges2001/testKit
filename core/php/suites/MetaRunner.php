<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;
use Testkit\Core\Config\RunnerConfig;
use Testkit\Core\Execution\ParallelGuard;
use Testkit\Core\Reporting\ConsoleReporter;
use Testkit\Core\Reporting\ReportSummary;
use Testkit\Core\Reporting\ResultWriter;

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

                $suiteRow = [
                    'suite_id' => $suiteId,
                    'exit_code' => $code,
                    'duration_ms' => $duration,
                ];

                $suiteReport = ReportSummary::loadLatestSuiteReport($suiteId, array_filter([
                    Paths::reportRootForSuite($suiteId) ?? '',
                    Paths::aggregateMetaReportRoot(),
                ]));
                if (is_array($suiteReport)) {
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
                    $suiteRow['rerun_plan'] = is_array($suiteReport['rerun_plan'] ?? null) ? $suiteReport['rerun_plan'] : [];
                }

                $suiteRows[] = $suiteRow;

                if ($code !== 0 && $code !== 2) {
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
            $meta = ReportSummary::enrichReport($meta);

            $currentPhase = 'reporting';
            ConsoleReporter::printMeta($meta);

            self::safeWriteMeta($meta, 'meta.run');

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
            ConsoleReporter::printMeta($meta);
            self::safeWriteMeta($meta, 'meta.operational_failure');
            return 1;
        } finally {
            $resourceLease?->release();
        }
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

    /**
     * @param array<string,mixed> $meta
     */
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

    /**
     * @param array<string,mixed> $meta
     */
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
}
