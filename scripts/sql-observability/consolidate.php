#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/php/bootstrap.php';

use Testkit\Core\DbProfiling\MysqlProfileReporter;

$runId = getenv('TESTKIT_DB_PROFILE_RUN_ID');
if (!is_string($runId) || trim($runId) === '') {
    fwrite(STDERR, "TESTKIT_DB_PROFILE_RUN_ID is required.\n");
    exit(3);
}

$profile = MysqlProfileReporter::safeWriteLatestFromShards(trim($runId), [
    'suite_id' => 'sql_observability',
]);

echo json_encode([
    'status' => 'ok',
    'run_id' => $runId,
    'queries' => count((array)($profile['queries'] ?? [])),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
