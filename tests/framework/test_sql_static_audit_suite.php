<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/testkit_sql_suite_' . getmypid() . '_' . substr(sha1(uniqid('', true)), 0, 8);
@mkdir($tmp . '/test', 0775, true);
@mkdir($tmp . '/src', 0775, true);
file_put_contents($tmp . '/test/.env.test', "APP_ENV=test\n");
file_put_contents($tmp . '/src/a.php', "<?php\n\$sql = 'SELECT * FROM users';\n");

$run = static function (array $env) use ($root, $tmp): array {
    $command = array_merge(['env', '-u', 'TEST_STORE_DRIVER', 'TESTKIT_PROJECT_ROOT=' . $tmp], $env, [PHP_BINARY, $root . '/runTest.php', '--suite', 'sql-static-audit']);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $stdout = is_resource($process) ? (stream_get_contents($pipes[1]) ?: '') : '';
    $stderr = is_resource($process) ? (stream_get_contents($pipes[2]) ?: '') : '';
    if (is_resource($process)) { fclose($pipes[1]); fclose($pipes[2]); }
    return [is_resource($process) ? proc_close($process) : -1, $stdout, $stderr];
};

$errors = [];
$assert = static function (bool $ok, string $message) use (&$errors): void { if (!$ok) $errors[] = $message; };
[$code, $stdout, $stderr] = $run(['TESTKIT_SQL_STATIC_PATH=src', 'NO_COLOR=1']);
$assert($code === 0, 'findings must keep suite exit 0: ' . $stderr);
$reports = glob($tmp . '/.testkit/reports/sql-static-audit/*/suite-report.json') ?: [];
$assert(count($reports) === 1, 'suite-report.json must be produced');
$suite = $reports === [] ? null : json_decode((string)file_get_contents($reports[0]), true);
$artifact = is_array($suite) ? (string)($suite['artifacts']['sql_static_audit'] ?? '') : '';
$assert(is_array($suite) && ($suite['suite_status'] ?? '') === 'passed', 'findings suite status passed');
$assert(($suite['outcome_status'] ?? '') === 'passed', 'findings outcome status passed');
$assert(($suite['process_exit_code'] ?? null) === 0, 'findings process exit 0');
$assert(($suite['sql_static']['findings'] ?? 0) > 0, 'suite exposes finding metadata');
$assert($artifact !== '' && is_file($artifact), 'SQL artifact must be produced');
$sql = $artifact !== '' && is_file($artifact) ? json_decode((string)file_get_contents($artifact), true) : null;
$assert(($sql['schema_version'] ?? '') === 'testkit.sql-static-audit.v1', 'SQL schema remains v1');
$assert(!str_contains((string)json_encode($sql), "\033["), 'artifact JSON has no ANSI');

[$badCode] = $run(['TESTKIT_SQL_STATIC_PATH=missing', 'NO_COLOR=1']);
$assert($badCode === 1, 'MetaRunner maps operational suite failure to public failure');
$badReports = glob($tmp . '/.testkit/reports/sql-static-audit/*/suite-report.json') ?: [];
sort($badReports);
$bad = null;
foreach ($badReports as $badPath) {
    $candidate = json_decode((string)file_get_contents($badPath), true);
    if (($candidate['process_exit_code'] ?? null) === 2) $bad = $candidate;
}
$assert(($bad['process_exit_code'] ?? null) === 2, 'suite report preserves operational exit 2');

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($iterator as $fileInfo) $fileInfo->isDir() ? @rmdir($fileInfo->getPathname()) : @unlink($fileInfo->getPathname());
@rmdir($tmp);

if ($errors !== []) {
    fwrite(STDERR, "SQL static suite tests failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}
echo "SQL static suite PASS\n";
