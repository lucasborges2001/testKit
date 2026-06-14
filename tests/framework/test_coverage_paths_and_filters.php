<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/common/Env.php';
require_once __DIR__ . '/../../core/php/common/Paths.php';
require_once __DIR__ . '/../../core/php/coverage/CoverageFilter.php';
require_once __DIR__ . '/../../core/php/coverage/CoverageDiagnostics.php';

use Testkit\Core\Common\Paths;
use Testkit\Core\Coverage\CoverageDiagnostics;

$errors = [];

function assert_same_coverage(mixed $actual, mixed $expected, string $message, array &$errors): void
{
    if ($actual !== $expected) {
        $errors[] = $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true);
    }
}

function assert_true_coverage(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

function set_env_coverage(string $key, ?string $value): void
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

function get_env_coverage(string $key): ?string
{
    $value = getenv($key);
    return $value === false ? null : (string)$value;
}

function mkdir_p_coverage(string $path): void
{
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
}

function rm_rf_coverage(string $path): void
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
            rm_rf_coverage($path . '/' . $item);
        }
    }
    @rmdir($path);
}

function write_php_file_coverage(string $path): void
{
    mkdir_p_coverage(dirname($path));
    file_put_contents($path, "<?php\nfunction testkit_coverage_sample_" . md5($path) . "(): void {}\n");
}

$keys = [
    'TESTKIT_PROJECT_ROOT',
    'TK_REPO_ROOT',
    'TESTKIT_ARTIFACTS_ROOT',
    'TEST_COVERAGE_ROOT',
    'TEST_COVERAGE_DIR',
    'TEST_COVERAGE_SOURCE_DIRS',
    'TEST_COVERAGE_EXCLUDE_DIRS',
    'TEST_COVERAGE_CRITICAL_FILES',
    'TEST_COVERAGE_CRITICAL_THRESHOLD',
    'TEST_COVERAGE_LOW_THRESHOLD',
    'TK_BACK_DIR',
    'TK_PUBLIC_DIR',
];
$previousEnv = [];
foreach ($keys as $key) {
    $previousEnv[$key] = get_env_coverage($key);
}

$root = sys_get_temp_dir() . '/testkit_coverage_contract_' . uniqid('', true);
$repoRoot = $root . '/repo';
$artifactRoot = $repoRoot . '/.testkit';

try {
    mkdir_p_coverage($artifactRoot);
    set_env_coverage('TESTKIT_PROJECT_ROOT', $repoRoot);
    set_env_coverage('TK_REPO_ROOT', $repoRoot);
    set_env_coverage('TESTKIT_ARTIFACTS_ROOT', $artifactRoot);
    set_env_coverage('TK_BACK_DIR', 'back');
    set_env_coverage('TK_PUBLIC_DIR', 'public_html');
    set_env_coverage('TEST_COVERAGE_ROOT', null);
    set_env_coverage('TEST_COVERAGE_DIR', null);

    assert_same_coverage(
        Paths::coverageDirForSuite('back_php'),
        Paths::normalize($artifactRoot . '/coverage/back_php'),
        'default coverage dir should live under .testkit/coverage/<suite_id>',
        $errors
    );

    set_env_coverage('TEST_COVERAGE_ROOT', '/tmp/testkit_cov_root');
    assert_same_coverage(
        Paths::coverageDirForSuite('front_php'),
        '/tmp/testkit_cov_root/front_php',
        'TEST_COVERAGE_ROOT should be treated as coverage root',
        $errors
    );

    set_env_coverage('TEST_COVERAGE_ROOT', null);
    set_env_coverage('TEST_COVERAGE_DIR', '/tmp/testkit_legacy_cov_root');
    assert_same_coverage(
        Paths::coverageDirForSuite('back_python'),
        '/tmp/testkit_legacy_cov_root/back_python',
        'legacy TEST_COVERAGE_DIR should remain a root alias',
        $errors
    );

    set_env_coverage('TEST_COVERAGE_DIR', null);
    set_env_coverage('TEST_COVERAGE_SOURCE_DIRS', 'back,public_html');
    set_env_coverage('TEST_COVERAGE_EXCLUDE_DIRS', 'test,testkit,docker,vendor,logs,storage');
    set_env_coverage('TEST_COVERAGE_CRITICAL_FILES', 'back/*.php,public_html/*.php');
    set_env_coverage('TEST_COVERAGE_CRITICAL_THRESHOLD', '80');
    set_env_coverage('TEST_COVERAGE_LOW_THRESHOLD', '80');

    write_php_file_coverage($repoRoot . '/back/low.php');
    write_php_file_coverage($repoRoot . '/back/missing.php');
    write_php_file_coverage($repoRoot . '/public_html/ok.php');
    write_php_file_coverage($repoRoot . '/testkit/internal.php');
    write_php_file_coverage($repoRoot . '/storage/cache.php');

    $merged = [
        $repoRoot . '/back/low.php' => [1 => 1, 2 => 0],
        $repoRoot . '/public_html/ok.php' => [1 => 1, 2 => 1],
        $repoRoot . '/testkit/internal.php' => [1 => 1, 2 => 1, 3 => 1, 4 => 1],
        $repoRoot . '/storage/cache.php' => [1 => 0, 2 => 0],
    ];

    $diagnostics = CoverageDiagnostics::analyze($merged, ['suite_id' => 'back_php']);

    assert_same_coverage(
        $diagnostics['overall']['lines_total'] ?? null,
        4,
        'overall should count only configured source dirs and exclude ignored dirs',
        $errors
    );
    assert_same_coverage(
        $diagnostics['overall']['lines_hit'] ?? null,
        3,
        'overall hit count should exclude testkit/storage files',
        $errors
    );
    assert_same_coverage(
        $diagnostics['overall']['percent'] ?? null,
        75.0,
        'overall percent should be computed after filtering',
        $errors
    );

    $files = array_map(static fn(array $row): string => (string)$row['rel'], (array)($diagnostics['files'] ?? []));
    sort($files);
    assert_same_coverage(
        $files,
        ['back/low.php', 'public_html/ok.php'],
        'files should include only source-filtered coverage rows',
        $errors
    );

    assert_true_coverage(
        in_array('back/missing.php', (array)($diagnostics['critical_missing'] ?? []), true),
        'critical_missing should list source-filtered critical files with no coverage data',
        $errors
    );

    $criticalLow = array_map(static fn(array $row): string => (string)$row['rel'], (array)($diagnostics['critical_low'] ?? []));
    assert_same_coverage(
        $criticalLow,
        ['back/low.php'],
        'critical_low should list covered critical files below threshold after filtering',
        $errors
    );

    assert_true_coverage(
        !in_array('testkit/internal.php', $files, true),
        'testkit files must not leak into diagnostics files',
        $errors
    );
} finally {
    foreach ($previousEnv as $key => $value) {
        set_env_coverage($key, $value);
    }
    rm_rf_coverage($root);
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Coverage paths and filters contract PASS\n";
exit(0);
