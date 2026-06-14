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

function assert_same_coverage_metadata_paths(mixed $actual, mixed $expected, string $message, array &$errors): void
{
    if ($actual !== $expected) {
        $errors[] = $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true);
    }
}

function mkdir_p_coverage_metadata_paths(string $path): void
{
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
}

function rm_rf_coverage_metadata_paths(string $path): void
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
            rm_rf_coverage_metadata_paths($path . '/' . $item);
        }
    }
    @rmdir($path);
}

function set_env_coverage_metadata_paths(string $key, ?string $value): void
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

function get_env_coverage_metadata_paths(string $key): ?string
{
    $value = getenv($key);
    return $value === false ? null : (string)$value;
}

$keys = [
    'TESTKIT_PROJECT_ROOT',
    'TK_REPO_ROOT',
    'TESTKIT_ARTIFACTS_ROOT',
];
$previousEnv = [];
foreach ($keys as $key) {
    $previousEnv[$key] = get_env_coverage_metadata_paths($key);
}

$root = sys_get_temp_dir() . '/testkit_coverage_metadata_paths_' . uniqid('', true);
$repoRoot = $root . '/repo';
$artifactRoot = $repoRoot . '/.testkit';
$coverageDir = $artifactRoot . '/coverage/back_php';

try {
    mkdir_p_coverage_metadata_paths($coverageDir);
    set_env_coverage_metadata_paths('TESTKIT_PROJECT_ROOT', $repoRoot);
    set_env_coverage_metadata_paths('TK_REPO_ROOT', $repoRoot);
    set_env_coverage_metadata_paths('TESTKIT_ARTIFACTS_ROOT', $artifactRoot);

    $base = ['coverage_dir' => $coverageDir];

    assert_same_coverage_metadata_paths(
        CoverageMetadata::resolveArtifactPath($base + ['coverage_file' => '/workspace/project/.testkit/coverage/back_php/coverage.json'], 'coverage_file', 'fallback.json'),
        '/workspace/project/.testkit/coverage/back_php/coverage.json',
        'absolute Unix path should stay absolute',
        $errors
    );

    assert_same_coverage_metadata_paths(
        CoverageMetadata::resolveArtifactPath($base + ['coverage_file' => 'C:\\tmp\\coverage.json'], 'coverage_file', 'fallback.json'),
        'C:/tmp/coverage.json',
        'absolute Windows path with backslashes should stay absolute and normalize separators',
        $errors
    );

    assert_same_coverage_metadata_paths(
        CoverageMetadata::resolveArtifactPath($base + ['coverage_file' => 'C:/tmp/coverage.json'], 'coverage_file', 'fallback.json'),
        'C:/tmp/coverage.json',
        'absolute Windows path with slashes should stay absolute',
        $errors
    );

    assert_same_coverage_metadata_paths(
        CoverageMetadata::resolveArtifactPath($base + ['coverage_file' => '\\\\server\\share\\coverage.json'], 'coverage_file', 'fallback.json'),
        '//server/share/coverage.json',
        'UNC path should stay absolute and normalize separators',
        $errors
    );

    assert_same_coverage_metadata_paths(
        CoverageMetadata::resolveArtifactPath($base + ['coverage_file' => 'coverage.json'], 'coverage_file', 'fallback.json'),
        $coverageDir . '/coverage.json',
        'relative simple path should resolve inside coverage dir',
        $errors
    );

    assert_same_coverage_metadata_paths(
        CoverageMetadata::resolveArtifactPath($base + ['coverage_file' => '.testkit/coverage/back_php/coverage.json'], 'coverage_file', 'fallback.json'),
        $coverageDir . '/.testkit/coverage/back_php/coverage.json',
        'relative nested path should resolve inside coverage dir',
        $errors
    );

    assert_same_coverage_metadata_paths(
        CoverageMetadata::resolveArtifactPath($base + ['coverage_file' => ''], 'coverage_file', 'coverage.json'),
        $coverageDir . '/coverage.json',
        'empty metadata field should use fallback basename',
        $errors
    );

    assert_same_coverage_metadata_paths(
        CoverageMetadata::resolveArtifactPath($base, 'coverage_file', 'coverage.json'),
        $coverageDir . '/coverage.json',
        'missing metadata field should use fallback basename',
        $errors
    );


    file_put_contents($coverageDir . '/coverage.json', '{}');
    assert_same_coverage_metadata_paths(
        CoverageMetadata::resolvePathWithFallback([
            'coverage_file' => '/workspace/project/.testkit/coverage/back_php/coverage.json',
            'coverage_file_rel' => '.testkit/coverage/back_php/coverage.json',
        ], 'coverage_file', 'coverage_file_rel', $repoRoot),
        $coverageDir . '/coverage.json',
        'resolvePathWithFallback should use repo-relative file when absolute belongs to a different environment',
        $errors
    );

    assert_same_coverage_metadata_paths(
        CoverageMetadata::resolvePathWithFallback([
            'coverage_file_rel' => '../outside.json',
        ], 'coverage_file', 'coverage_file_rel', $repoRoot),
        null,
        'unsafe relative fallback should not be resolved',
        $errors
    );

    $attachment = CoverageMetadata::suiteAttachment([
        'coverage_dir' => $coverageDir,
        'diagnostics_file' => 'coverage_diagnostics.json',
        'report_file' => 'coverage_report.md',
        'coverage_file' => 'coverage.json',
        'lcov_file' => 'lcov.info',
        'diagnostics_summary' => [
            'overall_percent' => 50.0,
            'critical_missing_count' => 0,
            'critical_low_count' => 0,
        ],
    ]);

    assert_same_coverage_metadata_paths($attachment['coverage_file'] ?? null, $coverageDir . '/coverage.json', 'suite attachment should resolve coverage json', $errors);
    assert_same_coverage_metadata_paths($attachment['lcov_file'] ?? null, $coverageDir . '/lcov.info', 'suite attachment should resolve lcov file', $errors);
} finally {
    restore_error_handler();
    foreach ($previousEnv as $key => $value) {
        set_env_coverage_metadata_paths($key, $value);
    }
    rm_rf_coverage_metadata_paths($root);
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

echo "Coverage metadata path handling PASS\n";
exit(0);
