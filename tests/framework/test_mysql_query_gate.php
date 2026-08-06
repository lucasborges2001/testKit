<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/dbprofiling/bootstrap.php';

use Testkit\Core\DbProfiling\Gate\MysqlQueryBaselineApprovalEvaluator;
use Testkit\Core\DbProfiling\Gate\MysqlQueryGateAllowlistLoader;
use Testkit\Core\DbProfiling\Gate\MysqlQueryGateArtifactWriter;
use Testkit\Core\DbProfiling\Gate\MysqlQueryGateConfig;
use Testkit\Core\DbProfiling\Gate\MysqlQueryGateEvidenceLoader;
use Testkit\Core\DbProfiling\Gate\MysqlQueryGateException;
use Testkit\Core\DbProfiling\Gate\MysqlQueryGateFindingNormalizer;
use Testkit\Core\DbProfiling\Gate\MysqlQueryGateJUnitWriter;
use Testkit\Core\DbProfiling\Gate\MysqlQueryGateLoader;
use Testkit\Core\DbProfiling\Gate\MysqlQueryGateReporter;
use Testkit\Core\DbProfiling\Gate\MysqlQueryGateSarifWriter;
use Testkit\Core\DbProfiling\Gate\MysqlQueryGateSummaryWriter;

$errors = [];

function gate_assert(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

function gate_same(mixed $expected, mixed $actual, string $message, array &$errors): void
{
    if ($expected !== $actual) {
        $errors[] = $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true);
    }
}

