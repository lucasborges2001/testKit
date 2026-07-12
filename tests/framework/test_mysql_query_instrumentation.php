<?php
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/testkit_mysql_instrumentation_' . getmypid();
@mkdir($tmp . '/repo/test/back/catalog', 0777, true);

putenv('TESTKIT_DB_PROFILE=1');
putenv('TESTKIT_DB_PROFILE_RUN_ID=instrumentation_run');
putenv('TEST_RUN_ID=instrumentation_run');
putenv('TEST_META_RUN_ID=meta_run');
putenv('TEST_SUITE=back_php');
putenv('TEST_WORKER_ID=7');
putenv('TK_REPO_ROOT=' . $tmp . '/repo');
putenv('TESTKIT_ARTIFACTS_ROOT=' . $tmp . '/artifacts');
putenv('TESTKIT_DB_PROFILE_REPORT_PATH=' . $tmp . '/artifacts/reports/mysql_profile_latest.json');
putenv('TESTKIT_DB_PROFILE_HISTORY_PATH=' . $tmp . '/artifacts/history/mysql_profile');
putenv('TESTKIT_DB_PROFILE_SHARD_DIR=' . $tmp . '/artifacts/shards');
putenv('TESTKIT_DB_PROFILE_SAMPLE_LIMIT=8');
putenv('TESTKIT_DB_PROFILE_MAX_CONTEXT_VALUES=2');
putenv('TESTKIT_DB_PROFILE_EXPLAIN=0');

require_once __DIR__ . '/../../core/php/dbprofiling/public_api.php';

use Testkit\Core\DbProfiling\InstrumentationContext;
use Testkit\Core\DbProfiling\MysqlCaptureMethod;
use Testkit\Core\DbProfiling\MysqlInstrumentationAudit;
use Testkit\Core\DbProfiling\MysqlProfileConfig;
use Testkit\Core\DbProfiling\MysqlProfileReporter;
use Testkit\Core\DbProfiling\QueryProfileCollector;

$errors = [];
function instrumentation_assert(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}
function instrumentation_same(mixed $expected, mixed $actual, string $message, array &$errors): void
{
    if ($expected !== $actual) {
        $errors[] = $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true);
    }
}

MysqlProfileReporter::prepareRun('instrumentation_run');
putenv('TESTKIT_DB_PROFILE_SAMPLE_LIMIT');
$workerConfig = MysqlProfileConfig::fromEnv();
instrumentation_same(8, $workerConfig['capture']['sample_limit'] ?? null, 'session config propagated to worker', $errors);
putenv('TESTKIT_DB_PROFILE_SAMPLE_LIMIT=8');
QueryProfileCollector::resetForTests();
QueryProfileCollector::enableForTests();
QueryProfileCollector::markBootstrapped();

$pdoWorked = false;
if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $pdo = tk_profiled_pdo('sqlite::memory:');
    $pdo->exec('CREATE TABLE demo (id INTEGER PRIMARY KEY, email TEXT)');
    $stmt = $pdo->prepare('INSERT INTO demo (email) VALUES (?)');
    $stmt->execute(['secret@example.com']);
    $pdo->query('SELECT * FROM demo WHERE id = 1')->fetchAll();
    $pdo->beginTransaction();
    $pdo->rollBack();
    $pdoWorked = true;
}

tk_mysql_profile_mysqli_record_query(
    "SELECT * FROM audit_log WHERE id = 99",
    3.5,
    $tmp . '/repo/test/back/catalog/catalog.test.php',
    $tmp . '/repo/src/AuditRepository.php:10',
    ['module_id' => 'audit']
);

for ($i = 1; $i <= 20; $i++) {
    tk_mysql_profile_record(
        "SELECT * FROM catalog WHERE id = {$i} AND token = 'api_key_12345678901234567890'",
        (float)$i,
        $tmp . '/repo/test/back/catalog/catalog.test.php',
        $tmp . '/repo/src/CatalogRepository.php:' . (40 + $i),
        [
            'module_id' => 'catalog',
            'scenario_id' => 'list_products',
            'capture_method' => MysqlCaptureMethod::MANUAL_RECORD,
            'connection_id' => '',
            'dsn' => 'mysql:host=prod;user=root;password=secret',
        ]
    );
}

$snapshot = QueryProfileCollector::snapshot();
instrumentation_same('instrumentation_run', $snapshot['run_id'] ?? null, 'run context', $errors);
instrumentation_same('meta_run', $snapshot['meta_run_id'] ?? null, 'meta run context', $errors);
instrumentation_same('back_php', $snapshot['suite_id'] ?? null, 'suite context', $errors);
instrumentation_same('7', $snapshot['worker_id'] ?? null, 'worker context', $errors);
instrumentation_assert(($snapshot['capture_session_id'] ?? '') !== '', 'capture session id', $errors);

