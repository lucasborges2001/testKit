<?php
declare(strict_types=1);

$errors = [];

function assert_contains_report_coverage(string $haystack, string $needle, string $message, array &$errors): void
{
    if (!str_contains($haystack, $needle)) {
        $errors[] = $message . ' missing=' . var_export($needle, true) . "\noutput=" . $haystack;
    }
}

function assert_same_report_coverage(mixed $actual, mixed $expected, string $message, array &$errors): void
{
    if ($actual !== $expected) {
        $errors[] = $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true);
    }
}

function mkdir_p_report_coverage(string $path): void
{
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
}

function rm_rf_report_coverage(string $path): void
{
    if ($path === '' || $path === '/' || !file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    $items = @scandir($path);
    if (is_array($items)) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            rm_rf_report_coverage($path . '/' . $item);
        }
    }
    @rmdir($path);
}

/** @return array{code:int,stdout:string,stderr:string} */
function run_report_coverage_script(string $repoUnderTest, string $hostRepoRoot, string $artifactRoot): array
{
    $script = $hostRepoRoot . '/scripts/report.php';
    $cmd = [PHP_BINARY, $script];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = array_merge($_ENV, [
        'TESTKIT_PROJECT_ROOT' => $repoUnderTest,
        'TK_REPO_ROOT' => $repoUnderTest,
        'TESTKIT_ARTIFACTS_ROOT' => $artifactRoot,
        'TEST_COVERAGE_ROOT' => $artifactRoot . '/coverage',
        'TEST_COVERAGE_DIR' => '',
        'TEST_COVERAGE_SUMMARY_TOP' => '1',
    ]);

    $proc = proc_open($cmd, $descriptors, $pipes, $hostRepoRoot, $env);
    if (!is_resource($proc)) {
        return ['code' => 127, 'stdout' => '', 'stderr' => 'proc_open failed'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    return [
        'code' => (int)$code,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

function write_latest_suite_report(string $reportsRoot): void
{
    mkdir_p_report_coverage($reportsRoot);
    file_put_contents($reportsRoot . '/back_php_latest.json', json_encode([
        'suite_id' => 'back_php',
        'suite_status' => 'passed',
        'outcome_status' => 'passed',
        'summary' => [
            'total' => 1,
            'passed' => 1,
            'failed' => 0,
            'skipped' => 0,
            'duration_ms' => 1,
        ],
        'tests_total' => 1,
        'pass' => 1,
        'fail' => 0,
        'skip' => 0,
        'timeout' => 0,
        'duration_ms' => 1,
        'failures' => [],
        'diagnostics' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function write_coverage_diag(string $dir, string $missingRel): void
{
    mkdir_p_report_coverage($dir);
    file_put_contents($dir . '/coverage_diagnostics.json', json_encode([
        'overall' => ['lines_total' => 11, 'lines_hit' => 6, 'percent' => 54.54],
        'critical_missing' => [
            $missingRel,
            'back/curso/service/contenido_service.php',
        ],
        'critical_low' => [
            ['percent' => 2.94, 'rel' => 'back/curso/service/delivery/pdf_resolver_para_consumo.php'],
            ['percent' => 4.35, 'rel' => 'back/auth/service/plan.php'],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$hostRepoRoot = dirname(__DIR__, 2);
$root = sys_get_temp_dir() . '/testkit_report_coverage_' . uniqid('', true);
$repoUnderTest = $root . '/repo';
$artifactRoot = $repoUnderTest . '/.testkit';
$reportsRoot = $artifactRoot . '/reports';

try {
    mkdir_p_report_coverage($reportsRoot);
    mkdir_p_report_coverage($repoUnderTest . '/test/coverage/php_back');
    write_latest_suite_report($reportsRoot);

    write_coverage_diag($artifactRoot . '/coverage/back_php', 'back/curso/service/delivery_service.php');
    $canonical = run_report_coverage_script($repoUnderTest, $hostRepoRoot, $artifactRoot);
    assert_same_report_coverage($canonical['code'], 0, 'report.php should exit cleanly with canonical coverage diagnostics', $errors);
    assert_contains_report_coverage($canonical['stdout'], '- back_php: overall=54.54% critical_missing=2 critical_low=2', 'summary should show coverage counts', $errors);
    assert_contains_report_coverage($canonical['stdout'], '  missing:', 'summary should print missing heading', $errors);
    assert_contains_report_coverage($canonical['stdout'], '    * back/curso/service/delivery_service.php', 'summary should print first missing file', $errors);
    assert_contains_report_coverage($canonical['stdout'], '    ... 1 more', 'summary should show truncated missing count', $errors);
    assert_contains_report_coverage($canonical['stdout'], '  low:', 'summary should print low heading', $errors);
    assert_contains_report_coverage($canonical['stdout'], '    * 2.94% back/curso/service/delivery/pdf_resolver_para_consumo.php', 'summary should print first low file', $errors);

    rm_rf_report_coverage($artifactRoot . '/coverage/back_php');
    write_coverage_diag($repoUnderTest . '/test/coverage/php_back', 'back/legacy/missing.php');
    $legacy = run_report_coverage_script($repoUnderTest, $hostRepoRoot, $artifactRoot);
    assert_same_report_coverage($legacy['code'], 0, 'report.php should exit cleanly with legacy coverage diagnostics fallback', $errors);
    assert_contains_report_coverage($legacy['stdout'], '    * back/legacy/missing.php', 'summary should read legacy test/coverage/php_back fallback', $errors);
} finally {
    rm_rf_report_coverage($root);
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Report coverage summary contract PASS\n";
exit(0);
