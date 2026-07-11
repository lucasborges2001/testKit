<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/dbprofiling/bootstrap.php';

use Testkit\Core\DbProfiling\Baseline\MysqlQueryBaselineBuilder;
use Testkit\Core\DbProfiling\Baseline\MysqlQueryBaselineComparator;
use Testkit\Core\DbProfiling\Baseline\MysqlQueryBaselineConfig;
use Testkit\Core\DbProfiling\Baseline\MysqlQueryBaselineException;
use Testkit\Core\DbProfiling\Baseline\MysqlQueryBaselineLoader;
use Testkit\Core\DbProfiling\Baseline\MysqlQueryBaselineReporter;
use Testkit\Core\DbProfiling\MysqlProfileReporter;

$errors = [];

function baseline_assert(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

function baseline_same(mixed $expected, mixed $actual, string $message, array &$errors): void
{
    if ($expected !== $actual) {
        $errors[] = $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true);
    }
}

/** @return array<string,mixed> */
function baseline_json(string $path): array
{
    $decoded = json_decode((string)file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
    return is_array($decoded) ? $decoded : [];
}

function baseline_expect_invalid(string $path, string $errorCode, string $jsonPath, array &$errors): void
{
    try {
        MysqlQueryBaselineLoader::load($path);
        $errors[] = 'expected invalid baseline: ' . basename($path);
    } catch (MysqlQueryBaselineException $e) {
        baseline_same($errorCode, $e->errorCode(), basename($path) . ' error code', $errors);
        baseline_same($jsonPath, $e->jsonPath(), basename($path) . ' json path', $errors);
    }
}

/** @return array{code:int,stdout:string,stderr:string} */
function baseline_exec(array $args, array $env = []): array
{
    $stdout = tempnam(sys_get_temp_dir(), 'baseline_stdout_');
    $stderr = tempnam(sys_get_temp_dir(), 'baseline_stderr_');
    $command = [];
    foreach ($env as $key => $value) {
        $command[] = escapeshellarg((string)$key . '=' . (string)$value);
    }
    $command[] = escapeshellarg(PHP_BINARY);
    foreach ($args as $arg) {
        $command[] = escapeshellarg((string)$arg);
    }
    $shell = implode(' ', $command)
        . ' >' . escapeshellarg((string)$stdout)
        . ' 2>' . escapeshellarg((string)$stderr);
    $code = 0;
    exec($shell, $unused, $code);
    $result = [
        'code' => $code,
        'stdout' => (string)file_get_contents((string)$stdout),
        'stderr' => (string)file_get_contents((string)$stderr),
    ];
    @unlink((string)$stdout);
    @unlink((string)$stderr);
    return $result;
}

/** @return array<string,mixed>|null */
function baseline_query(array $comparison, string $needle): ?array
{
    foreach ((array)($comparison['queries'] ?? []) as $query) {
        if (
            is_array($query)
            && (
                str_contains((string)($query['identity'] ?? ''), $needle)
                || str_contains((string)($query['fingerprint'] ?? ''), $needle)
                || str_contains((string)($query['query_id'] ?? ''), $needle)
            )
        ) {
            return $query;
        }
    }
    return null;
}

/** @return array<string,mixed>|null */
function baseline_metric(array $query, string $metric): ?array
{
    foreach ((array)($query['metric_results'] ?? []) as $row) {
        if (is_array($row) && ($row['metric'] ?? '') === $metric) {
            return $row;
        }
    }
    return null;
}

$fixtures = __DIR__ . '/../fixtures/mysql_query_baseline';
$sourceProfile = baseline_json($fixtures . '/profile_v2_baseline_source.json');
$metadata = [
    'baseline_id' => 'catalog.main',
    'description' => 'Canonical SQL baseline',
    'dataset_id' => 'test-dataset',
    'dataset_version' => '1',
    'environment_id' => 'local-test',
];

// Builder: deterministic output, stable ordering and metadata.
$builtA = MysqlQueryBaselineBuilder::build($sourceProfile, $metadata);
$reversedProfile = $sourceProfile;
$reversedProfile['queries'] = array_reverse((array)$reversedProfile['queries']);
$builtB = MysqlQueryBaselineBuilder::build($reversedProfile, $metadata);
$builtAForOrder = $builtA;
$builtBForOrder = $builtB;
unset($builtAForOrder['baseline']['source']['profile_hash'], $builtBForOrder['baseline']['source']['profile_hash']);
baseline_same($builtAForOrder, $builtBForOrder, 'builder content must be independent of query order', $errors);
baseline_same('mysql-query-baseline-v1', $builtA['schema_version'] ?? null, 'baseline schema', $errors);
baseline_same('catalog.main', $builtA['baseline']['id'] ?? null, 'baseline ID', $errors);
baseline_same('back_php', $builtA['baseline']['compatibility']['suite_id'] ?? null, 'suite inferred from profile', $errors);
baseline_same(2, count((array)($builtA['baseline']['queries'] ?? [])), 'baseline query count', $errors);
$identities = array_column((array)$builtA['baseline']['queries'], 'identity');
$sortedIdentities = $identities;
sort($sortedIdentities, SORT_STRING);
baseline_same($sortedIdentities, $identities, 'baseline queries sorted by identity', $errors);
baseline_assert(
    preg_match('/^[a-f0-9]{64}$/', (string)($builtA['baseline']['source']['profile_hash'] ?? '')) === 1,
    'source profile hash must be SHA-256',
    $errors
);
$queryIdRows = array_values(array_filter(
    (array)$builtA['baseline']['queries'],
    static fn(mixed $row): bool => is_array($row) && ($row['query_id'] ?? '') === 'catalog.product_search'
));
baseline_assert(
    isset($queryIdRows[0]) && ($queryIdRows[0]['identity'] ?? '') === 'query_id:catalog.product_search',
    'stable query ID must be preferred',
    $errors
);

// Builder sanitization: parameters, secrets and absolute paths are not retained.
$unsafeProfile = $sourceProfile;
$unsafeProfile['queries'][0]['sample_sql'] = "SELECT * FROM users WHERE email='alice@example.test' AND token='sk_test_ABCDEFGHIJKLMNOPQRSTUVWXYZ' AND password='hunter2'";
$unsafeProfile['queries'][0]['tests'] = ['/home/private/checkout/test/back/catalog.php'];
$unsafeProfile['comparison_context']['environment_id'] = 'ci-safe';
$unsafeBaseline = MysqlQueryBaselineBuilder::build($unsafeProfile, $metadata);
$unsafeJson = json_encode($unsafeBaseline, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
foreach (['alice@example.test', 'sk_test_ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'hunter2', '/home/private/checkout'] as $secret) {
    baseline_assert(!str_contains($unsafeJson, $secret), 'secret/path leaked into baseline: ' . $secret, $errors);
}

// Loader contract and fixtures.
$valid = MysqlQueryBaselineLoader::load($fixtures . '/baseline_valid.json');
baseline_same('mysql-query-baseline-v1', $valid['schema_version'] ?? null, 'valid baseline loads', $errors);
baseline_expect_invalid($fixtures . '/baseline_invalid_schema.json', 'baseline_schema_invalid', '$.schema_version', $errors);
baseline_expect_invalid($fixtures . '/baseline_invalid_unknown_key.json', 'unknown_baseline_key', '$.baseline.unexpected', $errors);
baseline_expect_invalid($fixtures . '/baseline_invalid_duplicate_identity.json', 'baseline_identity_duplicate', '$.baseline.queries[2].identity', $errors);
baseline_expect_invalid($fixtures . '/baseline_invalid_metrics.json', 'baseline_metrics_incoherent', '$.baseline.queries[0]', $errors);
baseline_expect_invalid($fixtures . '/baseline_invalid_hash.json', 'baseline_hash_invalid', '$.baseline.source.profile_hash', $errors);
baseline_expect_invalid($fixtures . '/baseline_invalid_tolerance.json', 'baseline_tolerance_invalid', '$.baseline.comparison_defaults.time_regression_pct', $errors);

$tmpRoot = sys_get_temp_dir() . '/testkit_baseline_' . getmypid();
@mkdir($tmpRoot, 0777, true);
$invalidJson = $tmpRoot . '/invalid.json';
file_put_contents($invalidJson, '{bad json');
baseline_expect_invalid($invalidJson, 'baseline_json_invalid', '$', $errors);
$large = $tmpRoot . '/large.json';
file_put_contents($large, str_repeat(' ', 10_000_001));
baseline_expect_invalid($large, 'baseline_file_too_large', '$', $errors);
$tooMany = $builtA;
$tooMany['baseline']['queries'] = array_fill(0, 10001, []);
try {
    MysqlQueryBaselineLoader::validate($tooMany);
    $errors[] = 'query limit should fail';
} catch (MysqlQueryBaselineException $e) {
    baseline_same('baseline_query_limit', $e->errorCode(), 'query limit code', $errors);
}
$nonFinite = $builtA;
$nonFinite['baseline']['queries'][0]['p95_ms'] = INF;
try {
    MysqlQueryBaselineLoader::validate($nonFinite);
    $errors[] = 'non-finite metric should fail';
} catch (MysqlQueryBaselineException $e) {
    baseline_same('$.baseline.queries[0].p95_ms', $e->jsonPath(), 'non-finite metric path', $errors);
}

// Compatibility and metric comparison.
$equal = MysqlQueryBaselineComparator::compare(baseline_json($fixtures . '/profile_v2_equal.json'), $valid);
baseline_same('compatible', $equal['compatibility']['status'] ?? null, 'equal compatibility', $errors);
baseline_same(2, $equal['summary']['unchanged'] ?? null, 'equal queries unchanged', $errors);

$regression = MysqlQueryBaselineComparator::compare(baseline_json($fixtures . '/profile_v2_regression.json'), $valid);
baseline_same(1, $regression['summary']['regressed'] ?? null, 'regression detected', $errors);
$regressedQuery = baseline_query($regression, 'catalog.product_search');
baseline_assert(is_array($regressedQuery), 'regressed query present', $errors);
$p95 = is_array($regressedQuery) ? baseline_metric($regressedQuery, 'p95_ms') : null;
baseline_same('regressed', $p95['status'] ?? null, 'p95 regression status', $errors);
baseline_assert(($p95['delta'] ?? 0) > 0, 'p95 absolute delta positive', $errors);
baseline_assert(($p95['delta_pct'] ?? 0) > 0, 'p95 percentage delta positive', $errors);

$improvement = MysqlQueryBaselineComparator::compare(baseline_json($fixtures . '/profile_v2_improvement.json'), $valid);
baseline_same(1, $improvement['summary']['improved'] ?? null, 'improvement detected', $errors);

$newQuery = MysqlQueryBaselineComparator::compare(baseline_json($fixtures . '/profile_v2_new_query.json'), $valid);
baseline_same(1, $newQuery['summary']['new'] ?? null, 'new query detected', $errors);
$removedQuery = MysqlQueryBaselineComparator::compare(baseline_json($fixtures . '/profile_v2_removed_query.json'), $valid);
baseline_same(1, $removedQuery['summary']['removed'] ?? null, 'removed query detected', $errors);

$missing = MysqlQueryBaselineComparator::compare(baseline_json($fixtures . '/profile_v2_missing_metric.json'), $valid);
baseline_same(1, $missing['summary']['insufficient_data'] ?? null, 'missing metric is insufficient data', $errors);

$zeroBaseline = MysqlQueryBaselineLoader::load($fixtures . '/baseline_valid_zero_metric.json');
$zeroComparison = MysqlQueryBaselineComparator::compare(
    baseline_json($fixtures . '/profile_v2_current_against_zero_baseline.json'),
    $zeroBaseline
);
$zeroQuery = baseline_query($zeroComparison, 'catalog.product_search');
$zeroP95 = is_array($zeroQuery) ? baseline_metric($zeroQuery, 'p95_ms') : null;
baseline_assert(is_array($zeroP95) && array_key_exists('delta_pct', $zeroP95) && $zeroP95['delta_pct'] === null, 'baseline zero has null delta percentage', $errors);
baseline_same('baseline_zero', $zeroP95['reason'] ?? null, 'baseline zero reason', $errors);

$small = baseline_json($fixtures . '/profile_v2_equal.json');
foreach ($small['queries'] as &$smallRow) {
    if (is_array($smallRow) && str_contains((string)($smallRow['fingerprint'] ?? ''), 'products')) {
        $smallRow['p95_ms'] = 46.0;
        $smallRow['p99_ms'] = 46.0;
    }
}
unset($smallRow);
$smallComparison = MysqlQueryBaselineComparator::compare($small, $valid);
$smallQuery = baseline_query($smallComparison, 'catalog.product_search');
$smallP95 = is_array($smallQuery) ? baseline_metric($smallQuery, 'p95_ms') : null;
baseline_same('unchanged', $smallP95['status'] ?? null, 'small timing variation stays within tolerance', $errors);

// Compatibility matrix.
$cases = [
    'profile_v2_engine_incompatible.json' => ['incompatible', 'none'],
    'profile_v2_engine_version_incompatible.json' => ['incompatible', 'structural_only'],
    'profile_v2_dataset_incompatible.json' => ['incompatible', 'none'],
    'profile_v2_environment_incompatible.json' => ['compatible_with_warnings', 'structural_only'],
    'profile_v2_metadata_insufficient.json' => ['insufficient_metadata', 'structural_only'],
    'profile_v2_structural_only.json' => ['compatible_with_warnings', 'structural_only'],
    'profile_v1_legacy.json' => ['legacy_current', 'structural_only'],
];
foreach ($cases as $file => [$status, $scope]) {
    $comparison = MysqlQueryBaselineComparator::compare(baseline_json($fixtures . '/' . $file), $valid);
    baseline_same($status, $comparison['compatibility']['status'] ?? null, $file . ' compatibility', $errors);
    baseline_same($scope, $comparison['compatibility']['comparison_scope'] ?? null, $file . ' scope', $errors);
}

$exact = $valid;
$exact['baseline']['compatibility']['engine_version_mode'] = 'exact';
$currentVersion = baseline_json($fixtures . '/profile_v2_equal.json');
$currentVersion['comparison_context']['engine_version'] = '8.4.1';
$exactComparison = MysqlQueryBaselineComparator::compare($currentVersion, $exact);
baseline_same('incompatible', $exactComparison['compatibility']['status'] ?? null, 'exact engine version mismatch', $errors);
$major = $valid;
$major['baseline']['compatibility']['engine_version_mode'] = 'major';
$majorComparison = MysqlQueryBaselineComparator::compare($currentVersion, $major);
baseline_same('compatible', $majorComparison['compatibility']['status'] ?? null, 'major engine version match', $errors);

// Plan comparison.
$planCases = [
    'profile_v2_plan_unchanged.json' => ['unchanged', 0, 0],
    'profile_v2_plan_improved.json' => ['improved', 0, 1],
    'profile_v2_plan_degraded.json' => ['regressed', 1, 0],
];
foreach ($planCases as $file => [$expected, $regressed, $improved]) {
    $comparison = MysqlQueryBaselineComparator::compare(baseline_json($fixtures . '/' . $file), $valid);
    baseline_same($regressed, $comparison['summary']['regressed'] ?? null, $file . ' regressed count', $errors);
    baseline_same($improved, $comparison['summary']['improved'] ?? null, $file . ' improved count', $errors);
    $query = baseline_query($comparison, 'catalog.product_search');
    baseline_same($expected, $query['plan_result']['status'] ?? null, $file . ' plan status', $errors);
}
$planAbsent = MysqlQueryBaselineComparator::compare(baseline_json($fixtures . '/profile_v2_plan_absent.json'), $valid);
baseline_assert(($planAbsent['summary']['insufficient_data'] ?? 0) >= 1, 'absent plan is insufficient data', $errors);
$planAmbiguous = MysqlQueryBaselineComparator::compare(baseline_json($fixtures . '/profile_v2_plan_ambiguous.json'), $valid);
baseline_assert(($planAmbiguous['summary']['ambiguous'] ?? 0) >= 1, 'ambiguous plan/query identity visible', $errors);

// Query-level override is distinct from policy and suppresses the default regression threshold.
$overrideBaseline = MysqlQueryBaselineLoader::load($fixtures . '/baseline_valid_query_override.json');
$override = MysqlQueryBaselineComparator::compare(baseline_json($fixtures . '/profile_v2_regression.json'), $overrideBaseline);
$overrideQuery = baseline_query($override, 'catalog.product_search');
$overrideMetricRegressions = array_values(array_filter(
    (array)($overrideQuery['metric_results'] ?? []),
    static fn(mixed $row): bool => is_array($row) && ($row['status'] ?? '') === 'regressed'
));
baseline_same(0, count($overrideMetricRegressions), 'query metric override applied deterministically', $errors);
baseline_same('regressed', $overrideQuery['plan_result']['status'] ?? null, 'query override does not suppress plan regression', $errors);

// Policy context is shown but never recalculated.
$policyChanged = baseline_json($fixtures . '/profile_v2_equal.json');
$policyChanged['policy_evaluation']['policy_file_hash'] = str_repeat('b', 64);
$policyComparison = MysqlQueryBaselineComparator::compare($policyChanged, $valid);
baseline_assert(
    in_array('Policy hash differs between baseline and current report.', (array)($policyComparison['warnings'] ?? []), true),
    'policy hash mismatch warning',
    $errors
);

// Reporter integration, disabled mode, artifacts and invalid baseline safety.
$disabledProfile = MysqlQueryBaselineReporter::attachDisabled($sourceProfile);
baseline_same(false, $disabledProfile['baseline_comparison']['enabled'] ?? null, 'baseline disabled section', $errors);

$reportPath = $tmpRoot . '/reports/mysql_comparison_latest.json';
$historyPath = $tmpRoot . '/history/mysql_comparison';
$config = [
    'enabled' => true,
    'mode' => 'report_only',
    'file' => $fixtures . '/baseline_valid.json',
    'max_results' => 5000,
    'output' => ['report_path' => $reportPath, 'history_path' => $historyPath],
];
$comparison = MysqlQueryBaselineReporter::evaluate(
    baseline_json($fixtures . '/profile_v2_regression.json'),
    $config
);
MysqlQueryBaselineReporter::writeArtifacts($comparison, $config);
baseline_assert(is_file($reportPath), 'derived comparison artifact written', $errors);
baseline_same(1, count(glob($historyPath . '/*.json') ?: []), 'history artifact written', $errors);
$attached = MysqlQueryBaselineReporter::attachToProfile(
    baseline_json($fixtures . '/profile_v2_regression.json'),
    $comparison
);
baseline_same(true, $attached['baseline_comparison']['enabled'] ?? null, 'comparison attached to profile', $errors);
baseline_same(1, $attached['baseline_comparison']['summary']['regressed'] ?? null, 'attached summary', $errors);
baseline_assert(isset($attached['queries'][0]['baseline_status']), 'bounded per-query baseline fields', $errors);

try {
    MysqlQueryBaselineReporter::evaluate($sourceProfile, array_replace($config, ['mode' => 'fail']));
    $errors[] = 'unsupported baseline mode should fail';
} catch (MysqlQueryBaselineException $e) {
    baseline_same('unsupported_baseline_mode', $e->errorCode(), 'unsupported mode code', $errors);
}
$invalidConfig = $config;
$invalidConfig['file'] = $fixtures . '/baseline_invalid_schema.json';
try {
    MysqlQueryBaselineReporter::evaluate($sourceProfile, $invalidConfig);
    $errors[] = 'invalid baseline reporter should fail contractually';
} catch (MysqlQueryBaselineException $e) {
    $safe = MysqlQueryBaselineReporter::invalidComparison($e);
    baseline_same('invalid_baseline', $safe['status'] ?? null, 'invalid baseline remains visible', $errors);
    baseline_same('mysql-query-profile-report-v2', $sourceProfile['schema_version'] ?? null, 'base SQL report remains available', $errors);
}

// CLI creation/overwrite/force and exit codes.
$baselineScript = __DIR__ . '/../../scripts/query_baseline.php';
$comparisonScript = __DIR__ . '/../../scripts/query_comparison_report.php';
$cliBaseline = $tmpRoot . '/cli-baseline.json';
$createArgs = [
    $baselineScript, 'create',
    '--profile', $fixtures . '/profile_v2_baseline_source.json',
    '--output', $cliBaseline,
    '--baseline-id', 'test.baseline.v1',
    '--dataset-id', 'test-dataset',
    '--dataset-version', '1',
    '--environment-id', 'local-test',
];
$created = baseline_exec($createArgs);
baseline_same(0, $created['code'], 'baseline CLI create exit', $errors);
baseline_assert(is_file($cliBaseline), 'baseline CLI output exists', $errors);
$overwrite = baseline_exec($createArgs);
baseline_same(2, $overwrite['code'], 'baseline CLI refuses overwrite', $errors);
$forced = baseline_exec(array_merge($createArgs, ['--force']));
baseline_same(0, $forced['code'], 'baseline CLI explicit force', $errors);

$regressionCli = baseline_exec([
    $comparisonScript,
    '--current', $fixtures . '/profile_v2_regression.json',
    '--baseline', $cliBaseline,
]);
baseline_same(0, $regressionCli['code'], 'regressions keep CLI exit zero', $errors);
baseline_assert(str_contains($regressionCli['stdout'], 'Top regressions'), 'human output shows regressions', $errors);
$comparisonJsonPath = $tmpRoot . '/manual-comparison.json';
$jsonCli = baseline_exec([
    $comparisonScript,
    '--current', $fixtures . '/profile_v2_regression.json',
    '--baseline', $cliBaseline,
    '--format=json',
    '--json', $comparisonJsonPath,
]);
baseline_same(0, $jsonCli['code'], 'comparison JSON exit', $errors);
baseline_assert(is_file($comparisonJsonPath), 'comparison JSON written atomically', $errors);
json_decode($jsonCli['stdout'], true, 128, JSON_THROW_ON_ERROR);
$invalidCli = baseline_exec([
    $comparisonScript,
    '--current', $fixtures . '/profile_v2_equal.json',
    '--baseline', $fixtures . '/baseline_invalid_schema.json',
]);
baseline_same(3, $invalidCli['code'], 'invalid baseline CLI exit', $errors);
$missingCli = baseline_exec([
    $comparisonScript,
    '--current', $fixtures . '/missing.json',
    '--baseline', $cliBaseline,
]);
baseline_same(2, $missingCli['code'], 'missing current CLI exit', $errors);
$currentInvalid = $tmpRoot . '/current-invalid.json';
file_put_contents($currentInvalid, json_encode(['report_version' => 9, 'queries' => [], 'summary' => []]));
$currentInvalidCli = baseline_exec([
    $comparisonScript,
    '--current', $currentInvalid,
    '--baseline', $cliBaseline,
]);
baseline_same(4, $currentInvalidCli['code'], 'incompatible current CLI exit', $errors);

// Full reporter path: no baseline produces the disabled contract; invalid baseline never removes SQL data.
putenv('TESTKIT_DB_PROFILE=1');
putenv('TESTKIT_DB_PROFILE_BASELINE_FILE');
$reportWithout = MysqlProfileReporter::buildReportFromSnapshot([
    'run_id' => 'baseline_disabled', 'suite_id' => 'back_php',
    'started_at' => '2026-07-11T10:00:00Z', 'finished_at' => '2026-07-11T10:00:01Z',
    'queries' => [], 'connections' => [], 'instrumentation_findings' => [],
]);
baseline_same(false, $reportWithout['baseline_comparison']['enabled'] ?? null, 'profile reporter baseline disabled', $errors);
putenv('TESTKIT_DB_PROFILE_BASELINE_FILE=' . $fixtures . '/baseline_invalid_schema.json');
$reportInvalid = MysqlProfileReporter::buildReportFromSnapshot([
    'run_id' => 'baseline_invalid', 'suite_id' => 'back_php',
    'started_at' => '2026-07-11T10:00:00Z', 'finished_at' => '2026-07-11T10:00:01Z',
    'queries' => [], 'connections' => [], 'instrumentation_findings' => [],
]);
baseline_same('invalid_baseline', $reportInvalid['baseline_comparison']['status'] ?? null, 'invalid baseline attached safely', $errors);
baseline_same('mysql-query-profile-report-v2', $reportInvalid['schema_version'] ?? null, 'profile v2 preserved after invalid baseline', $errors);
putenv('TESTKIT_DB_PROFILE_BASELINE_FILE');

// Security scan across JSON, human output and history.
$securityCorpus = $unsafeJson
    . (string)file_get_contents($reportPath)
    . implode('', array_map(static fn(string $f): string => (string)file_get_contents($f), glob($historyPath . '/*.json') ?: []))
    . $regressionCli['stdout'] . $regressionCli['stderr'];
foreach (['alice@example.test', 'sk_test_ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'hunter2', '/home/private/checkout'] as $secret) {
    baseline_assert(!str_contains($securityCorpus, $secret), 'secret leaked into baseline artifacts/output: ' . $secret, $errors);
}

if ($errors !== []) {
    fwrite(STDERR, "MySQL query baseline FAIL\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}

echo "MySQL query baseline PASS\n";
