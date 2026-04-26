<?php
declare(strict_types=1);

putenv('TESTKIT_DB_PROFILE=1');
putenv('TESTKIT_DB_PROFILE_EXPLAIN=1');
putenv('TESTKIT_DB_PROFILE_RUN_ID=simulated_mysql_profile');
putenv('TESTKIT_DB_PROFILE_EXPLAIN_QUERIES_FILE=' . __DIR__ . '/explain_queries.json');

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/testkit_mysql_profile_example_' . getmypid();
putenv('TESTKIT_ARTIFACTS_ROOT=' . $tmp);
putenv('TESTKIT_DB_PROFILE_REPORT_PATH=' . $tmp . '/reports/mysql_profile_latest.json');
putenv('TESTKIT_DB_PROFILE_HISTORY_PATH=' . $tmp . '/history/mysql_profile');
putenv('TESTKIT_DB_PROFILE_SHARD_DIR=' . $tmp . '/shards');

require_once $root . '/core/php/dbprofiling/public_api.php';

\Testkit\Core\DbProfiling\QueryProfileCollector::resetForTests();
\Testkit\Core\DbProfiling\QueryProfileCollector::enableForTests();
\Testkit\Core\DbProfiling\QueryProfileCollector::record('SELECT * FROM users WHERE id = 1', 10.0, 'example', 'example:1');
\Testkit\Core\DbProfiling\QueryProfileCollector::record('SELECT * FROM users WHERE id = 2', 12.0, 'example', 'example:2');
\Testkit\Core\DbProfiling\QueryProfileCollector::record('SELECT * FROM orders WHERE user_id = 1 ORDER BY created_at DESC', 200.0, 'example', 'example:3');
\Testkit\Core\DbProfiling\MysqlProfileReporter::writeProcessShard(\Testkit\Core\DbProfiling\QueryProfileCollector::snapshot());
$report = \Testkit\Core\DbProfiling\MysqlProfileReporter::writeLatestFromShards('simulated_mysql_profile');

echo 'Report: ' . $tmp . '/reports/mysql_profile_latest.json' . PHP_EOL;
echo 'Queries: ' . (int)($report['summary']['total_queries'] ?? 0) . PHP_EOL;
echo 'Explain attempted: ' . (int)($report['explain']['attempted'] ?? 0) . PHP_EOL;
echo 'Explain skipped: ' . (int)($report['explain']['skipped'] ?? 0) . PHP_EOL;
