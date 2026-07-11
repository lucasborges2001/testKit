<?php
declare(strict_types=1);

putenv('TESTKIT_DB_PROFILE=1');
require_once __DIR__ . '/../../core/php/dbprofiling/bootstrap.php';

use Testkit\Core\DbProfiling\MysqlProfileReporter;
use Testkit\Core\DbProfiling\QueryProfileCollector;
use Testkit\Core\DbProfiling\Policy\MysqlQueryPolicyException;
use Testkit\Core\DbProfiling\Policy\MysqlQueryPolicyLoader;
use Testkit\Core\DbProfiling\Policy\MysqlQueryPolicyEvaluator;

$errors = [];
function policy_assert(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}
function policy_same(mixed $expected, mixed $actual, string $message, array &$errors): void
{
    if ($expected !== $actual) {
        $errors[] = $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true);
    }
}
function policy_json(string $path): array
{
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}
function policy_expect_invalid(array $payload, string $path, array &$errors): void
{
    try {
        MysqlQueryPolicyLoader::validate($payload);
        $errors[] = 'expected invalid policy at ' . $path;
    } catch (MysqlQueryPolicyException $e) {
        policy_same($path, $e->jsonPath(), 'invalid policy path', $errors);
    }
}

$fixtures = __DIR__ . '/../fixtures/mysql_query_policy';
$valid = MysqlQueryPolicyLoader::load($fixtures . '/policy_valid.json');
policy_same('mysql-query-policy-v1', $valid['schema_version'] ?? null, 'policy schema', $errors);
policy_same(5, count((array)($valid['policy_set']['policies'] ?? [])), 'loaded policy count', $errors);

try {
    MysqlQueryPolicyLoader::load($fixtures . '/policy_invalid_unknown_key.json');
    $errors[] = 'unknown key policy should fail';
} catch (MysqlQueryPolicyException $e) {
    policy_same('unknown_policy_key', $e->errorCode(), 'unknown key error code', $errors);
    policy_same('$.policy_set.policies[0].unexpected', $e->jsonPath(), 'unknown key path', $errors);
}

policy_expect_invalid([
    'schema_version' => 'other',
    'policy_set' => ['id' => 'x', 'mode' => 'report_only', 'policies' => []],
], '$.schema_version', $errors);
policy_expect_invalid([
    'schema_version' => 'mysql-query-policy-v1',
    'policy_set' => ['id' => 'x', 'mode' => 'report_only', 'policies' => [[
        'id' => 'empty', 'selector' => [], 'budgets' => ['max_calls' => 1],
    ]]],
], '$.policy_set.policies[0].selector', $errors);
policy_expect_invalid([
    'schema_version' => 'mysql-query-policy-v1',
    'policy_set' => ['id' => 'x', 'mode' => 'report_only', 'policies' => [[
        'id' => 'negative', 'selector' => ['module_id' => 'catalogo'], 'budgets' => ['max_calls' => -1],
    ]]],
], '$.policy_set.policies[0].budgets.max_calls', $errors);
policy_expect_invalid([
    'schema_version' => 'mysql-query-policy-v1',
    'policy_set' => ['id' => 'x', 'mode' => 'enforce', 'policies' => []],
], '$.policy_set.mode', $errors);
policy_expect_invalid([
    'schema_version' => 'mysql-query-policy-v1',
    'policy_set' => ['id' => 'x', 'mode' => 'report_only', 'policies' => [
        ['id' => 'dup', 'selector' => ['module_id' => 'a'], 'budgets' => ['max_calls' => 1]],
        ['id' => 'dup', 'selector' => ['module_id' => 'b'], 'budgets' => ['max_calls' => 1]],
    ]],
], '$.policy_set.policies[1].id', $errors);

policy_expect_invalid([
    'schema_version' => 'mysql-query-policy-v1',
    'policy_set' => ['id' => 'x', 'mode' => 'report_only', 'policies' => [[
        'id' => 'infinite', 'selector' => ['module_id' => 'catalogo'], 'budgets' => ['max_p95_ms' => INF],
    ]]],
], '$.policy_set.policies[0].budgets.max_p95_ms', $errors);
policy_expect_invalid([
    'schema_version' => 'mysql-query-policy-v1',
    'policy_set' => ['id' => 'x', 'mode' => 'report_only', 'policies' => [[
        'id' => 'bad_flag', 'selector' => ['module_id' => 'catalogo'], 'plan' => ['forbid_flags' => ['invented_flag']],
    ]]],
], '$.policy_set.policies[0].plan.forbid_flags[0]', $errors);

