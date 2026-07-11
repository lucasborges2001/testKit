<?php
declare(strict_types=1);

putenv('TESTKIT_DB_PROFILE=1');
putenv('TESTKIT_DB_PROFILE_SLOW_MAX_MS=100');
putenv('TESTKIT_DB_PROFILE_HOTSPOT_TOTAL_MS=250');
putenv('TESTKIT_DB_PROFILE_HIGH_CALLS=3');
putenv('TESTKIT_DB_PROFILE_WATCH_RATIO=0.75');
putenv('TESTKIT_DB_PROFILE_SAMPLE_LIMIT=32');

require_once __DIR__ . '/../../core/php/dbprofiling/bootstrap.php';

use Testkit\Core\DbProfiling\BoundedDurationSamples;
use Testkit\Core\DbProfiling\ConnectionRegistry;
use Testkit\Core\DbProfiling\MysqlCaptureMethod;
use Testkit\Core\DbProfiling\MysqlExplainAnalyzer;
use Testkit\Core\DbProfiling\MysqlExplainPlanParser;
use Testkit\Core\DbProfiling\MysqlProfileConfig;
use Testkit\Core\DbProfiling\MysqlProfileReporter;
use Testkit\Core\DbProfiling\QueryProfileCollector;
use Testkit\Core\DbProfiling\SqlFingerprint;

$errors = [];
function assert_true(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}
function assert_same(mixed $expected, mixed $actual, string $message, array &$errors): void
{
    if ($expected !== $actual) {
        $errors[] = $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true);
    }
}
function assert_contains_value(string $needle, array $haystack, string $message, array &$errors): void
{
    if (!in_array($needle, $haystack, true)) {
        $errors[] = $message . ' missing=' . $needle . ' values=' . var_export($haystack, true);
    }
}

$leftSamples = [];
$rightSamples = [];
foreach ([1.0, 2.0, 3.0, 4.0, 5.0] as $index => $duration) {
    $leftSamples = BoundedDurationSamples::add($leftSamples, $duration, 'sample-' . $index, 3);
}
foreach (array_reverse([1.0, 2.0, 3.0, 4.0, 5.0], true) as $index => $duration) {
    $rightSamples = BoundedDurationSamples::add($rightSamples, $duration, 'sample-' . $index, 3);
}
assert_same($leftSamples, $rightSamples, 'bounded sampling deterministic across order', $errors);
assert_same(
    BoundedDurationSamples::statistics($leftSamples, 5),
    BoundedDurationSamples::statistics($rightSamples, 5),
    'percentiles deterministic across order',
    $errors
);

$connectionA = new stdClass();
$connectionB = new stdClass();
QueryProfileCollector::resetForTests();
QueryProfileCollector::enableForTests();
$connectionIdA = ConnectionRegistry::register($connectionA, 'test');
$connectionIdB = ConnectionRegistry::register($connectionB, 'test');
assert_true($connectionIdA !== '' && $connectionIdB !== '' && $connectionIdA !== $connectionIdB, 'connection ids are anonymized and collision resistant', $errors);

$fingerprint = SqlFingerprint::fingerprint("SELECT * FROM transactions WHERE user_id = 123 AND status = 'paid'");
assert_same('select * from transactions where user_id = ? and status = ?', $fingerprint, 'literal normalization', $errors);

$inFingerprint = SqlFingerprint::fingerprint("select * from users where id in (1, 2, 3) and uuid = '550e8400-e29b-41d4-a716-446655440000'");
assert_same('select * from users where id in (?) and uuid = ?', $inFingerprint, 'IN and UUID normalization', $errors);

$sample = SqlFingerprint::sampleSql("select * from users where email = 'secret@example.com' and token = 'abc123'");
assert_true(!str_contains($sample, 'secret@example.com') && !str_contains($sample, 'abc123'), 'sample SQL should not leak string values', $errors);