MysqlProfileReporter::writeProcessShard($snapshot);
$config = MysqlProfileConfig::fromEnv();
$shardDir = (string)$config['output']['shard_dir'];

file_put_contents($shardDir . '/mysql_profile_corrupt.json', '{broken');
file_put_contents(
    $shardDir . '/mysql_profile_foreign.json',
    json_encode([
        'report_version' => 2,
        'run_id' => 'another_run',
        'capture_session_id' => $snapshot['capture_session_id'],
        'queries' => [],
    ])
);

$report = MysqlProfileReporter::writeLatestFromShards('instrumentation_run', ['suite_id' => 'back_php']);
instrumentation_same(2, $report['report_version'] ?? null, 'v2 report', $errors);
instrumentation_same('mysql-query-profile-report-v2', $report['schema_version'] ?? null, 'schema contract', $errors);
instrumentation_assert(array_key_exists('overall_capture_coverage_pct', $report['coverage']['unknown']) && $report['coverage']['unknown']['overall_capture_coverage_pct'] === null, 'unknown capture percentage', $errors);
instrumentation_same('unknown', $report['coverage']['unknown']['overall_capture_coverage_status'] ?? null, 'unknown capture status', $errors);
instrumentation_assert(($report['coverage']['facts']['captured_queries'] ?? 0) >= 20, 'captured query facts', $errors);
instrumentation_assert(($report['coverage']['calculable']['source_context_coverage_pct'] ?? 0) > 0, 'source coverage calculable', $errors);
instrumentation_assert(($report['coverage']['calculable']['test_context_coverage_pct'] ?? 0) > 0, 'test coverage calculable', $errors);
instrumentation_same(1, $report['coverage']['facts']['queries_by_capture_method'][MysqlCaptureMethod::MYSQLI_QUERY_MANUAL] ?? null, 'mysqli query capture method', $errors);
instrumentation_assert(($report['profiler_metrics']['shard_write_ms'] ?? 0) >= 0, 'shard write metric', $errors);

$manual = null;
foreach ((array)($report['queries'] ?? []) as $row) {
    if (($row['capture_methods'][MysqlCaptureMethod::MANUAL_RECORD] ?? 0) === 20) {
        $manual = $row;
        break;
    }
}
instrumentation_assert(is_array($manual), 'manual fingerprint exists', $errors);
if (is_array($manual)) {
    instrumentation_same(8, $manual['sample_count'] ?? null, 'bounded sample count', $errors);
    instrumentation_same(true, $manual['percentiles_approximate'] ?? null, 'approximate percentiles flag', $errors);
    instrumentation_assert(isset($manual['p50_ms'], $manual['p95_ms'], $manual['p99_ms'], $manual['standard_deviation_ms']), 'robust statistics', $errors);
    instrumentation_assert(!str_contains((string)$manual['sample_sql'], 'api_key_'), 'sample SQL redacts token literal', $errors);
    instrumentation_assert(!str_contains(json_encode($manual), $tmp . '/repo'), 'absolute repo path not persisted', $errors);
}

$encoded = json_encode($report, JSON_UNESCAPED_SLASHES);
instrumentation_assert(!str_contains((string)$encoded, 'secret@example.com'), 'email not leaked', $errors);
instrumentation_assert(!str_contains((string)$encoded, 'password=secret'), 'DSN password not leaked', $errors);
instrumentation_assert(!str_contains((string)$encoded, 'api_key_12345678901234567890'), 'API key not leaked', $errors);
instrumentation_assert(!str_contains((string)$encoded, 'mysql:host=prod'), 'DSN not leaked', $errors);

$codes = [];
foreach ((array)($report['instrumentation']['findings'] ?? []) as $finding) {
    $codes[] = (string)($finding['code'] ?? '');
}
instrumentation_assert(in_array('corrupt_shard', $codes, true), 'corrupt shard finding', $errors);
instrumentation_assert(in_array('foreign_shards_ignored', $codes, true), 'foreign shard finding', $errors);
instrumentation_assert(in_array('query_without_connection', $codes, true), 'query without connection finding', $errors);