$passProfile = policy_json($fixtures . '/profile_v2_pass.json');
$pass = MysqlQueryPolicyEvaluator::evaluate($passProfile, $valid, 500);
$selectorPolicy = MysqlQueryPolicyLoader::validate([
    'schema_version' => 'mysql-query-policy-v1',
    'policy_set' => [
        'id' => 'selector.coverage',
        'mode' => 'report_only',
        'policies' => [[
            'id' => 'selector.all',
            'selector' => [
                'query_id' => ['catalog.product_search', 'other'],
                'module_id' => ['catalogo', 'other'],
                'scenario_id' => 'product_search',
                'suite_id' => 'back_php',
                'test_id' => 'test/back/catalog/search.test.php',
                'capture_method' => 'profiled_pdo.statement_execute'
            ],
            'budgets' => ['max_calls' => 2]
        ]]
    ]
]);
$selectorEvaluation = MysqlQueryPolicyEvaluator::evaluate($passProfile, $selectorPolicy);
policy_same(1, $selectorEvaluation['applicable_policies'] ?? null, 'AND selectors and OR arrays match', $errors);
policy_same(1, $selectorEvaluation['passed_budgets'] ?? null, 'selector budget evaluated', $errors);

$globalPolicy = MysqlQueryPolicyLoader::validate([
    'schema_version' => 'mysql-query-policy-v1',
    'policy_set' => [
        'id' => 'global.coverage', 'mode' => 'report_only', 'policies' => [[
            'id' => 'global.findings', 'scope' => 'global',
            'budgets' => ['max_instrumentation_findings' => 0, 'max_uninstrumented_findings' => 0]
        ]]
    ]
]);
$globalEvaluation = MysqlQueryPolicyEvaluator::evaluate($passProfile, $globalPolicy);
policy_same(2, $globalEvaluation['passed_budgets'] ?? null, 'global finding budgets pass', $errors);

policy_same(0, $pass['violated_budgets'] ?? null, 'pass fixture has no violations', $errors);
policy_assert(($pass['passed_budgets'] ?? 0) >= 10, 'pass fixture evaluates inherited budgets', $errors);
policy_same(1, count((array)($pass['unused_policies'] ?? [])), 'unused policy detected', $errors);
policy_same('unused_no_match', $pass['unused_policies'][0]['status'] ?? null, 'unused reason', $errors);

$origins = [];
foreach ((array)($pass['results'] ?? []) as $result) {
    if (is_array($result)) {
        $origins[(string)($result['budget_key'] ?? '')] = (string)($result['source_policy_id'] ?? '');
    }
}
policy_same('catalog.search', $origins['max_calls'] ?? null, 'scenario overrides module max_calls', $errors);
policy_same('catalog.search', $origins['max_p95_ms'] ?? null, 'scenario overrides module max_p95', $errors);
policy_same('catalog.search.query', $origins['max_total_ms'] ?? null, 'fingerprint budget origin', $errors);

$violationProfile = policy_json($fixtures . '/profile_v2_violations.json');
$violations = MysqlQueryPolicyEvaluator::evaluate($violationProfile, $valid, 500);
policy_assert(($violations['violated_budgets'] ?? 0) >= 10, 'violation fixture exceeds query and plan budgets', $errors);
$statuses = array_column((array)$violations['results'], 'status', 'budget_key');
policy_same('violation', $statuses['max_calls'] ?? null, 'max_calls violation', $errors);
policy_same('violation', $statuses['forbid_flags'] ?? null, 'forbid flags violation', $errors);
policy_same('violation', $statuses['require_any_key'] ?? null, 'require any key violation', $errors);
policy_same('violation', $statuses['max_estimated_rows'] ?? null, 'estimated rows violation', $errors);