assert_true(MysqlExplainAnalyzer::isExplainableSql('select * from users where id = 1'), 'select should be explainable', $errors);
assert_true(!MysqlExplainAnalyzer::isExplainableSql('delete from users where id = 1'), 'delete should not be explainable', $errors);
assert_true(!MysqlExplainAnalyzer::isExplainableSql('select * from users where id = ?'), 'placeholder sample should not be explainable', $errors);

QueryProfileCollector::resetForTests();
QueryProfileCollector::enableForTests();
QueryProfileCollector::markBootstrapped();
QueryProfileCollector::record(
    "select * from users where id = 1",
    10.0,
    'test/back/users/a.test.php',
    'src/UserRepository.php:10',
    ['capture_method' => MysqlCaptureMethod::MANUAL_RECORD, 'module_id' => 'users']
);
QueryProfileCollector::record(
    "select * from users where id = 2",
    20.0,
    'test/back/users/a.test.php',
    'src/UserRepository.php:10',
    ['capture_method' => MysqlCaptureMethod::MANUAL_RECORD, 'module_id' => 'users']
);
$report = MysqlProfileReporter::buildReportFromSnapshot(QueryProfileCollector::snapshot());
assert_same(MysqlProfileConfig::REPORT_VERSION, $report['report_version'], 'report version', $errors);
assert_same(MysqlProfileConfig::SCHEMA_VERSION, $report['schema_version'], 'schema version', $errors);
assert_same(2, $report['summary']['total_queries'], 'total query count', $errors);
assert_same(1, $report['summary']['unique_fingerprints'], 'equivalent query grouping', $errors);
$query = $report['queries'][0] ?? [];
assert_same(2, $query['calls'] ?? null, 'calls aggregation', $errors);
assert_same(10.0, $query['min_ms'] ?? null, 'min aggregation', $errors);
assert_same(15.0, $query['avg_ms'] ?? null, 'avg aggregation', $errors);
assert_same(20.0, $query['max_ms'] ?? null, 'max aggregation', $errors);
assert_same(30.0, $query['total_ms'] ?? null, 'total aggregation', $errors);
assert_same(15.0, $query['p50_ms'] ?? null, 'p50 interpolation', $errors);
assert_same(19.5, $query['p95_ms'] ?? null, 'p95 interpolation', $errors);
assert_same(19.9, $query['p99_ms'] ?? null, 'p99 interpolation', $errors);
assert_same(5.0, $query['standard_deviation_ms'] ?? null, 'standard deviation', $errors);
assert_same(2, $query['sample_count'] ?? null, 'sample count', $errors);
assert_same(2, $query['capture_methods'][MysqlCaptureMethod::MANUAL_RECORD] ?? null, 'capture method aggregation', $errors);
assert_same('unknown', $report['coverage']['unknown']['overall_capture_coverage_status'] ?? null, 'overall coverage remains unknown', $errors);
assert_true(array_key_exists('overall_capture_coverage_pct', $report['coverage']['unknown']) && $report['coverage']['unknown']['overall_capture_coverage_pct'] === null, 'overall coverage null', $errors);

QueryProfileCollector::resetForTests();
QueryProfileCollector::enableForTests();
QueryProfileCollector::markBootstrapped();
QueryProfileCollector::record('select sleep(1)', 120.0);
$slow = MysqlProfileReporter::buildReportFromSnapshot(QueryProfileCollector::snapshot());
assert_same('slow', $slow['queries'][0]['classification'] ?? null, 'slow classification', $errors);

QueryProfileCollector::resetForTests();
QueryProfileCollector::enableForTests();
QueryProfileCollector::markBootstrapped();
for ($i = 1; $i <= 3; $i++) {
    QueryProfileCollector::record('select * from big_join where account_id = ' . $i, 90.0);
}
$hotspot = MysqlProfileReporter::buildReportFromSnapshot(QueryProfileCollector::snapshot());
assert_same('hotspot', $hotspot['queries'][0]['classification'] ?? null, 'hotspot classification', $errors);

