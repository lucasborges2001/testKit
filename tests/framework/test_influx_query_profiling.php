<?php
declare(strict_types=1);

putenv('TESTKIT_INFLUX_PROFILE=1');
putenv('TESTKIT_INFLUX_PROFILE_SLOW_MAX_MS=100');
putenv('TESTKIT_INFLUX_PROFILE_HOTSPOT_TOTAL_MS=250');
putenv('TESTKIT_INFLUX_PROFILE_HIGH_CALLS=3');
putenv('TESTKIT_INFLUX_PROFILE_WATCH_RATIO=0.75');
putenv('TESTKIT_INFLUX_PROFILE_MAX_RANGE_HOURS=168');
putenv('TESTKIT_INFLUX_PROFILE_REQUIRE_RANGE=1');

require_once __DIR__ . '/../../core/php/influxprofiling/bootstrap.php';

use Testkit\Core\InfluxProfiling\InfluxProfileCollector;
use Testkit\Core\InfluxProfiling\InfluxProfileReporter;
use Testkit\Core\InfluxProfiling\InfluxQueryAnalyzer;
use Testkit\Core\InfluxProfiling\InfluxQueryFingerprint;

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

$flux = 'from(bucket: "prod_metrics") |> range(start: -30d) |> filter(fn: (r) => r._measurement == "charger_sessions" and r.charger_id == "ABC123")';
$fingerprint = InfluxQueryFingerprint::fingerprint($flux);
assert_true(str_contains($fingerprint, 'from(bucket: ?)'), 'bucket should be sanitized', $errors);
assert_true(str_contains($fingerprint, 'range(start: ?)'), 'duration should be normalized', $errors);
assert_true(str_contains($fingerprint, 'r.charger_id == ?'), 'tag value should be normalized', $errors);
assert_true(!str_contains($fingerprint, 'prod_metrics') && !str_contains($fingerprint, 'ABC123'), 'fingerprint should not leak values', $errors);

$uuidQuery = 'from(bucket: "b") |> range(start: 2026-04-25T10:00:00Z, stop: 2026-04-25T11:00:00Z) |> filter(fn: (r) => r.id == "550e8400-e29b-41d4-a716-446655440000" and r.value > 123.45)';
$uuidFingerprint = InfluxQueryFingerprint::fingerprint($uuidQuery);
assert_true(!str_contains($uuidFingerprint, '550e8400') && !str_contains($uuidFingerprint, '123.45'), 'UUID/numbers/timestamps should be normalized', $errors);

$sample = InfluxQueryFingerprint::sampleQuery('from(bucket: "secret_bucket") |> filter(fn: (r) => r.token == "secret-token" and r.url == "https://influx.example.com/api")');
assert_true(!str_contains($sample, 'secret_bucket') && !str_contains($sample, 'secret-token') && !str_contains($sample, 'influx.example.com'), 'sample query should sanitize sensitive values', $errors);

$ok = InfluxQueryAnalyzer::analyze($flux);
assert_true((bool)$ok['has_range'], 'range should be detected', $errors);
assert_true((bool)$ok['has_tag_filter'], 'tag filter should be detected', $errors);

$missingRange = InfluxQueryAnalyzer::analyze('from(bucket: "b") |> filter(fn: (r) => r._measurement == "m")');
assert_contains_value('missing_range', $missingRange['risk_flags'], 'missing range flag', $errors);
assert_same('warn', $missingRange['risk_severity'], 'missing range severity', $errors);

$wide = InfluxQueryAnalyzer::analyze('from(bucket: "b") |> range(start: -30d) |> filter(fn: (r) => r.charger_id == "1")');
assert_contains_value('wide_range', $wide['risk_flags'], 'wide range flag', $errors);

$earlyPivot = InfluxQueryAnalyzer::analyze('from(bucket: "b") |> range(start: -1h) |> pivot(rowKey:["_time"], columnKey:["_field"], valueColumn:"_value") |> filter(fn: (r) => r.charger_id == "1")');
assert_contains_value('pivot_before_filter', $earlyPivot['risk_flags'], 'pivot before filter flag', $errors);

$join = InfluxQueryAnalyzer::analyze('join(tables: {a: a, b: b}, on: ["_time"])');
assert_contains_value('join_present', $join['risk_flags'], 'join flag', $errors);

$regex = InfluxQueryAnalyzer::analyze('from(bucket:"b") |> range(start:-1h) |> filter(fn: (r) => r.host =~ /api-.*/)');
assert_contains_value('regex_filter', $regex['risk_flags'], 'regex flag', $errors);

$sort = InfluxQueryAnalyzer::analyze('from(bucket:"b") |> range(start:-1h) |> sort(columns:["_time"], desc:true)');
assert_contains_value('sort_without_limit', $sort['risk_flags'], 'sort without limit flag', $errors);