$insufficient = MysqlQueryPolicyEvaluator::evaluate(policy_json($fixtures . '/profile_v2_insufficient_explain.json'), $valid, 500);
policy_assert(($insufficient['insufficient_data_budgets'] ?? 0) >= 5, 'missing explain is insufficient, not pass', $errors);
foreach ((array)$insufficient['results'] as $result) {
    if (is_array($result) && in_array((string)($result['budget_key'] ?? ''), ['forbid_flags', 'require_any_key', 'require_keys', 'forbid_access_types', 'max_estimated_rows'], true)) {
        policy_same('insufficient_data', $result['status'] ?? null, 'plan missing evidence status', $errors);
    }
}

$legacy = MysqlQueryPolicyEvaluator::evaluate(policy_json($fixtures . '/profile_v1_legacy.json'), $valid, 500);
policy_assert(($legacy['insufficient_data_budgets'] ?? 0) >= 4, 'legacy missing percentiles reported insufficient', $errors);
$legacyReasons = array_values(array_filter(array_column((array)$legacy['results'], 'reason')));
policy_assert(in_array('legacy_report_field_missing', $legacyReasons, true), 'legacy reason stable', $errors);

$reordered = $valid;
$reordered['policy_set']['policies'] = array_reverse((array)$reordered['policy_set']['policies']);
$passReordered = MysqlQueryPolicyEvaluator::evaluate($passProfile, $reordered, 500);
policy_same($pass['status_counts'], $passReordered['status_counts'], 'JSON order does not alter evaluation', $errors);
$effectiveA = $pass['effective_policies'][0]['budgets'] ?? [];
$effectiveB = $passReordered['effective_policies'][0]['budgets'] ?? [];
policy_same($effectiveA, $effectiveB, 'JSON order does not alter effective budgets', $errors);

try {
    MysqlQueryPolicyEvaluator::evaluate($passProfile, MysqlQueryPolicyLoader::load($fixtures . '/policy_conflicting.json'));
    $errors[] = 'equal precedence conflict should fail';
} catch (MysqlQueryPolicyException $e) {
    policy_same('policy_precedence_conflict', $e->errorCode(), 'conflict code', $errors);
}

$tmp = sys_get_temp_dir() . '/testkit_policy_' . getmypid();
@mkdir($tmp, 0777, true);
putenv('TESTKIT_ARTIFACTS_ROOT=' . $tmp);
putenv('TESTKIT_DB_PROFILE_POLICY_FILE=' . $fixtures . '/policy_valid.json');
putenv('TESTKIT_DB_PROFILE_POLICY_REPORT_PATH=' . $tmp . '/reports/mysql_policy_latest.json');
putenv('TESTKIT_DB_PROFILE_POLICY_HISTORY_PATH=' . $tmp . '/history/mysql_policy');
QueryProfileCollector::resetForTests();
QueryProfileCollector::enableForTests();
QueryProfileCollector::markBootstrapped();
QueryProfileCollector::record(
    'SELECT * FROM products WHERE category_id = 9 ORDER BY created_at DESC',
    20.0,
    'test/back/catalog/search.test.php',
    'src/CatalogRepository.php:10',
    ['module_id' => 'catalogo', 'scenario_id' => 'product_search']
);
$integrated = MysqlProfileReporter::buildReportFromSnapshot(QueryProfileCollector::snapshot());
policy_same(true, $integrated['policy_evaluation']['enabled'] ?? null, 'profile reporter policy integration', $errors);
policy_same('test.sql', $integrated['policy_evaluation']['policy_set_id'] ?? null, 'policy set attached', $errors);
policy_assert(isset($integrated['queries'][0]['policy_status'], $integrated['queries'][0]['applied_policy_ids']), 'query policy summary attached', $errors);
policy_assert(is_file($tmp . '/reports/mysql_policy_latest.json'), 'policy artifact written atomically', $errors);

putenv('TESTKIT_DB_PROFILE_POLICY_FILE=' . $fixtures . '/policy_invalid_unknown_key.json');
$invalidIntegrated = MysqlProfileReporter::buildReportFromSnapshot(QueryProfileCollector::snapshot());
policy_same('invalid_policy', $invalidIntegrated['policy_evaluation']['results'][0]['status'] ?? null, 'invalid policy visible in base report', $errors);
policy_same('unknown_policy_key', $invalidIntegrated['policy_evaluation']['results'][0]['error_code'] ?? null, 'invalid policy error code visible', $errors);

