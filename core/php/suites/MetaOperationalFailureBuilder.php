<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;
use Testkit\Core\Reporting\ReportSummary;

final class MetaOperationalFailureBuilder
{
    /**
     * @param array<string,mixed> $admission
     * @return array<string,mixed>
     */
    public static function build(
        string $target,
        string $category,
        string $reportRoot,
        int $durationMs,
        string $startedAt,
        string $runId,
        array $admission,
        string $phase,
        \Throwable $error
    ): array {
        Paths::ensureDir($reportRoot);

        $failurePhase = in_array((string)($admission['reason'] ?? ''), ['shared_store_locked', 'store_resource_locked'], true)
            ? 'store_setup'
            : $phase;
        $failureDomain = $failurePhase === 'store_setup' ? 'store' : ($failurePhase === 'reporting' ? 'reporting' : 'infra');
        $causeCode = (string)($admission['reason'] ?? '');
        if ($causeCode === '') {
            $causeCode = $failurePhase === 'reporting' ? 'report_write_failed' : 'runner_exception';
        }

        $failure = ReportSummary::buildThrowableFailure($error, [
            'test_id' => 'meta.run',
            'test_name' => 'meta.run',
            'case' => 'meta.run',
            'suite_id' => 'meta',
            'suite' => 'meta',
            'scope' => Env::string('TEST_SCOPE', 'all'),
            'category' => $category,
            'kind' => in_array((string)($admission['reason'] ?? ''), ['shared_store_locked', 'store_resource_locked'], true)
                ? 'environment_conflict'
                : 'setup_failure',
            'phase' => $failurePhase,
            'failure_domain' => $failureDomain,
            'cause_code' => $causeCode,
            'artifact_path' => Paths::relativeToRepo($reportRoot),
        ]);

        $meta = [
            'target' => $target,
            'category' => $category,
            'started_at' => $startedAt,
            'duration_ms' => $durationMs,
            'report_root' => $reportRoot,
            'report_scope_rel' => Paths::relativeToRepo($reportRoot),
            'selected_module_scope' => '',
            'selected_test_count' => 0,
            'suite_status_counts' => [],
            'outcome_status_counts' => [],
            'summary' => [
                'total' => 0,
                'passed' => 0,
                'failed' => 0,
                'skipped' => 0,
                'duration_ms' => $durationMs,
            ],
            'failures' => [$failure],
            'failure_contract' => [
                'canonical' => 'failures',
                'legacy_fallback' => 'suites[].has_failures',
            ],
            'first_failure' => ReportSummary::summarizeFailure($failure),
            'evidence_valid' => false,
            'evidence_invalid_reason' => $causeCode,
            'failed_files' => [],
            'top_failure_messages' => ReportSummary::topFailureMessages([$failure], 5),
            'suite_ids' => [],
            'has_failures' => true,
            'suites' => [],
            'run_id' => $runId,
            'meta_run_id' => $runId,
            'run_kind' => 'meta',
            'concurrency_admission' => $admission,
            'filters' => [
                'target' => $target,
                'scope' => Env::string('TEST_SCOPE', 'all'),
                'category' => $category,
                'match' => Env::string('TEST_MATCH', ''),
            ],
        ];

        return ReportSummary::enrichReport($meta);
    }
}
