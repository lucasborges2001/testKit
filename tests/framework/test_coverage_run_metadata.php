<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/common/Env.php';
require_once __DIR__ . '/../../core/php/common/Paths.php';
require_once __DIR__ . '/../../core/php/coverage/CoverageFilter.php';
require_once __DIR__ . '/../../core/php/coverage/CoverageMetadata.php';
require_once __DIR__ . '/../../core/php/coverage/CoverageDiagnostics.php';

use Testkit\Core\Coverage\CoverageDiagnostics;
use Testkit\Core\Coverage\CoverageMetadata;

$errors = [];

function assert_same_coverage_meta(mixed $actual, mixed $expected, string $message, array &$errors): void
{
    if ($actual !== $expected) {
        $errors[] = $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true);
    }
}

function assert_true_coverage_meta(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

function set_env_coverage_meta(string $key, ?string $value): void
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

function get_env_coverage_meta(string $key): ?string
{
    $value = getenv($key);
    return $value === false ? null : (string)$value;
}

function mkdir_p_coverage_meta(string $path): void
{
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
}

function rm_rf_coverage_meta(string $path): void
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
            rm_rf_coverage_meta($path . '/' . $item);
        }
    }
    @rmdir($path);
}

$keys = [
    'TESTKIT_PROJECT_ROOT',
    'TK_REPO_ROOT',
    'TESTKIT_ARTIFACTS_ROOT',
    'TEST_COVERAGE_SOURCE_DIRS',
    'TEST_COVERAGE_EXCLUDE_DIRS',
    'TEST_RUN_ID',
    'TEST_META_RUN_ID',
];
$previousEnv = [];
foreach ($keys as $key) {
    $previousEnv[$key] = get_env_coverage_meta($key);
}

$root = sys_get_temp_dir() . '/testkit_coverage_meta_' . uniqid('', true);
$repoRoot = $root . '/repo';
$artifactRoot = $repoRoot . '/.testkit';
$coverageDir = $artifactRoot . '/coverage/back_php';
$reportRoot = $artifactRoot . '/reports/runs/run-current';

try {
    mkdir_p_coverage_meta($coverageDir);
    mkdir_p_coverage_meta($reportRoot);

    set_env_coverage_meta('TESTKIT_PROJECT_ROOT', $repoRoot);
    set_env_coverage_meta('TK_REPO_ROOT', $repoRoot);
    set_env_coverage_meta('TESTKIT_ARTIFACTS_ROOT', $artifactRoot);
    set_env_coverage_meta('TEST_COVERAGE_SOURCE_DIRS', 'back,public_html');
    set_env_coverage_meta('TEST_COVERAGE_EXCLUDE_DIRS', 'test,testkit,docker,vendor,logs,storage');
    set_env_coverage_meta('TEST_RUN_ID', 'run-current');
    set_env_coverage_meta('TEST_META_RUN_ID', 'meta-current');

    $diagnostics = [
        'overall' => ['lines_total' => 10, 'lines_hit' => 7, 'percent' => 70.0],
        'critical_missing' => ['back/missing.php'],
        'critical_low' => [['percent' => 10.0, 'rel' => 'back/low.php']],
        'source_dirs' => ['back', 'public_html'],
        'exclude_dirs' => ['test', 'testkit', 'docker', 'vendor', 'logs', 'storage'],
    ];
    $diagnosticFiles = CoverageDiagnostics::write($coverageDir, $diagnostics);
    $metadata = CoverageMetadata::write(
        [
            'suite_id' => 'back_php',
            'coverage' => true,
            'coverage_dir' => $coverageDir,
            'coverage_format' => 'both',
        ],
        [
            'suite_id' => 'back_php',
            'run_id' => 'run-current',
            'meta_run_id' => 'meta-current',
            'report_root' => $reportRoot,
        ],
        $reportRoot,
        $diagnostics,
        array_merge($diagnosticFiles, [
            'coverage_json' => $coverageDir . '/coverage.json',
            'coverage_lcov' => $coverageDir . '/lcov.info',
        ])
    );

    assert_true_coverage_meta(is_file($coverageDir . '/coverage_meta.json'), 'coverage_meta.json should be written next to diagnostics', $errors);
    assert_same_coverage_meta($metadata['suite_id'] ?? null, 'back_php', 'metadata should include suite_id', $errors);
    assert_same_coverage_meta($metadata['run_id'] ?? null, 'run-current', 'metadata should include run_id', $errors);
    assert_same_coverage_meta($metadata['meta_run_id'] ?? null, 'meta-current', 'metadata should include meta_run_id', $errors);
    assert_same_coverage_meta($metadata['report_root'] ?? null, $reportRoot, 'metadata should include report_root', $errors);
    assert_same_coverage_meta($metadata['coverage_dir'] ?? null, $coverageDir, 'metadata should include coverage_dir', $errors);
    assert_same_coverage_meta($metadata['coverage_enabled'] ?? null, true, 'metadata should mark coverage enabled', $errors);
    assert_same_coverage_meta($metadata['source_dirs'] ?? null, ['back', 'public_html'], 'metadata should preserve source dirs', $errors);
    assert_same_coverage_meta($metadata['diagnostics_file'] ?? null, 'coverage_diagnostics.json', 'metadata should reference diagnostics file', $errors);
    assert_same_coverage_meta($metadata['diagnostics_file_rel'] ?? null, '.testkit/coverage/back_php/coverage_diagnostics.json', 'metadata should include repo-relative diagnostics file fallback', $errors);
    assert_same_coverage_meta($metadata['coverage_file'] ?? null, 'coverage.json', 'metadata should reference coverage json', $errors);
    assert_same_coverage_meta($metadata['coverage_file_rel'] ?? null, '.testkit/coverage/back_php/coverage.json', 'metadata should include repo-relative coverage json fallback', $errors);
    assert_same_coverage_meta($metadata['lcov_file'] ?? null, 'lcov.info', 'metadata should reference lcov file', $errors);
    assert_same_coverage_meta($metadata['lcov_file_rel'] ?? null, '.testkit/coverage/back_php/lcov.info', 'metadata should include repo-relative lcov fallback', $errors);
    assert_same_coverage_meta($metadata['diagnostics_summary']['overall_percent'] ?? null, 70.0, 'metadata should summarize overall percent', $errors);
    assert_same_coverage_meta($metadata['diagnostics_summary']['critical_missing_count'] ?? null, 1, 'metadata should summarize missing count', $errors);
    assert_same_coverage_meta($metadata['diagnostics_summary']['critical_low_count'] ?? null, 1, 'metadata should summarize low count', $errors);

    $read = CoverageMetadata::readFromDir($coverageDir);
    assert_true_coverage_meta(is_array($read), 'metadata should be readable from coverage dir', $errors);
    assert_true_coverage_meta(CoverageMetadata::matchesReport((array)$read, [
        'suite_id' => 'back_php',
        'run_id' => 'run-current',
        'report_root' => $reportRoot,
    ]), 'metadata should match the generating suite report', $errors);
    assert_true_coverage_meta(!CoverageMetadata::matchesReport((array)$read, [
        'suite_id' => 'back_php',
        'run_id' => 'other-run',
        'report_root' => $reportRoot,
    ]), 'metadata should reject a different run_id', $errors);
} finally {
    foreach ($previousEnv as $key => $value) {
        set_env_coverage_meta($key, $value);
    }
    rm_rf_coverage_meta($root);
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Coverage run metadata contract PASS\n";
exit(0);