QueryProfileCollector::resetForTests();
QueryProfileCollector::enableForTests();
QueryProfileCollector::markBootstrapped();
for ($i = 1; $i <= 3; $i++) {
    QueryProfileCollector::record('select * from child where parent_id = ' . $i, 5.0);
}
$n1 = MysqlProfileReporter::buildReportFromSnapshot(QueryProfileCollector::snapshot());
assert_same('n_plus_one_candidate', $n1['queries'][0]['classification'] ?? null, 'n+1 classification', $errors);

$fixtureDir = __DIR__ . '/../fixtures/mysql_explain';
$scan = MysqlExplainPlanParser::parseJson((string)file_get_contents($fixtureDir . '/full_table_scan.json'), 10000);
assert_contains_value('full_table_scan', $scan['flags'], 'full scan flag', $errors);
assert_contains_value('no_key_used', $scan['flags'], 'no key flag', $errors);
assert_contains_value('filesort', $scan['flags'], 'filesort flag', $errors);
assert_contains_value('high_rows_examined', $scan['flags'], 'high rows flag', $errors);
assert_same('warn', $scan['severity'], 'full scan severity', $errors);

$idx = MysqlExplainPlanParser::parseJson((string)file_get_contents($fixtureDir . '/index_lookup.json'), 10000);
assert_same([], $idx['flags'], 'index plan should not warn', $errors);
assert_same('info', $idx['severity'], 'index plan severity', $errors);

$tmpPlan = MysqlExplainPlanParser::parseJson((string)file_get_contents($fixtureDir . '/temporary_table.json'), 10000);
assert_contains_value('temporary_table', $tmpPlan['flags'], 'temporary flag', $errors);
assert_contains_value('filesort', $tmpPlan['flags'], 'filesort flag on grouping', $errors);
assert_contains_value('range_or_index_merge', $tmpPlan['flags'], 'range info flag', $errors);

$finding = MysqlExplainAnalyzer::findingFromJsonPlan([
    'query_id' => 'user.scan',
    'fingerprint' => 'select * from users where status = ?',
    'sample_sql' => 'select * from users where status = ?',
    'declared_policy' => ['forbid' => ['full_table_scan'], 'max_rows_examined' => 100],
], (string)file_get_contents($fixtureDir . '/full_table_scan.json'), 10000);
assert_same('analyzed', $finding['explain_status'] ?? null, 'finding status', $errors);
assert_contains_value('full_table_scan', $finding['flags'], 'finding flag', $errors);
assert_contains_value('full_table_scan', $finding['policy_violations'] ?? [], 'policy violation', $errors);

$tmp = sys_get_temp_dir() . '/testkit_mysql_profile_' . getmypid();
@mkdir($tmp, 0777, true);
putenv('TESTKIT_ARTIFACTS_ROOT=' . $tmp);
putenv('TESTKIT_DB_PROFILE_REPORT_PATH=' . $tmp . '/reports/mysql_profile_latest.json');
putenv('TESTKIT_DB_PROFILE_HISTORY_PATH=' . $tmp . '/history/mysql_profile');
putenv('TESTKIT_DB_PROFILE_SHARD_DIR=' . $tmp . '/shards');
putenv('TESTKIT_DB_PROFILE_RUN_ID=test_run');
putenv('TESTKIT_DB_PROFILE_EXPLAIN=1');
MysqlProfileReporter::prepareRun('test_run');
QueryProfileCollector::resetForTests();
QueryProfileCollector::enableForTests();
QueryProfileCollector::markBootstrapped();
QueryProfileCollector::record('select * from reports where id = 1', 7.0);
MysqlProfileReporter::writeProcessShard(QueryProfileCollector::snapshot());
$jsonReport = MysqlProfileReporter::writeLatestFromShards('test_run');
assert_true(is_file($tmp . '/reports/mysql_profile_latest.json'), 'latest JSON report should be written', $errors);
assert_same(1, $jsonReport['summary']['total_queries'] ?? null, 'JSON report summary', $errors);
assert_true(($jsonReport['explain']['enabled'] ?? false) === true, 'explain enabled report section', $errors);
assert_true(isset($jsonReport['run_metadata']['shards']), 'shard metadata exists', $errors);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "MySQL query profiling PASS\n";
