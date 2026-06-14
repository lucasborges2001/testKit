<?php
declare(strict_types=1);

$errors = [];

function assert_contains_stale_guard(string $haystack, string $needle, string $message, array &$errors): void
{
    if (!str_contains($haystack, $needle)) {
        $errors[] = $message . ' missing=' . var_export($needle, true) . "\noutput=" . $haystack;
    }
}

function assert_not_contains_stale_guard(string $haystack, string $needle, string $message, array &$errors): void
{
    if (str_contains($haystack, $needle)) {
        $errors[] = $message . ' unexpected=' . var_export($needle, true) . "\noutput=" . $haystack;
    }
}

function assert_same_stale_guard(mixed $actual, mixed $expected, string $message, array &$errors): void
{
    if ($actual !== $expected) {
        $errors[] = $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true);
    }
}

function mkdir_p_stale_guard(string $path): void
{
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
}

function rm_rf_stale_guard(string $path): void
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
            rm_rf_stale_guard($path . '/' . $item);
        }
    }
    @rmdir($path);
}

/** @return array{code:int,stdout:string,stderr:string} */
function run_report_stale_guard(string $repoUnderTest, string $hostRepoRoot, string $artifactRoot): array
{
    $cmd = [PHP_BINARY, $hostRepoRoot . '/scripts/report.php'];
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
        'TEST_COVERAGE_SUMMARY_TOP' => '10',
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

    return [
        'code' => (int)proc_close($proc),
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

function write_suite_report_stale_guard(string $reportsRoot, string $runId, bool $coverageEnabled, bool $coverageGenerated, string $coverageDir): void
{
    mkdir_p_stale_guard($reportsRoot);
    file_put_contents($reportsRoot . '/back_php_latest.json', json_encode([
        'suite_id' => 'back_php',
        'run_id' => $runId,
        'report_root' => $reportsRoot,
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
        'coverage' => [
            'enabled' => $coverageEnabled,
            'generated' => $coverageGenerated,
            'status' => $coverageGenerated ? 'generated' : ($coverageEnabled ? 'missing_artifacts' : 'disabled'),
            'dir' => $coverageDir,
            'metadata_file' => $coverageGenerated ? ($coverageDir . '/coverage_meta.json') : null,
            'diagnostics_file' => $coverageGenerated ? ($coverageDir . '/coverage_diagnostics.json') : null,
            'run_id' => $runId,
            'report_root' => $reportsRoot,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function write_stale_coverage_files(string $coverageDir, string $reportsRoot, string $metadataRunId): void
{
    mkdir_p_stale_guard($coverageDir);
    file_put_contents($coverageDir . '/coverage_diagnostics.json', json_encode([
        'overall' => ['lines_total' => 10, 'lines_hit' => 9, 'percent' => 90.0],
        'critical_missing' => ['back/old_missing.php'],
        'critical_low' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents($coverageDir . '/coverage_meta.json', json_encode([
        'schema_version' => 1,
        'suite_id' => 'back_php',
        'generated_at' => '2026-06-14T20:49:57Z',
        'coverage_dir' => $coverageDir,
        'coverage_dir_rel' => '.testkit/coverage/back_php',
        'report_root' => $reportsRoot,
        'report_root_rel' => '.testkit/reports',
        'run_id' => $metadataRunId,
        'meta_run_id' => $metadataRunId,
        'coverage_enabled' => true,
        'coverage_format' => 'both',
        'source_dirs' => ['back', 'public_html'],
        'exclude_dirs' => ['test', 'testkit', 'docker', 'vendor', 'logs', 'storage'],
        'diagnostics_file' => 'coverage_diagnostics.json',
        'report_file' => 'coverage_report.md',
        'coverage_file' => 'coverage.json',
        'lcov_file' => 'lcov.info',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$hostRepoRoot = dirname(__DIR__, 2);
$root = sys_get_temp_dir() . '/testkit_report_stale_guard_' . uniqid('', true);
$repoUnderTest = $root . '/repo';
$artifactRoot = $repoUnderTest . '/.testkit';
$reportsRoot = $artifactRoot . '/reports';
$coverageDir = $artifactRoot . '/coverage/back_php';
$legacyDir = $repoUnderTest . '/test/coverage/php_back';

try {
    write_suite_report_stale_guard($reportsRoot, 'run-current', false, false, $coverageDir);
    write_stale_coverage_files($coverageDir, $reportsRoot, 'old-run');
    $disabledWithStale = run_report_stale_guard($repoUnderTest, $hostRepoRoot, $artifactRoot);
    assert_same_stale_guard($disabledWithStale['code'], 0, 'report.php should exit cleanly when disabled suite has stale coverage', $errors);
    assert_contains_stale_guard($disabledWithStale['stdout'], '- back_php: stale coverage available at .testkit/coverage/back_php, not attached to current run', 'disabled coverage run should mark stale diagnostics', $errors);
    assert_not_contains_stale_guard($disabledWithStale['stdout'], 'overall=90%', 'disabled coverage run must not print stale overall', $errors);
    assert_not_contains_stale_guard($disabledWithStale['stdout'], 'back/old_missing.php', 'disabled coverage run must not print stale file list', $errors);

    rm_rf_stale_guard($coverageDir);
    write_suite_report_stale_guard($reportsRoot, 'run-current', false, false, $coverageDir);
    $disabledNoCoverage = run_report_stale_guard($repoUnderTest, $hostRepoRoot, $artifactRoot);
    assert_same_stale_guard($disabledNoCoverage['code'], 0, 'report.php should exit cleanly when no coverage exists', $errors);
    assert_contains_stale_guard($disabledNoCoverage['stdout'], '- back_php: not generated for this run', 'disabled coverage run without stale artifacts should say not generated', $errors);

    write_suite_report_stale_guard($reportsRoot, 'run-current', true, true, $coverageDir);
    write_stale_coverage_files($coverageDir, $reportsRoot, 'old-run');
    $mismatch = run_report_stale_guard($repoUnderTest, $hostRepoRoot, $artifactRoot);
    assert_same_stale_guard($mismatch['code'], 0, 'report.php should exit cleanly when metadata run mismatches suite report', $errors);
    assert_contains_stale_guard($mismatch['stdout'], '- back_php: stale coverage available at .testkit/coverage/back_php, not attached to current run', 'mismatched metadata should be stale', $errors);
    assert_not_contains_stale_guard($mismatch['stdout'], 'overall=90%', 'mismatched metadata must not print stale overall', $errors);

    rm_rf_stale_guard($coverageDir);
    write_suite_report_stale_guard($reportsRoot, 'run-current', false, false, $legacyDir);
    write_stale_coverage_files($legacyDir, $reportsRoot, 'old-run');
    $legacy = run_report_stale_guard($repoUnderTest, $hostRepoRoot, $artifactRoot);
    assert_same_stale_guard($legacy['code'], 0, 'report.php should exit cleanly with legacy stale coverage', $errors);
    assert_contains_stale_guard($legacy['stdout'], '- back_php: legacy/stale coverage available at test/coverage/php_back, not attached to current run', 'legacy stale coverage should be marked explicitly', $errors);
    assert_not_contains_stale_guard($legacy['stdout'], 'overall=90%', 'legacy stale coverage must not print stale overall', $errors);
} finally {
    rm_rf_stale_guard($root);
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Report coverage stale guard contract PASS\n";
exit(0);
