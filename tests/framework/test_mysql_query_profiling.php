<?php
declare(strict_types=1);

putenv('TESTKIT_DB_PROFILE=1');
putenv('TESTKIT_DB_PROFILE_SLOW_MAX_MS=100');
putenv('TESTKIT_DB_PROFILE_HOTSPOT_TOTAL_MS=250');
putenv('TESTKIT_DB_PROFILE_HIGH_CALLS=3');
putenv('TESTKIT_DB_PROFILE_WATCH_RATIO=0.75');

require_once __DIR__ . '/../../core/php/dbprofiling/bootstrap.php';

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

$fingerprint = SqlFingerprint::fingerprint("SELECT * FROM transactions WHERE user_id = 123 AND status = 'paid'");
assert_same('select * from transactions where user_id = ? and status = ?', $fingerprint, 'literal normalization', $errors);

$inFingerprint = SqlFingerprint::fingerprint("select * from users where id in (1, 2, 3) and uuid = '550e8400-e29b-41d4-a716-446655440000'");
assert_same('select * from users where id in (?) and uuid = ?', $inFingerprint, 'IN and UUID normalization', $errors);

QueryProfileCollector::resetForTests();
QueryProfileCollector::enableForTests();
QueryProfileCollector::record("select * from users where id = 1", 10.0, 'a.test.php', 'caller.php:10');
QueryProfileCollector::record("select * from users where id = 2", 20.0, 'a.test.php', 'caller.php:10');
$snapshot = QueryProfileCollector::snapshot();
$report = MysqlProfileReporter::buildReportFromSnapshot($snapshot);

assert_same(2, $report['summary']['total_queries'], 'total query count', $errors);
assert_same(1, $report['summary']['unique_fingerprints'], 'equivalent query grouping', $errors);
$query = $report['queries'][0] ?? [];
assert_same(2, $query['calls'] ?? null, 'calls aggregation', $errors);
assert_same(10.0, $query['min_ms'] ?? null, 'min aggregation', $errors);
assert_same(15.0, $query['avg_ms'] ?? null, 'avg aggregation', $errors);
assert_same(20.0, $query['max_ms'] ?? null, 'max aggregation', $errors);
assert_same(30.0, $query['total_ms'] ?? null, 'total aggregation', $errors);

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

$tmp = sys_get_temp_dir() . '/testkit_mysql_profile_' . getmypid();
@mkdir($tmp, 0777, true);
putenv('TESTKIT_ARTIFACTS_ROOT=' . $tmp);
putenv('TESTKIT_DB_PROFILE_REPORT_PATH=' . $tmp . '/reports/mysql_profile_latest.json');
putenv('TESTKIT_DB_PROFILE_HISTORY_PATH=' . $tmp . '/history/mysql_profile');
putenv('TESTKIT_DB_PROFILE_SHARD_DIR=' . $tmp . '/shards');
putenv('TESTKIT_DB_PROFILE_RUN_ID=test_run');
QueryProfileCollector::resetForTests();
QueryProfileCollector::enableForTests();
QueryProfileCollector::record('select * from reports where id = 1', 7.0);
\Testkit\Core\DbProfiling\MysqlProfileReporter::writeProcessShard(QueryProfileCollector::snapshot());
$jsonReport = \Testkit\Core\DbProfiling\MysqlProfileReporter::writeLatestFromShards('test_run');
assert_true(is_file($tmp . '/reports/mysql_profile_latest.json'), 'latest JSON report should be written', $errors);
assert_same(1, $jsonReport['summary']['total_queries'] ?? null, 'JSON report summary', $errors);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "MySQL query profiling PASS\n";