if ($pdoWorked) {
    instrumentation_assert(($report['coverage']['facts']['instrumented_connections'] ?? 0) >= 1, 'instrumented PDO connection', $errors);
    $methods = (array)($report['coverage']['facts']['queries_by_capture_method'] ?? []);
    instrumentation_assert(isset($methods[MysqlCaptureMethod::PROFILED_PDO_EXEC]), 'PDO exec method', $errors);
    instrumentation_assert(isset($methods[MysqlCaptureMethod::PROFILED_PDO_QUERY]), 'PDO query method', $errors);
    instrumentation_assert(isset($methods[MysqlCaptureMethod::PROFILED_PDO_STATEMENT_EXECUTE]), 'PDO statement method', $errors);
}

$audit = MysqlInstrumentationAudit::analyze($report);
instrumentation_same(true, $audit['valid'] ?? null, 'audit valid', $errors);
instrumentation_assert(isset($audit['coverage'], $audit['findings'], $audit['recommendations']), 'audit sections', $errors);

$reportPath = $tmp . '/artifacts/reports/mysql_profile_latest.json';
$reportCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../scripts/query_report.php') . ' --path ' . escapeshellarg($reportPath);
exec($reportCommand . ' >/dev/null 2>&1', $output, $reportExit);
instrumentation_same(0, $reportExit, 'query_report valid exit', $errors);

$auditCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../scripts/query_instrumentation_audit.php') . ' --path ' . escapeshellarg($reportPath);
exec($auditCommand . ' >/dev/null 2>&1', $output, $auditExit);
instrumentation_same(0, $auditExit, 'audit warnings exit zero', $errors);

$invalidPath = $tmp . '/invalid.json';
file_put_contents($invalidPath, '{invalid');
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../scripts/query_instrumentation_audit.php')
    . ' --path ' . escapeshellarg($invalidPath) . ' >/dev/null 2>&1',
    $output,
    $invalidExit
);
instrumentation_same(2, $invalidExit, 'invalid JSON operational exit', $errors);

$legacyPath = $tmp . '/legacy-v1.json';
file_put_contents($legacyPath, json_encode([
    'report_version' => 1,
    'engine' => 'mysql',
    'summary' => ['total_queries' => 1, 'unique_fingerprints' => 1, 'total_db_time_ms' => 1],
    'rankings' => [],
    'queries' => [],
]));
exec($reportCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../scripts/query_report.php') . ' --path ' . escapeshellarg($legacyPath) . ' >/dev/null 2>&1', $output, $legacyExit);
instrumentation_same(0, $legacyExit, 'legacy v1 human report compatibility', $errors);

$missingPath = $tmp . '/missing.json';
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../scripts/query_instrumentation_audit.php')
    . ' --path ' . escapeshellarg($missingPath) . ' >/dev/null 2>&1',
    $output,
    $missingExit
);
instrumentation_same(2, $missingExit, 'missing audit report operational exit', $errors);

$corruptContractPath = $tmp . '/corrupt-contract.json';
file_put_contents($corruptContractPath, json_encode(['report_version' => 2, 'engine' => 'mysql', 'summary' => []]));
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../scripts/query_instrumentation_audit.php')
    . ' --path ' . escapeshellarg($corruptContractPath) . ' >/dev/null 2>&1',
    $output,
    $contractExit
);
instrumentation_same(3, $contractExit, 'corrupt contract exit', $errors);

$disabledRoot = $tmp . '/disabled';
putenv('TESTKIT_DB_PROFILE=0');
putenv('TESTKIT_DB_PROFILE_SHARD_DIR=' . $disabledRoot . '/shards');
putenv('TESTKIT_DB_PROFILE_REPORT_PATH=' . $disabledRoot . '/report.json');
QueryProfileCollector::resetForTests();
MysqlProfileReporter::prepareRun('disabled_run');
QueryProfileCollector::record('SELECT 1', 1.0);
instrumentation_assert(!is_dir($disabledRoot), 'profiling disabled creates no artifacts', $errors);
putenv('TESTKIT_DB_PROFILE=1');
putenv('TESTKIT_DB_PROFILE_SHARD_DIR=' . $tmp . '/artifacts/shards');
putenv('TESTKIT_DB_PROFILE_REPORT_PATH=' . $tmp . '/artifacts/reports/mysql_profile_latest.json');

$sanitized = InstrumentationContext::sanitizeMap([
    'password' => 'never-store',
    'api_key' => 'never-store',
    'safe' => 'value',
]);
instrumentation_same(['safe' => 'value'], $sanitized, 'sensitive context keys removed', $errors);
instrumentation_same(
    'sqlobs-base_database-20260712122454-local-70538-1',
    InstrumentationContext::sanitizeIdentifier('sqlobs-base_database-20260712122454-local-70538-1'),
    'sql observability run ids are identifiers, not secrets',
    $errors
);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "MySQL query instrumentation PASS\n";
