<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';
require_once __DIR__ . '/../../core/php/cleanup/CleanupCommand.php';

use Testkit\Core\Cleanup\CleanupCommand;
use Testkit\Core\Common\Paths;

$errors = [];

function assert_true_cleanup(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

function assert_same_cleanup(mixed $actual, mixed $expected, string $message, array &$errors): void
{
    if ($actual !== $expected) {
        $errors[] = $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true);
    }
}

function set_env_cleanup(string $key, ?string $value): void
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

function get_env_cleanup(string $key): ?string
{
    $value = getenv($key);
    return $value === false ? null : (string)$value;
}

/**
 * @return array<string,mixed>
 */
function run_cleanup_json(array $args, int $expectedExitCode, array &$errors): array
{
    ob_start();
    $exitCode = CleanupCommand::runCli(array_merge(['cleanup'], $args, ['--json']));
    $output = trim((string)ob_get_clean());

    assert_same_cleanup($exitCode, $expectedExitCode, 'cleanup exit code', $errors);

    $decoded = json_decode($output, true);
    if (!is_array($decoded)) {
        $errors[] = 'cleanup JSON output should decode. output=' . $output;
        return [];
    }

    return $decoded;
}

function mkdir_p_cleanup(string $path): void
{
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
}

function create_run_cleanup(string $runsRoot, string $name, int $mtime): string
{
    $dir = Paths::normalize($runsRoot . '/' . $name);
    mkdir_p_cleanup($dir);
    file_put_contents($dir . '/meta_latest.json', "{}\n");
    file_put_contents($dir . '/meta_20260614_120000.json', "{}\n");
    @touch($dir . '/meta_latest.json', $mtime);
    @touch($dir . '/meta_20260614_120000.json', $mtime);
    @touch($dir, $mtime);
    return $dir;
}

/**
 * @return array<int,string>
 */
function child_dir_names_cleanup(string $root): array
{
    if (!is_dir($root)) {
        return [];
    }

    $names = [];
    foreach ((array)@scandir($root) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        if (is_dir($root . '/' . $item)) {
            $names[] = $item;
        }
    }
    sort($names);
    return $names;
}

function rm_rf_cleanup(string $path): void
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
            rm_rf_cleanup($path . '/' . $item);
        }
    }
    @rmdir($path);
}

$keys = [
    'TESTKIT_ARTIFACTS_ROOT',
    'TESTKIT_PROJECT_ROOT',
    'TK_REPO_ROOT',
    'TEST_COVERAGE_DIR',
];
$previousEnv = [];
foreach ($keys as $key) {
    $previousEnv[$key] = get_env_cleanup($key);
}

$root = sys_get_temp_dir() . '/testkit_cleanup_contract_' . uniqid('', true);
$repoRoot = $root . '/repo';
$artifactRoot = $repoRoot . '/.testkit';
$runsRoot = $artifactRoot . '/reports/runs';
mkdir_p_cleanup($runsRoot);

try {
    set_env_cleanup('TESTKIT_PROJECT_ROOT', $repoRoot);
    set_env_cleanup('TK_REPO_ROOT', $repoRoot);
    set_env_cleanup('TESTKIT_ARTIFACTS_ROOT', $artifactRoot);
    set_env_cleanup('TEST_COVERAGE_DIR', null);

    $now = time();
    $runNamesNewestFirst = [
        '20260614T120000Z_newest',
        '20260614T115900Z_second',
        '20260614T115800Z_third',
        '20260614T115700Z_fourth',
        '20260614T115600Z_fifth',
    ];

    foreach ($runNamesNewestFirst as $index => $runName) {
        create_run_cleanup($runsRoot, $runName, $now - ($index * 100));
    }

    file_put_contents($artifactRoot . '/reports/meta_latest.json', "{}\n");

    $dryRun = run_cleanup_json(['reports', '--max-runs=2', '--dry-run'], 0, $errors);
    assert_same_cleanup($dryRun['mode'] ?? null, 'dry_run', 'dry-run mode should be persisted', $errors);
    assert_same_cleanup($dryRun['groups']['reports']['run_dirs_scanned'] ?? null, 5, 'dry-run should scan five run dirs', $errors);
    assert_same_cleanup($dryRun['groups']['reports']['run_dirs_delete'] ?? null, 3, 'dry-run should select three run dirs beyond max cap', $errors);
    assert_same_cleanup($dryRun['groups']['reports']['run_dirs_delete_by_max_runs'] ?? null, 3, 'dry-run should classify deletes as max-runs deletes', $errors);
    assert_same_cleanup($dryRun['summary']['delete_candidates'] ?? null, 3, 'dry-run summary should count three candidates', $errors);

    assert_same_cleanup(
        child_dir_names_cleanup($runsRoot),
        [
            '20260614T115600Z_fifth',
            '20260614T115700Z_fourth',
            '20260614T115800Z_third',
            '20260614T115900Z_second',
            '20260614T120000Z_newest',
        ],
        'dry-run must not delete run dirs',
        $errors
    );

    $apply = run_cleanup_json(['reports', '--max-runs=2', '--apply'], 0, $errors);
    assert_same_cleanup($apply['mode'] ?? null, 'apply', 'apply mode should be persisted', $errors);
    assert_same_cleanup($apply['groups']['reports']['run_dirs_scanned'] ?? null, 5, 'apply should scan original five run dirs', $errors);
    assert_same_cleanup($apply['groups']['reports']['run_dirs_delete'] ?? null, 3, 'apply should select three run dirs beyond max cap', $errors);
    assert_same_cleanup($apply['groups']['reports']['run_dirs_delete_by_max_runs'] ?? null, 3, 'apply should classify deletes as max-runs deletes', $errors);
    assert_same_cleanup($apply['summary']['deleted'] ?? null, 3, 'apply should delete three candidates', $errors);

    assert_same_cleanup(
        child_dir_names_cleanup($runsRoot),
        [
            '20260614T115900Z_second',
            '20260614T120000Z_newest',
        ],
        'apply should leave only the two newest run dirs',
        $errors
    );

    assert_true_cleanup(is_file($artifactRoot . '/reports/meta_latest.json'), 'cleanup must preserve *_latest.json files', $errors);
    assert_true_cleanup(is_file($artifactRoot . '/reports/cleanup/cleanup_latest.json'), 'cleanup should write an audit latest artifact', $errors);
} finally {
    foreach ($previousEnv as $key => $value) {
        set_env_cleanup($key, $value);
    }
    rm_rf_cleanup($root);
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Cleanup contract PASS\n";
exit(0);
