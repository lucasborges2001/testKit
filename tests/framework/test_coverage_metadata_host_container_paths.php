<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/common/Env.php';
require_once __DIR__ . '/../../core/php/common/Paths.php';
require_once __DIR__ . '/../../core/php/coverage/CoverageMetadata.php';

use Testkit\Core\Coverage\CoverageMetadata;

$errors = [];
$runtimeErrors = [];

set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$runtimeErrors): bool {
    $runtimeErrors[] = $message . ' at ' . $file . ':' . $line;
    return true;
});

function assert_same_host_container_paths(mixed $actual, mixed $expected, string $message, array &$errors): void
{
    if ($actual !== $expected) {
        $errors[] = $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true);
    }
}

function assert_true_host_container_paths(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

function mkdir_p_host_container_paths(string $path): void
{
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
}

function rm_rf_host_container_paths(string $path): void
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
            rm_rf_host_container_paths($path . '/' . $item);
        }
    }
    @rmdir($path);
}

function set_env_host_container_paths(string $key, ?string $value): void
{
    if ($value === null) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
        return;
    }

    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

function get_env_host_container_paths(string $key): ?string
{
    $value = getenv($key);
    return $value === false ? null : (string)$value;
}

/** @return array{code:int,stdout:string,stderr:string} */
function run_report_host_container_paths(string $repoUnderTest, string $hostRepoRoot, string $artifactRoot): array
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