/** @return array<string,mixed> */
function gate_json(string $path, bool $artifact = false): array
{
    $decoded = json_decode((string)file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
    $decoded = is_array($decoded) ? $decoded : [];
    if ($artifact) {
        $decoded['_artifact_path'] = basename($path);
        $decoded['_artifact_hash'] = hash_file('sha256', $path) ?: '';
    }
    return $decoded;
}

function gate_expect_invalid(callable $fn, string $code, string $path, string $message, array &$errors): void
{
    try {
        $fn();
        $errors[] = $message . ': expected exception';
    } catch (MysqlQueryGateException $e) {
        gate_same($code, $e->errorCode(), $message . ' code', $errors);
        gate_same($path, $e->jsonPath(), $message . ' path', $errors);
    }
}

/** @return array{code:int,stdout:string,stderr:string} */
function gate_exec(array $args, array $env = []): array
{
    $stdout = tempnam(sys_get_temp_dir(), 'gate_out_');
    $stderr = tempnam(sys_get_temp_dir(), 'gate_err_');
    $command = ['env'];
    foreach ($env as $key => $value) {
        $command[] = escapeshellarg((string)$key . '=' . (string)$value);
    }
    foreach ($args as $arg) {
        $command[] = escapeshellarg((string)$arg);
    }
    $code = 0;
    exec(implode(' ', $command) . ' >' . escapeshellarg((string)$stdout) . ' 2>' . escapeshellarg((string)$stderr), $unused, $code);
    $result = ['code' => $code, 'stdout' => (string)file_get_contents((string)$stdout), 'stderr' => (string)file_get_contents((string)$stderr)];
    @unlink((string)$stdout);
    @unlink((string)$stderr);
    return $result;
}

/** @return array<string,mixed> */
function gate_runtime(string $gate, string $mode = '', string $allowlist = '', string $evidence = '', ?string $tmp = null): array
{
    $tmp ??= sys_get_temp_dir() . '/testkit_gate_' . getmypid();
    @mkdir($tmp, 0777, true);
    return [
        'enabled' => true,
        'file' => $gate,
        'mode_override' => $mode,
        'allowlist_file' => $allowlist,
        'evidence_file' => $evidence,
        'max_findings' => 5000,
        'max_annotations' => 5,
        'github_annotations' => false,
        'output' => [
            'report_path' => $tmp . '/mysql_gate_latest.json',
            'history_path' => $tmp . '/history',
            'junit_path' => $tmp . '/mysql_gate.junit.xml',
            'sarif_path' => $tmp . '/mysql_gate.sarif',
            'summary_path' => $tmp . '/mysql_gate_summary.md',
            'approval_path' => $tmp . '/mysql_baseline_approval_latest.json',
        ],
    ];
}

/** @return array<string,mixed>|null */
function gate_finding(array $report, string $category, string $metric = ''): ?array
{
    foreach ((array)($report['findings'] ?? []) as $finding) {
        if (is_array($finding)
            && ($finding['category'] ?? '') === $category
            && ($metric === '' || ($finding['metric'] ?? '') === $metric)) {
            return $finding;
        }
    }
    return null;
}

/**
 * Creates a temporary active copy of an allowlist fixture.
 *
 * Self-tests must not depend on absolute expiration dates that eventually age out.
 */
function gate_active_allowlist_fixture(string $source, string $target): string
{
    $payload = gate_json($source);
    $createdAt = time() - 3600;
    $expiresAt = $createdAt + (30 * 86400);

    foreach ((array)($payload['allowlist']['entries'] ?? []) as $index => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $payload['allowlist']['entries'][$index]['created_at'] = gmdate('Y-m-d\TH:i:s\Z', $createdAt);
        $payload['allowlist']['entries'][$index]['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', $expiresAt);
    }

    $encoded = json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    if (file_put_contents($target, $encoded . PHP_EOL) === false) {
        throw new RuntimeException('Unable to write active allowlist fixture: ' . $target);
    }

    return $target;
}

$fixtures = __DIR__ . '/../fixtures/mysql_query_gate';
$tmpRoot = sys_get_temp_dir() . '/testkit_mysql_gate_' . getmypid();
@mkdir($tmpRoot, 0777, true);
$activeAllowlistPath = gate_active_allowlist_fixture(
    $fixtures . '/allowlist_valid.json',
    $tmpRoot . '/allowlist_valid_active.json'
);
$unusedAllowlistPath = gate_active_allowlist_fixture(
    $fixtures . '/allowlist_unused.json',
    $tmpRoot . '/allowlist_unused_active.json'
);

// Safe defaults and strict loader.
putenv('TESTKIT_DB_PROFILE_GATE_FILE');
putenv('TESTKIT_DB_PROFILE_GATE_MODE');
$defaultConfig = MysqlQueryGateConfig::fromEnv();
gate_same(false, $defaultConfig['enabled'] ?? null, 'gate disabled by default', $errors);
gate_same('off', MysqlQueryGateConfig::disabledResult()['mode'] ?? null, 'disabled mode is off', $errors);
foreach (['off', 'report', 'warn', 'fail'] as $mode) {
    $loaded = MysqlQueryGateLoader::load($fixtures . '/gate_valid_' . $mode . '.json');
    gate_same($mode, $loaded['gate']['mode'] ?? null, 'valid mode ' . $mode, $errors);
}
gate_expect_invalid(fn() => MysqlQueryGateLoader::load($fixtures . '/gate_invalid_unknown_key.json'), 'unknown_gate_key', '$.gate.unknown', 'unknown key', $errors);
gate_expect_invalid(fn() => MysqlQueryGateLoader::load($fixtures . '/gate_invalid_mode.json'), 'invalid_gate_enum', '$.gate.mode', 'unknown mode', $errors);
gate_expect_invalid(fn() => MysqlQueryGateLoader::load($fixtures . '/gate_conflicting_precedence.json'), 'gate_rule_precedence_conflict', '$.gate.rules[1].decision', 'rule conflict', $errors);
gate_expect_invalid(fn() => MysqlQueryGateLoader::load($fixtures . '/gate_invalid_limits.json'), 'invalid_stability_confirmations', '$.gate.stability.temporal.required_confirmations', 'stability limits', $errors);
$duplicate = gate_json($fixtures . '/gate_valid_report.json');
$duplicate['gate']['rules'][] = $duplicate['gate']['rules'][0];
gate_expect_invalid(fn() => MysqlQueryGateLoader::validate($duplicate), 'duplicate_gate_rule_id', '$.gate.rules[9].id', 'duplicate rule id', $errors);

// Normalization: policy, instrumentation, comparison, stable identity and safe locations.
$policyProfile = gate_json($fixtures . '/profile_policy_violation.json');
$policyNormalizedA = MysqlQueryGateFindingNormalizer::normalize($policyProfile);
$policyNormalizedB = MysqlQueryGateFindingNormalizer::normalize($policyProfile);
$policyFinding = gate_finding(['findings' => $policyNormalizedA['findings']], 'policy.violation');
gate_assert(is_array($policyFinding), 'policy violation normalized', $errors);
gate_same($policyNormalizedA['findings'][0]['finding_id'] ?? null, $policyNormalizedB['findings'][0]['finding_id'] ?? null, 'finding identity deterministic', $errors);
$instrumentationNormalized = MysqlQueryGateFindingNormalizer::normalize(gate_json($fixtures . '/profile_instrumentation_finding.json'));
$instrumentationFinding = gate_finding(['findings' => $instrumentationNormalized['findings']], 'instrumentation.bypass');
gate_assert(is_array($instrumentationFinding), 'instrumentation bypass normalized', $errors);
gate_assert(!str_contains(json_encode($instrumentationFinding), '/home/'), 'normalization has no absolute path', $errors);
$comparison = gate_json($fixtures . '/comparison_structural_regression.json', true);
$comparisonNormalized = MysqlQueryGateFindingNormalizer::normalize(gate_json($fixtures . '/profile_clean.json'), [], [$comparison]);
gate_assert(is_array(gate_finding(['findings' => $comparisonNormalized['findings']], 'baseline.plan_regression')), 'plan regression normalized', $errors);
$ids = array_column($comparisonNormalized['findings'], 'finding_id');
$sorted = $ids;
sort($sorted, SORT_STRING);
gate_same($sorted, $ids, 'normalized findings ordered', $errors);

// Modes and mapping.
$modeReports = [];
foreach (['off', 'report', 'warn', 'fail'] as $mode) {
    $modeReports[$mode] = MysqlQueryGateReporter::evaluate(
        $policyProfile,
        gate_runtime($fixtures . '/gate_valid_' . $mode . '.json', $mode, '', '', $tmpRoot . '/' . $mode)
    );
}
gate_same('disabled', $modeReports['off']['decision']['status'] ?? null, 'off disabled', $errors);
$offInvalidInput = MysqlQueryGateReporter::evaluate(['schema_version' => 'invalid'], gate_runtime($fixtures . '/gate_valid_off.json', 'off', '', '', $tmpRoot . '/off-invalid'));
gate_same('disabled', $offInvalidInput['decision']['status'] ?? null, 'off does not evaluate input evidence', $errors);
gate_same(0, $modeReports['report']['decision']['exit_code'] ?? null, 'report exit zero', $errors);
gate_same('observe', $modeReports['report']['findings'][0]['decision_effective'] ?? null, 'report maps block to observe', $errors);
gate_same(0, $modeReports['warn']['decision']['exit_code'] ?? null, 'warn exit zero', $errors);
gate_same('warn', $modeReports['warn']['findings'][0]['decision_effective'] ?? null, 'warn maps block to warning', $errors);
gate_same(5, $modeReports['fail']['decision']['exit_code'] ?? null, 'fail blocks confirmed finding', $errors);
gate_same('block', $modeReports['fail']['findings'][0]['decision_effective'] ?? null, 'fail effective block', $errors);

// Stability with explicit evidence.
$cleanProfile = gate_json($fixtures . '/profile_clean.json');
$oneRun = MysqlQueryGateReporter::evaluate($cleanProfile, gate_runtime($fixtures . '/gate_valid_fail.json', 'fail', '', $fixtures . '/evidence_1_run.json', $tmpRoot . '/one'));
$p95One = gate_finding($oneRun, 'baseline.temporal_regression', 'p95_ms');
gate_same('insufficient_runs', $p95One['stability_status'] ?? null, 'single run pending stability', $errors);
gate_same(0, $oneRun['decision']['exit_code'] ?? null, 'single temporal run does not block', $errors);
$expiredEvidence = MysqlQueryGateReporter::evaluate($cleanProfile, gate_runtime($fixtures . '/gate_valid_fail.json', 'fail', '', $fixtures . '/evidence_expired.json', $tmpRoot . '/expired-evidence'));
$p95ExpiredEvidence = gate_finding($expiredEvidence, 'baseline.temporal_regression', 'p95_ms');
gate_same('insufficient_runs', $p95ExpiredEvidence['stability_status'] ?? null, 'expired evidence excluded by maximum age', $errors);
gate_same(0, $expiredEvidence['decision']['exit_code'] ?? null, 'expired evidence cannot block', $errors);
$insufficientSamples = MysqlQueryGateReporter::evaluate($cleanProfile, gate_runtime($fixtures . '/gate_valid_fail.json', 'fail', '', $fixtures . '/evidence_insufficient_samples.json', $tmpRoot . '/insufficient-samples'));
$p95InsufficientSamples = gate_finding($insufficientSamples, 'baseline.temporal_regression', 'p95_ms');
gate_same('insufficient_samples', $p95InsufficientSamples['stability_status'] ?? null, 'minimum sample count enforced', $errors);
gate_same(0, $insufficientSamples['decision']['exit_code'] ?? null, 'insufficient samples cannot block', $errors);
$confirmed = MysqlQueryGateReporter::evaluate($cleanProfile, gate_runtime($fixtures . '/gate_valid_fail.json', 'fail', '', $fixtures . '/evidence_3_runs_confirmed.json', $tmpRoot . '/confirmed'));
$p95Confirmed = gate_finding($confirmed, 'baseline.temporal_regression', 'p95_ms');
gate_same('confirmed', $p95Confirmed['stability_status'] ?? null, 'three runs confirmed', $errors);
gate_same(5, $confirmed['decision']['exit_code'] ?? null, 'confirmed temporal regression blocks', $errors);
$unstable = MysqlQueryGateReporter::evaluate($cleanProfile, gate_runtime($fixtures . '/gate_valid_fail.json', 'fail', '', $fixtures . '/evidence_3_runs_unstable.json', $tmpRoot . '/unstable'));
gate_same('unstable', gate_finding($unstable, 'baseline.temporal_regression', 'p95_ms')['stability_status'] ?? null, 'unstable evidence visible', $errors);
gate_same(0, $unstable['decision']['exit_code'] ?? null, 'unstable evidence does not block', $errors);
$baselineMismatch = MysqlQueryGateReporter::evaluate($cleanProfile, gate_runtime($fixtures . '/gate_valid_fail.json', 'fail', '', $fixtures . '/evidence_different_baseline.json', $tmpRoot . '/baseline-mismatch'));
gate_same('incompatible_evidence', gate_finding($baselineMismatch, 'baseline.temporal_regression', 'p95_ms')['stability_status'] ?? null, 'baseline mismatch incompatible', $errors);
$environmentMismatch = MysqlQueryGateReporter::evaluate($cleanProfile, gate_runtime($fixtures . '/gate_valid_fail.json', 'fail', '', $fixtures . '/evidence_different_environment.json', $tmpRoot . '/env-mismatch'));
gate_same('incompatible_evidence', gate_finding($environmentMismatch, 'baseline.temporal_regression', 'p95_ms')['stability_status'] ?? null, 'environment mismatch incompatible', $errors);
gate_expect_invalid(fn() => MysqlQueryGateEvidenceLoader::load($fixtures . '/evidence_corrupt_artifact.json', $fixtures), 'invalid_json', '$', 'corrupt evidence artifact', $errors);
$structuralOnlyComparison = gate_json($fixtures . '/comparison_structural_only_regression.json', true);
$structuralOnly = MysqlQueryGateReporter::evaluate($cleanProfile, gate_runtime($fixtures . '/gate_valid_fail.json', 'fail', '', '', $tmpRoot . '/structural-only'), [], [$structuralOnlyComparison]);
$structuralFinding = gate_finding($structuralOnly, 'baseline.plan_regression');
gate_same('confirmed', $structuralFinding['stability_status'] ?? null, 'structural-only finding can be confirmed explicitly', $errors);
gate_same(5, $structuralOnly['decision']['exit_code'] ?? null, 'allowed structural-only finding blocks', $errors);
$incompatibleComparison = gate_json($fixtures . '/comparison_incompatible.json', true);
$incompatibleGate = MysqlQueryGateReporter::evaluate($cleanProfile, gate_runtime($fixtures . '/gate_valid_fail.json', 'fail', '', '', $tmpRoot . '/incompatible'), [], [$incompatibleComparison]);
gate_same(0, $incompatibleGate['decision']['exit_code'] ?? null, 'incompatible comparison does not block', $errors);

// Allowlist contract, suppression, expiration and visibility.
$validAllowlist = MysqlQueryGateAllowlistLoader::load($activeAllowlistPath);
gate_same('pruebas.sql.temporary', $validAllowlist['allowlist']['id'] ?? null, 'valid allowlist loads', $errors);
gate_expect_invalid(fn() => MysqlQueryGateAllowlistLoader::load($fixtures . '/allowlist_invalid.json'), 'invalid_allowlist_string', '$.allowlist.entries[0].owner', 'invalid allowlist', $errors);
gate_expect_invalid(fn() => MysqlQueryGateAllowlistLoader::load($fixtures . '/allowlist_too_broad.json'), 'allowlist_selector_too_broad', '$.allowlist.entries[0].selectors', 'broad allowlist', $errors);
gate_expect_invalid(fn() => MysqlQueryGateAllowlistLoader::load($fixtures . '/allowlist_non_suppressible.json'), 'allowlist_non_suppressible_category', '$.allowlist.entries[0].selectors.category', 'non suppressible allowlist', $errors);
$suppressed = MysqlQueryGateReporter::evaluate($cleanProfile, gate_runtime(
    $fixtures . '/gate_valid_fail.json', 'fail', $activeAllowlistPath, $fixtures . '/evidence_3_runs_confirmed.json', $tmpRoot . '/suppressed'
));
gate_assert(($suppressed['summary']['suppressed'] ?? 0) > 0, 'valid suppression visible', $errors);
gate_assert(($suppressed['summary']['suppressed_blocking'] ?? 0) > 0, 'suppressed blocking finding counted', $errors);
gate_same(0, $suppressed['decision']['exit_code'] ?? null, 'valid suppression prevents block', $errors);
$suppressedP95 = gate_finding($suppressed, 'baseline.temporal_regression', 'p95_ms');
gate_same(true, $suppressedP95['suppressed'] ?? null, 'finding retains suppression marker', $errors);
gate_same('catalog-p95-temporary', $suppressedP95['suppression']['suppression_id'] ?? null, 'suppression id retained', $errors);
$expired = MysqlQueryGateReporter::evaluate($cleanProfile, gate_runtime(
    $fixtures . '/gate_valid_fail.json', 'fail', $fixtures . '/allowlist_expired.json', $fixtures . '/evidence_3_runs_confirmed.json', $tmpRoot . '/expired'
));
gate_assert(is_array(gate_finding($expired, 'allowlist.expired')), 'expired allowlist generates finding', $errors);
gate_same(false, gate_finding($expired, 'baseline.temporal_regression', 'p95_ms')['suppressed'] ?? null, 'expired entry does not suppress', $errors);
$unused = MysqlQueryGateReporter::evaluate($cleanProfile, gate_runtime(
    $fixtures . '/gate_valid_report.json', 'report', $unusedAllowlistPath, '', $tmpRoot . '/unused'
));
gate_assert(in_array('catalog-p95-temporary', (array)($unused['allowlist']['unused'] ?? []), true), 'unused allowlist reported', $errors);

// Runner integration preserves prior failures and only changes successful suites.
$passResult = ['exit_code' => 0, 'suite_status' => 'passed'];
MysqlQueryGateReporter::applyToSuiteResult($passResult, MysqlQueryGateReporter::profileAttachment($modeReports['report']));
gate_same(0, $passResult['exit_code'], 'suite pass + gate pass', $errors);
$blockResult = ['exit_code' => 0, 'suite_status' => 'passed'];
MysqlQueryGateReporter::applyToSuiteResult($blockResult, MysqlQueryGateReporter::profileAttachment($modeReports['fail']));
gate_same(5, $blockResult['exit_code'], 'suite pass + gate block', $errors);
gate_assert(isset($blockResult['mysql_gate'], $blockResult['quality_gate'], $blockResult['blocking_findings'], $blockResult['gate_mode'], $blockResult['gate_exit_code']), 'suite report contains gate fields', $errors);
$failedResult = ['exit_code' => 1, 'suite_status' => 'failed'];
MysqlQueryGateReporter::applyToSuiteResult($failedResult, MysqlQueryGateReporter::profileAttachment($modeReports['fail']));
gate_same(1, $failedResult['exit_code'], 'test failure preserved with blocking gate', $errors);
$failedPassGate = ['exit_code' => 1, 'suite_status' => 'failed'];
MysqlQueryGateReporter::applyToSuiteResult($failedPassGate, MysqlQueryGateReporter::profileAttachment($modeReports['report']));
gate_same(1, $failedPassGate['exit_code'], 'test failure preserved with passing gate', $errors);
$disabledResult = ['exit_code' => 0];
MysqlQueryGateReporter::applyToSuiteResult($disabledResult, MysqlQueryGateReporter::profileAttachment(MysqlQueryGateConfig::disabledResult()));
gate_same(0, $disabledResult['exit_code'], 'disabled gate no behavior change', $errors);

// CI outputs are deterministic, escaped and free of fixture secrets.
$expectedReport = $modeReports['fail'];
$junit = MysqlQueryGateJUnitWriter::render($expectedReport);
gate_same((string)file_get_contents($fixtures . '/expected_gate.junit.xml'), $junit, 'JUnit fixture stable', $errors);
gate_assert(str_contains($junit, '<testsuite name="testkit.mysql.query-gate"'), 'JUnit root', $errors);
gate_assert(str_contains($junit, 'failures="1"'), 'JUnit counts failure', $errors);
gate_assert(substr_count($junit, '<testcase ') === 1, 'JUnit testcase count', $errors);
$sarif = MysqlQueryGateSarifWriter::build($expectedReport);
gate_same('2.1.0', $sarif['version'] ?? null, 'SARIF version', $errors);
gate_assert(count((array)($sarif['runs'][0]['tool']['driver']['rules'] ?? [])) >= 1, 'SARIF rules', $errors);
gate_same('error', $sarif['runs'][0]['results'][0]['level'] ?? null, 'SARIF blocking level', $errors);
gate_same(gate_json($fixtures . '/expected_gate.sarif.json'), $sarif, 'SARIF fixture stable', $errors);
$summary = MysqlQueryGateSummaryWriter::render($expectedReport, 20);
gate_same((string)file_get_contents($fixtures . '/expected_gate_summary.md'), $summary, 'summary fixture stable', $errors);
gate_assert(str_contains($summary, '## Blocking findings'), 'summary blocking section', $errors);
$serializedOutputs = $junit . json_encode($sarif) . $summary;
foreach (['alice@example.test', 'hunter2', 'sk_test_', '/home/private'] as $secret) {
    gate_assert(!str_contains($serializedOutputs, $secret), 'CI output leaks secret ' . $secret, $errors);
}
$outputRuntime = gate_runtime($fixtures . '/gate_valid_fail.json', 'fail', '', '', $tmpRoot . '/outputs');
$outputReport = $expectedReport;
MysqlQueryGateReporter::writeArtifacts($outputReport, $outputRuntime);
gate_assert(is_file($outputRuntime['output']['report_path']), 'JSON artifact written', $errors);
gate_assert(is_file($outputRuntime['output']['junit_path']), 'JUnit artifact written', $errors);
gate_assert(is_file($outputRuntime['output']['sarif_path']), 'SARIF artifact written', $errors);
gate_assert(is_file($outputRuntime['output']['summary_path']), 'summary artifact written', $errors);
gate_assert(count(glob($outputRuntime['output']['history_path'] . '/*.json') ?: []) === 1, 'history artifact written', $errors);

// GitHub annotations are optional, escaped and bounded.
$annotationCode = <<<'CODE'
require $argv[1];
$r=['findings'=>[
 ['decision_effective'=>'block','category'=>'x:y,z','message'=>"bad%line\nnext:part,tail",'location'=>['path'=>'test/a.php','line'=>3]],
 ['decision_effective'=>'warn','category'=>'second','message'=>'second','location'=>[]],
]];
Testkit\Core\DbProfiling\Gate\MysqlQueryGateSummaryWriter::emitGithubAnnotations($r,1);
CODE;
$annotation = gate_exec([PHP_BINARY, '-r', $annotationCode, __DIR__ . '/../../core/php/dbprofiling/bootstrap.php']);
gate_same(0, $annotation['code'], 'annotation helper exits zero', $errors);
gate_assert(str_contains($annotation['stdout'], '::error '), 'annotation emits error', $errors);
gate_assert(str_contains($annotation['stdout'], '%25') && str_contains($annotation['stdout'], '%0A') && str_contains($annotation['stdout'], '%3A') && str_contains($annotation['stdout'], '%2C'), 'workflow commands escaped', $errors);
gate_same(1, substr_count($annotation['stdout'], '::error ') + substr_count($annotation['stdout'], '::warning '), 'annotation limit', $errors);

// Baseline approval is report-only and never creates a baseline.
$eligibleFixture = gate_json($fixtures . '/baseline_approval_eligible.json');
$ineligibleFixture = gate_json($fixtures . '/baseline_approval_ineligible.json');
$pendingFixture = gate_json($fixtures . '/baseline_approval_pending.json');
gate_same('eligible', $eligibleFixture['status'] ?? null, 'approval eligible fixture', $errors);
gate_same('incompatible', $ineligibleFixture['status'] ?? null, 'approval ineligible fixture', $errors);
gate_same('pending_stability', $pendingFixture['status'] ?? null, 'approval pending fixture', $errors);
$approvalSuppressed = MysqlQueryBaselineApprovalEvaluator::evaluate($suppressed, gate_json($fixtures . '/comparison_equal.json'), $cleanProfile);
gate_same('ineligible', $approvalSuppressed['status'] ?? null, 'blocking suppressions deny approval', $errors);
$approvalMissing = MysqlQueryBaselineApprovalEvaluator::evaluate($modeReports['report'], [], []);
gate_assert(in_array((string)($approvalMissing['status'] ?? ''), ['incompatible', 'insufficient_evidence'], true), 'missing metadata not eligible', $errors);
gate_assert(!is_file($tmpRoot . '/baseline.json'), 'approval evaluator did not write baseline', $errors);

// CLI exit codes and forbidden automatic acceptance.
$gateScript = __DIR__ . '/../../scripts/query_gate.php';
$approvalScript = __DIR__ . '/../../scripts/query_baseline_approval.php';
foreach (['report' => 0, 'warn' => 0, 'fail' => 5] as $mode => $expectedCode) {
    $cli = gate_exec([
        PHP_BINARY, $gateScript,
        '--profile', $fixtures . '/profile_policy_violation.json',
        '--gate', $fixtures . '/gate_valid_' . $mode . '.json',
        '--mode', $mode,
        '--format', 'json',
        '--json', $tmpRoot . '/cli-' . $mode . '.json',
    ]);
    gate_same($expectedCode, $cli['code'], 'CLI mode exit ' . $mode, $errors);
}
$missingCli = gate_exec([PHP_BINARY, $gateScript, '--gate', $fixtures . '/missing.json']);
gate_same(2, $missingCli['code'], 'CLI operational exit', $errors);
$invalidCli = gate_exec([PHP_BINARY, $gateScript, '--gate', $fixtures . '/gate_invalid_mode.json']);
gate_same(3, $invalidCli['code'], 'CLI invalid contract exit', $errors);
$incompatibleProfile = $tmpRoot . '/incompatible-profile.json';
file_put_contents($incompatibleProfile, json_encode(['schema_version' => 'other-v1']));
$incompatibleCli = gate_exec([PHP_BINARY, $gateScript, '--profile', $incompatibleProfile, '--gate', $fixtures . '/gate_valid_report.json']);
gate_same(4, $incompatibleCli['code'], 'CLI incompatible input exit', $errors);
$forbidden = gate_exec([PHP_BINARY, $gateScript, '--accept']);
gate_same(3, $forbidden['code'], 'gate CLI rejects accept', $errors);
$approvalHelp = gate_exec([PHP_BINARY, $approvalScript, '--help']);
gate_same(0, $approvalHelp['code'], 'approval CLI help', $errors);
$approvalForbidden = gate_exec([PHP_BINARY, $approvalScript, '--promote']);
gate_same(3, $approvalForbidden['code'], 'approval CLI rejects promote', $errors);
$unknownCli = gate_exec([PHP_BINARY, $gateScript, '--unknown-option', 'x']);
gate_same(3, $unknownCli['code'], 'gate CLI rejects unknown option', $errors);
$invalidTopCli = gate_exec([PHP_BINARY, $gateScript, '--gate', $fixtures . '/gate_valid_report.json', '--top', '0']);
gate_same(3, $invalidTopCli['code'], 'gate CLI rejects invalid top', $errors);
$cliModePrecedence = gate_exec([
    PHP_BINARY, $gateScript,
    '--profile', $fixtures . '/profile_policy_violation.json',
    '--gate', $fixtures . '/gate_valid_report.json',
    '--mode', 'report',
], ['TESTKIT_DB_PROFILE_GATE_MODE' => 'enforce']);
gate_same(0, $cliModePrecedence['code'], 'explicit CLI mode overrides invalid environment mode', $errors);
$unknownApprovalCli = gate_exec([PHP_BINARY, $approvalScript, '--unknown-option', 'x']);
gate_same(3, $unknownApprovalCli['code'], 'approval CLI rejects unknown option', $errors);

// Profile integration remains v2 and gate disabled does not alter previous contracts.
$profileWithGate = MysqlQueryGateReporter::attachToProfile($cleanProfile, $modeReports['report']);
gate_same('mysql-query-profile-report-v2', $profileWithGate['schema_version'] ?? null, 'profile schema remains v2', $errors);
gate_assert(isset($profileWithGate['mysql_gate'], $profileWithGate['quality_gate']), 'profile contains bounded gate attachments', $errors);
gate_same(false, MysqlQueryGateConfig::disabledResult()['enabled'] ?? null, 'profiling/gate disabled compatibility', $errors);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . PHP_EOL);
    }
    exit(1);
}

echo "PASS test_mysql_query_gate\n";
