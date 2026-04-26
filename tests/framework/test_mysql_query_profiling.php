<?php
declare(strict_types=1);

putenv('TESTKIT_DB_PROFILE=1');
putenv('TESTKIT_DB_PROFILE_SLOW_MAX_MS=100');
putenv('TESTKIT_DB_PROFILE_HOTSPOT_TOTAL_MS=250');
putenv('TESTKIT_DB_PROFILE_HIGH_CALLS=3');
putenv('TESTKIT_DB_PROFILE_WATCH_RATIO=0.75');

require_once __DIR__ . '/../../core/php/dbprofiling/bootstrap.php';

use Testkit\Core\DbProfiling\MysqlExplainAnalyzer;
use Testkit\Core\DbProfiling\MysqlExplainPlanParser;
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

$fingerprint = SqlFingerprint::fingerprint("SELECT * FROM transactions WHERE user_id = 123 AND status = 'paid'");
assert_same('select * from transactions where user_id = ? and status = ?', $fingerprint, 'literal normalization', $errors);

$inFingerprint = SqlFingerprint::fingerprint("select * from users where id in (1, 2, 3) and uuid = '550e8400-e29b-41d4-a716-446655440000'");
assert_same('select * from users where id in (?) and uuid = ?', $inFingerprint, 'IN and UUID normalization', $errors);

$dateFingerprint = SqlFingerprint::fingerprint("select * from events where created_at >= '2026-04-25 10:00:00' and active = true");
assert_same('select * from events where created_at >= ? and active = ?', $dateFingerprint, 'date and boolean normalization', $errors);

$sample = SqlFingerprint::sampleSql("select * from users where email = 'secret@example.com' and token = 'abc123'");
assert_true(!str_contains($sample, 'secret@example.com') && !str_contains($sample, 'abc123'), 'sample SQL should not leak string values', $errors);

assert_true(MysqlExplainAnalyzer::isExplainableSql('select * from users where id = 1'), 'select should be explainable', $errors);
assert_true(MysqlExplainAnalyzer::isExplainableSql('WITH recent AS (select * from users) select * from recent'), 'with should be explainable', $errors);
assert_true(!MysqlExplainAnalyzer::isExplainableSql('delete from users where id = 1'), 'delete should not be explainable', $errors);
assert_true(!MysqlExplainAnalyzer::isExplainableSql('select * from users where id = ?'), 'placeholder sample should not be explainable', $errors);
assert_true(!MysqlExplainAnalyzer::isExplainableSql('select * from users; drop table users'), 'multiple statements should not be explainable', $errors);

QueryProfileCollector::resetForTests();
QueryProfileCollector::enableForTests();
QueryProfileCollector::record("select * from users where id = 1", 10.0, 'a.test.php', 'caller.php:10');
QueryProfileCollector::record("select * from users where id = 2", 20.0, 'a.test.php', 'caller.php:10');
$report = MysqlProfileReporter::buildReportFromSnapshot(QueryProfileCollector::snapshot());
assert_same(2, $report['summary']['total_queries'], 'total query count', $errors);
assert_same(1, $report['summary']['unique_fingerprints'], 'equivalent query grouping', $errors);
$query = $report['queries'][0] ?? [];
assert_same(2, $query['calls'] ?? null, 'calls aggregation', $errors);
assert_same(10.0, $query['min_ms'] ?? null, 'min aggregation', $errors);
assert_same(15.0, $query['avg_ms'] ?? null, 'avg aggregation', $errors);
assert_same(20.0, $query['max_ms'] ?? null, 'max aggregation', $errors);
assert_same(30.0, $query['total_ms'] ?? null, 'total aggregation', $errors);
assert_true(isset($report['explain']) && $report['explain']['enabled'] === false, 'report should contain disabled explain section', $errors);

QueryProfileCollector::resetForTests();
QueryProfileCollector::enableForTests();
QueryProfileCollector::record('select sleep(1)', 120.0);
$slow = MysqlProfileReporter::buildReportFromSnapshot(QueryProfileCollector::snapshot());
assert_same('slow', $slow['queries'][0]['classification'] ?? null, 'slow classification', $errors);

QueryProfileCollector::resetForTests();
QueryProfileCollector::enableForTests();
QueryProfileCollector::record('select * from big_join where account_id = 1', 90.0);
QueryProfileCollector::record('select * from big_join where account_id = 2', 90.0);
QueryProfileCollector::record('select * from big_join where account_id = 3', 90.0);
$hotspot = MysqlProfileReporter::buildReportFromSnapshot(QueryProfileCollector::snapshot());
assert_same('hotspot', $hotspot['queries'][0]['classification'] ?? null, 'hotspot classification', $errors);

QueryProfileCollector::resetForTests();
QueryProfileCollector::enableForTests();
QueryProfileCollector::record('select * from child where parent_id = 1', 5.0);
QueryProfileCollector::record('select * from child where parent_id = 2', 5.0);
QueryProfileCollector::record('select * from child where parent_id = 3', 5.0);
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
QueryProfileCollector::resetForTests();
QueryProfileCollector::enableForTests();
QueryProfileCollector::record('select * from reports where id = 1', 7.0);
MysqlProfileReporter::writeProcessShard(QueryProfileCollector::snapshot());
$jsonReport = MysqlProfileReporter::writeLatestFromShards('test_run');
assert_true(is_file($tmp . '/reports/mysql_profile_latest.json'), 'latest JSON report should be written', $errors);
assert_same(1, $jsonReport['summary']['total_queries'] ?? null, 'JSON report summary', $errors);
assert_true(($jsonReport['explain']['enabled'] ?? false) === true, 'explain enabled report section', $errors);
assert_true(($jsonReport['explain']['attempted'] ?? 0) >= 0, 'explain attempted exists', $errors);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "MySQL query profiling PASS\n";