InfluxProfileCollector::resetForTests();
InfluxProfileCollector::enableForTests();
InfluxProfileCollector::record($flux, 10.0, 'flux', 'a.test.php', 'caller.php:10');
InfluxProfileCollector::record(str_replace('ABC123', 'XYZ999', $flux), 20.0, 'flux', 'a.test.php', 'caller.php:10');
$report = InfluxProfileReporter::buildReportFromSnapshot(InfluxProfileCollector::snapshot());
assert_same(2, $report['summary']['total_queries'], 'total query count', $errors);
assert_same(1, $report['summary']['unique_fingerprints'], 'equivalent query grouping', $errors);
$query = $report['queries'][0] ?? [];
assert_same(2, $query['calls'] ?? null, 'calls aggregation', $errors);
assert_same(10.0, $query['min_ms'] ?? null, 'min aggregation', $errors);
assert_same(15.0, $query['avg_ms'] ?? null, 'avg aggregation', $errors);
assert_same(20.0, $query['max_ms'] ?? null, 'max aggregation', $errors);
assert_same(30.0, $query['total_ms'] ?? null, 'total aggregation', $errors);

InfluxProfileCollector::resetForTests();
InfluxProfileCollector::enableForTests();
InfluxProfileCollector::record('from(bucket:"b") |> range(start:-1h)', 120.0);
$slow = InfluxProfileReporter::buildReportFromSnapshot(InfluxProfileCollector::snapshot());
assert_same('slow', $slow['queries'][0]['classification'] ?? null, 'slow classification', $errors);

InfluxProfileCollector::resetForTests();
InfluxProfileCollector::enableForTests();
InfluxProfileCollector::record('from(bucket:"b") |> range(start:-1h) |> filter(fn:(r)=>r.charger_id=="1")', 90.0);
InfluxProfileCollector::record('from(bucket:"b") |> range(start:-1h) |> filter(fn:(r)=>r.charger_id=="2")', 90.0);
InfluxProfileCollector::record('from(bucket:"b") |> range(start:-1h) |> filter(fn:(r)=>r.charger_id=="3")', 90.0);
$hotspot = InfluxProfileReporter::buildReportFromSnapshot(InfluxProfileCollector::snapshot());
assert_same('hotspot', $hotspot['queries'][0]['classification'] ?? null, 'hotspot classification', $errors);

InfluxProfileCollector::resetForTests();
InfluxProfileCollector::enableForTests();
InfluxProfileCollector::record('from(bucket:"b") |> range(start:-1h) |> filter(fn:(r)=>r.connector_id=="1")', 5.0);
InfluxProfileCollector::record('from(bucket:"b") |> range(start:-1h) |> filter(fn:(r)=>r.connector_id=="2")', 5.0);
InfluxProfileCollector::record('from(bucket:"b") |> range(start:-1h) |> filter(fn:(r)=>r.connector_id=="3")', 5.0);
$n1 = InfluxProfileReporter::buildReportFromSnapshot(InfluxProfileCollector::snapshot());
assert_same('n_plus_one_candidate', $n1['queries'][0]['classification'] ?? null, 'n+1 classification', $errors);

InfluxProfileCollector::resetForTests();
InfluxProfileCollector::enableForTests();
InfluxProfileCollector::record('from(bucket:"b") |> pivot(rowKey:["_time"], columnKey:["_field"], valueColumn:"_value")', 5.0);
$risky = InfluxProfileReporter::buildReportFromSnapshot(InfluxProfileCollector::snapshot());
assert_same('risky_query', $risky['queries'][0]['classification'] ?? null, 'risky classification', $errors);
assert_contains_value('missing_range', $risky['queries'][0]['risk_flags'], 'risk flags propagated', $errors);

$empty = InfluxProfileReporter::buildReportFromSnapshot(['run_id' => 'empty', 'queries' => []]);
assert_same(0, $empty['summary']['total_queries'] ?? null, 'empty report total', $errors);
assert_same([], $empty['queries'] ?? null, 'empty report queries', $errors);

$tmp = sys_get_temp_dir() . '/testkit_influx_profile_' . getmypid();
@mkdir($tmp, 0777, true);
putenv('TESTKIT_ARTIFACTS_ROOT=' . $tmp);
putenv('TESTKIT_INFLUX_PROFILE_REPORT_PATH=' . $tmp . '/reports/influx_profile_latest.json');
putenv('TESTKIT_INFLUX_PROFILE_HISTORY_PATH=' . $tmp . '/history/influx_profile');
putenv('TESTKIT_INFLUX_PROFILE_SHARD_DIR=' . $tmp . '/shards');
putenv('TESTKIT_INFLUX_PROFILE_RUN_ID=test_run');
InfluxProfileCollector::resetForTests();
InfluxProfileCollector::enableForTests();
InfluxProfileCollector::record($flux, 7.0);
InfluxProfileReporter::writeProcessShard(InfluxProfileCollector::snapshot());
$jsonReport = InfluxProfileReporter::writeLatestFromShards('test_run');
assert_true(is_file($tmp . '/reports/influx_profile_latest.json'), 'latest JSON report should be written', $errors);
assert_same(1, $jsonReport['summary']['total_queries'] ?? null, 'JSON report summary', $errors);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Influx query profiling PASS\n";