function write_json_host_container_paths(string $path, array $payload): void
{
    mkdir_p_host_container_paths(dirname($path));
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$keys = [
    'TESTKIT_PROJECT_ROOT',
    'TK_REPO_ROOT',
    'TESTKIT_ARTIFACTS_ROOT',
    'TEST_COVERAGE_ROOT',
    'TEST_COVERAGE_SUMMARY_TOP',
];
$previousEnv = [];
foreach ($keys as $key) {
    $previousEnv[$key] = get_env_host_container_paths($key);
}

$root = sys_get_temp_dir() . '/testkit_coverage_host_container_' . uniqid('', true);
$repoRoot = $root . '/repo';
$artifactRoot = $repoRoot . '/.testkit';
$coverageDir = $artifactRoot . '/coverage/back_php';
$reportsRoot = $artifactRoot . '/reports';
$hostRepoRoot = dirname(__DIR__, 2);

try {
    mkdir_p_host_container_paths($coverageDir);
    mkdir_p_host_container_paths($reportsRoot);
    set_env_host_container_paths('TESTKIT_PROJECT_ROOT', $repoRoot);
    set_env_host_container_paths('TK_REPO_ROOT', $repoRoot);
    set_env_host_container_paths('TESTKIT_ARTIFACTS_ROOT', $artifactRoot);

    $relativeDiagnostics = $coverageDir . '/coverage_diagnostics.json';
    file_put_contents($relativeDiagnostics, '{}');

    $absoluteExisting = $root . '/absolute/.testkit/coverage/back_php/coverage_diagnostics.json';
    mkdir_p_host_container_paths(dirname($absoluteExisting));
    file_put_contents($absoluteExisting, '{}');

    assert_same_host_container_paths(
        CoverageMetadata::resolveArtifactPath([
            'diagnostics_file' => $absoluteExisting,
            'diagnostics_file_rel' => '.testkit/coverage/back_php/coverage_diagnostics.json',
            'coverage_dir' => $coverageDir,
            'coverage_dir_rel' => '.testkit/coverage/back_php',
        ], 'diagnostics_file', 'coverage_diagnostics.json', $repoRoot),
        str_replace('\\', '/', $absoluteExisting),
        'existing absolute diagnostics path should win over relative fallback',
        $errors
    );

    assert_same_host_container_paths(
        CoverageMetadata::resolveArtifactPath([
            'diagnostics_file' => '/workspace/project/.testkit/coverage/back_php/coverage_diagnostics.json',
            'diagnostics_file_rel' => '.testkit/coverage/back_php/coverage_diagnostics.json',
            'coverage_dir' => '/workspace/project/.testkit/coverage/back_php',
            'coverage_dir_rel' => '.testkit/coverage/back_php',
        ], 'diagnostics_file', 'coverage_diagnostics.json', $repoRoot),
        $relativeDiagnostics,
        'missing container absolute diagnostics path should fall back to repo-relative diagnostics path',
        $errors
    );

    assert_same_host_container_paths(
        CoverageMetadata::resolveArtifactPath([
            'diagnostics_file' => 'coverage_diagnostics.json',
            'coverage_dir' => '/workspace/project/.testkit/coverage/back_php',
            'coverage_dir_rel' => '.testkit/coverage/back_php',
        ], 'diagnostics_file', 'coverage_diagnostics.json', $repoRoot),
        $relativeDiagnostics,
        'basename diagnostics path should resolve through coverage_dir_rel when coverage_dir is from another environment',
        $errors
    );

    assert_same_host_container_paths(
        CoverageMetadata::resolveArtifactPath([
            'diagnostics_file' => '',
            'coverage_dir' => '/workspace/project/.testkit/coverage/back_php',
            'coverage_dir_rel' => '.testkit/coverage/back_php',
        ], 'diagnostics_file', 'coverage_diagnostics.json', $repoRoot),
        $relativeDiagnostics,
        'empty diagnostics field should use fallback basename through coverage_dir_rel',
        $errors
    );

    foreach (['../outside.json', '../../etc/passwd', '/tmp/absolute.json', 'C:\\absolute\\file.json'] as $unsafeRel) {
        $resolved = CoverageMetadata::resolvePathWithFallback(
            ['diagnostics_file_rel' => $unsafeRel],
            'diagnostics_file',
            'diagnostics_file_rel',
            $repoRoot
        );
        assert_same_host_container_paths($resolved, null, 'unsafe relative field should be ignored: ' . $unsafeRel, $errors);
    }

    $runId = 'run-current';
    write_json_host_container_paths($relativeDiagnostics, [
        'overall' => ['lines_total' => 10, 'lines_hit' => 8, 'percent' => 80.0],
        'critical_missing' => [],
        'critical_low' => [],
    ]);
    write_json_host_container_paths($coverageDir . '/coverage_meta.json', [
        'schema_version' => 1,
        'suite_id' => 'back_php',
        'generated_at' => '2026-06-14T20:49:57Z',
        'coverage_dir' => '/workspace/project/.testkit/coverage/back_php',
        'coverage_dir_rel' => '.testkit/coverage/back_php',
        'metadata_file' => '/workspace/project/.testkit/coverage/back_php/coverage_meta.json',
        'metadata_file_rel' => '.testkit/coverage/back_php/coverage_meta.json',
        'report_root' => '/workspace/project/.testkit/reports',
        'report_root_rel' => '.testkit/reports',
        'run_id' => $runId,
        'meta_run_id' => $runId,
        'coverage_enabled' => true,
        'coverage_format' => 'both',
        'source_dirs' => ['back', 'public_html'],
        'exclude_dirs' => ['test', 'testkit', 'docker', 'vendor', 'logs', 'storage'],
        'diagnostics_file' => '/workspace/project/.testkit/coverage/back_php/coverage_diagnostics.json',
        'diagnostics_file_rel' => '.testkit/coverage/back_php/coverage_diagnostics.json',
        'report_file' => '/workspace/project/.testkit/coverage/back_php/coverage_report.md',
        'report_file_rel' => '.testkit/coverage/back_php/coverage_report.md',
        'coverage_file' => '/workspace/project/.testkit/coverage/back_php/coverage.json',
        'coverage_file_rel' => '.testkit/coverage/back_php/coverage.json',
        'lcov_file' => '/workspace/project/.testkit/coverage/back_php/lcov.info',
        'lcov_file_rel' => '.testkit/coverage/back_php/lcov.info',
        'diagnostics_summary' => [
            'overall_percent' => 80.0,
            'critical_missing_count' => 0,
            'critical_low_count' => 0,
        ],
    ]);
    write_json_host_container_paths($reportsRoot . '/back_php_latest.json', [
        'suite_id' => 'back_php',
        'run_id' => $runId,
        'report_root' => '/workspace/project/.testkit/reports',
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
            'enabled' => true,
            'generated' => true,
            'status' => 'generated',
            'dir' => '/workspace/project/.testkit/coverage/back_php',
            'dir_rel' => '.testkit/coverage/back_php',
            'metadata_file' => '/workspace/project/.testkit/coverage/back_php/coverage_meta.json',
            'metadata_file_rel' => '.testkit/coverage/back_php/coverage_meta.json',
            'diagnostics_file' => '/workspace/project/.testkit/coverage/back_php/coverage_diagnostics.json',
            'diagnostics_file_rel' => '.testkit/coverage/back_php/coverage_diagnostics.json',
            'run_id' => $runId,
            'report_root' => '/workspace/project/.testkit/reports',
        ],
    ]);

    $report = run_report_host_container_paths($repoRoot, $hostRepoRoot, $artifactRoot);
    assert_same_host_container_paths($report['code'], 0, 'report.php should exit cleanly with host/container fallback coverage paths', $errors);
    assert_same_host_container_paths($report['stderr'], '', 'report.php should not emit warnings for host/container fallback coverage paths', $errors);
    assert_true_host_container_paths(str_contains($report['stdout'], '- back_php: overall=80% critical_missing=0 critical_low=0'), 'report.php should print current coverage by using repo-relative fallback paths', $errors);
    assert_true_host_container_paths(!str_contains($report['stdout'], 'stale coverage available'), 'report.php should not mark matching metadata stale when relative fallback exists', $errors);
} finally {
    restore_error_handler();
    foreach ($previousEnv as $key => $value) {
        set_env_host_container_paths($key, $value);
    }
    rm_rf_host_container_paths($root);
}

if ($runtimeErrors !== []) {
    foreach ($runtimeErrors as $error) {
        fwrite(STDERR, 'RUNTIME ERROR: ' . $error . "\n");
    }
    exit(1);
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Coverage host/container path fallback contract PASS\n";
exit(0);
