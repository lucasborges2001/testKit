<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Reporting\AgentDecisionBuilder;

$errors = [];

function ad_assert(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

function ad_context(): array
{
    return [
        'run_id' => '20260418T120000Z_agentdecision',
        'report_root' => '/tmp/testkit-agent-decision',
        'report_scope_rel' => '.testkit/reports/runs/20260418T120000Z_agentdecision',
    ];
}

function ad_suite(string $outcome, ?array $failure = null, bool $evidenceValid = true, bool $canonical = true): array
{
    $summary = match ($outcome) {
        'passed' => ['total' => 1, 'passed' => 1, 'failed' => 0, 'skipped' => 0, 'duration_ms' => 10],
        'no_tests' => ['total' => 0, 'passed' => 0, 'failed' => 0, 'skipped' => 0, 'duration_ms' => 0],
        'listed' => ['total' => 1, 'passed' => 0, 'failed' => 0, 'skipped' => 0, 'duration_ms' => 0],
        default => ['total' => 1, 'passed' => 0, 'failed' => 1, 'skipped' => 0, 'duration_ms' => 10],
    };

    $report = [
        'suite_id' => 'back_php',
        'run_id' => '20260418T120000Z_agentdecision',
        'report_root' => '/tmp/testkit-agent-decision',
        'report_scope_rel' => '.testkit/reports/runs/20260418T120000Z_agentdecision',
        'suite_status' => $outcome === 'passed' ? 'passed' : ($outcome === 'listed' ? 'listed' : 'failed'),
        'outcome_status' => $outcome,
        'selected_test_count' => (int)$summary['total'],
        'tests_total' => (int)$summary['total'],
        'summary' => $summary,
        'pass' => (int)$summary['passed'],
        'fail' => (int)$summary['failed'],
        'skip' => (int)$summary['skipped'],
        'evidence_valid' => $evidenceValid,
    ];

    if (is_array($failure)) {
        $report['first_failure'] = $failure;
    }

    if (!$canonical) {
        return $report;
    }

    $report['canonical_report'] = [
        'report_version' => 1,
        'report_kind' => 'suite',
        'final_status' => match ($outcome) {
            'passed' => 'PASS',
            'timeout' => 'TIMEOUT',
            'contention' => 'BLOCKED',
            'no_tests' => 'NO_TESTS',
            'listed' => 'LISTED',
            default => in_array($outcome, ['bootstrap_error', 'discovery_error', 'reporting_error', 'infra_error'], true) ? 'ERROR' : 'FAIL',
        },
        'selection' => [
            'suite_id' => 'back_php',
            'target' => null,
            'scope' => 'all',
            'category' => 'all',
            'match' => '',
            'selected_test_count' => (int)$summary['total'],
            'selected_test_files' => is_array($failure) && trim((string)($failure['file'] ?? '')) !== '' ? [(string)$failure['file']] : [],
            'selected_module_scope' => 'back/auth',
        ],
        'summary' => $summary,
        'diagnostics' => ['outcome_status' => $outcome],
        'evidence' => [
            'valid' => $evidenceValid,
            'invalid_reason' => $evidenceValid ? null : 'stale_or_incomplete_report',
            'first_failure' => $failure,
        ],
        'artifacts' => [
            'report_root' => '/tmp/testkit-agent-decision',
            'report_scope_rel' => '.testkit/reports/runs/20260418T120000Z_agentdecision',
            'report_links' => [],
        ],
        'agent_mode' => ['enabled' => false, 'mode' => 'standard', 'enforced' => []],
        'warnings' => [],
    ];

    return $report;
}

function ad_failure(array $overrides = []): array
{
    return array_merge([
        'suite_id' => 'back_php',
        'file' => 'test/back/auth/integration/login.test.php',
        'case' => 'login.test.php',
        'kind' => 'test_failure',
        'phase' => 'execution',
        'failure_domain' => 'domain',
        'cause_code' => 'assertion_failed',
        'message' => 'Expected true, got false',
        'artifact_path' => '.testkit/reports/runs/20260418T120000Z_agentdecision/back_php_latest.json',
    ], $overrides);
}

$domain = AgentDecisionBuilder::buildFromContext(ad_context(), null, [ad_suite('failed', ad_failure())]);
ad_assert(($domain['next_action']['kind'] ?? null) === 'rerun_single_file', 'domain failure with file must rerun_single_file', $errors);
ad_assert(str_contains((string)($domain['next_action']['command'] ?? ''), 'TEST_MATCH='), 'rerun_single_file command must include TEST_MATCH', $errors);
ad_assert(($domain['decision_basis']['uses_canonical_report_only'] ?? null) === true, 'canonical-only reports must set uses_canonical_report_only=true', $errors);
ad_assert(count((array)($domain['decision_basis']['rules'] ?? [])) > 0, 'decision_basis.rules must not be empty', $errors);

$contention = AgentDecisionBuilder::buildFromContext(ad_context(), null, [ad_suite('contention', ad_failure([
    'phase' => 'admission',
    'failure_domain' => 'runner',
    'cause_code' => 'shared_store_locked',
]))]);
ad_assert(($contention['next_action']['kind'] ?? null) === 'inspect_concurrency', 'contention must inspect_concurrency', $errors);
ad_assert(!str_contains((string)($contention['next_action']['command'] ?? ''), 'runTest.php back-php'), 'contention must not suggest generic rerun', $errors);

$bootstrap = AgentDecisionBuilder::buildFromContext(ad_context(), null, [ad_suite('bootstrap_error', ad_failure([
    'phase' => 'bootstrap',
    'failure_domain' => 'bootstrap',
    'cause_code' => 'seed_failed',
]))]);
ad_assert(in_array(($bootstrap['next_action']['kind'] ?? null), ['inspect_seed_state', 'fix_bootstrap'], true), 'bootstrap error must inspect seed state or fix bootstrap', $errors);

$noTests = AgentDecisionBuilder::buildFromContext(ad_context(), null, [ad_suite('no_tests')]);
ad_assert(($noTests['next_action']['kind'] ?? null) === 'list_tests', 'no_tests must list_tests', $errors);
ad_assert(str_ends_with((string)($noTests['next_action']['command'] ?? ''), ' --list'), 'no_tests command must list tests', $errors);

$passed = AgentDecisionBuilder::buildFromContext(ad_context(), null, [ad_suite('passed')]);
ad_assert(($passed['next_action']['kind'] ?? null) === 'no_action', 'passed must no_action', $errors);

$listed = AgentDecisionBuilder::buildFromContext(ad_context(), null, [ad_suite('listed')]);
ad_assert(($listed['next_action']['kind'] ?? null) === 'no_action', 'listed must no_action', $errors);

$invalid = AgentDecisionBuilder::buildFromContext(ad_context(), null, [ad_suite('failed', ad_failure(), false)]);
ad_assert(($invalid['evidence_valid'] ?? null) === false, 'invalid evidence must be exposed', $errors);
ad_assert(($invalid['next_action']['confidence'] ?? null) !== 'high', 'invalid evidence must not have high confidence', $errors);
ad_assert(($invalid['next_action']['kind'] ?? null) === 'inspect_latest', 'invalid non-contention evidence must inspect_latest', $errors);

$fallback = AgentDecisionBuilder::buildFromContext(ad_context(), null, [ad_suite('failed', ad_failure(), true, false)]);
ad_assert(($fallback['decision_basis']['uses_canonical_report_only'] ?? null) === false, 'legacy fallback must set uses_canonical_report_only=false', $errors);
ad_assert(in_array('fallback_top_level_fields_used', (array)($fallback['decision_basis']['rules'] ?? []), true), 'legacy fallback must document fallback rule', $errors);
ad_assert(count((array)($fallback['decision_basis']['warnings'] ?? [])) > 0, 'legacy fallback must emit warning', $errors);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Agent decision contract PASS\n";
exit(0);