$disabledArtifact = $tmp . '/disabled/mysql_policy.json';
putenv('TESTKIT_DB_PROFILE_POLICY_FILE');
putenv('TESTKIT_DB_PROFILE_POLICY_REPORT_PATH=' . $disabledArtifact);
$disabledIntegrated = MysqlProfileReporter::buildReportFromSnapshot(QueryProfileCollector::snapshot());
policy_same(false, $disabledIntegrated['policy_evaluation']['enabled'] ?? null, 'policy disabled without file', $errors);
policy_assert(!is_file($disabledArtifact), 'policy disabled creates no policy artifact', $errors);
putenv('TESTKIT_DB_PROFILE_POLICY_FILE=' . $fixtures . '/policy_valid.json');
putenv('TESTKIT_DB_PROFILE_POLICY_REPORT_PATH=' . $tmp . '/reports/mysql_policy_latest.json');
$encoded = json_encode($integrated, JSON_UNESCAPED_SLASHES);
policy_assert(!str_contains((string)$encoded, 'secret@example.test'), 'email not leaked', $errors);
policy_assert(!str_contains((string)$encoded, 'mysql:host=prod'), 'DSN not leaked', $errors);
policy_assert(!str_contains((string)$encoded, $tmp . '/checkout'), 'absolute checkout path not leaked', $errors);

$cli = __DIR__ . '/../../scripts/query_policy_report.php';
$profilePath = $fixtures . '/profile_v2_violations.json';
$policyPath = $fixtures . '/policy_valid.json';
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cli) . ' --profile ' . escapeshellarg($profilePath) . ' --policy ' . escapeshellarg($policyPath) . ' >/dev/null 2>&1', $out, $exitHuman);
policy_same(0, $exitHuman, 'CLI human violations exit zero', $errors);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cli) . ' --format=json --profile ' . escapeshellarg($profilePath) . ' --policy ' . escapeshellarg($policyPath) . ' >/dev/null 2>&1', $out, $exitJson);
policy_same(0, $exitJson, 'CLI JSON violations exit zero', $errors);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cli) . ' --profile ' . escapeshellarg($profilePath) . ' --policy ' . escapeshellarg($fixtures . '/policy_invalid_unknown_key.json') . ' >/dev/null 2>&1', $out, $exitInvalid);
policy_same(3, $exitInvalid, 'CLI invalid policy exit 3', $errors);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cli) . ' --profile ' . escapeshellarg($profilePath) . ' --policy ' . escapeshellarg($tmp . '/missing-policy.json') . ' >/dev/null 2>&1', $out, $exitMissingPolicy);
policy_same(2, $exitMissingPolicy, 'CLI missing policy exit 2', $errors);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cli) . ' --profile ' . escapeshellarg($tmp . '/missing.json') . ' --policy ' . escapeshellarg($policyPath) . ' >/dev/null 2>&1', $out, $exitMissing);
policy_same(2, $exitMissing, 'CLI missing profile exit 2', $errors);
$cliJsonPath = $tmp . '/cli/mysql_policy.json';
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cli) . ' --format=json --json=' . escapeshellarg($cliJsonPath) . ' --profile ' . escapeshellarg($profilePath) . ' --policy ' . escapeshellarg($policyPath) . ' >/dev/null 2>&1', $out, $exitJsonWrite);
policy_same(0, $exitJsonWrite, 'CLI atomic JSON exit zero', $errors);
policy_assert(is_file($cliJsonPath), 'CLI atomic JSON written', $errors);
$incompatiblePath = $tmp . '/incompatible.json';
file_put_contents($incompatiblePath, json_encode(['report_version' => 9, 'engine' => 'mysql', 'summary' => [], 'queries' => []]));
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cli) . ' --profile ' . escapeshellarg($incompatiblePath) . ' --policy ' . escapeshellarg($policyPath) . ' >/dev/null 2>&1', $out, $exitIncompatible);
policy_same(4, $exitIncompatible, 'CLI incompatible profile exit 4', $errors);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cli) . ' --help >/dev/null 2>&1', $out, $exitHelp);
policy_same(0, $exitHelp, 'CLI help exit zero', $errors);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . PHP_EOL);
    }
    exit(1);
}

echo "MySQL query policy PASS\n";
