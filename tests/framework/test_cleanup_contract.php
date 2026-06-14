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

function assert_false_cleanup(bool $condition, string $message, array &$errors): void
{
    assert_true_cleanup(!$condition, $message, $errors);
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
 * @return array{0:int,1:array<string,mixed>}
 */
function run_cleanup_json_with_code(array $args, array &$errors): array
{
    ob_start();
    $exitCode = CleanupCommand::runCli(array_merge(['cleanup'], $args, ['--json']));
    $output = trim((string)ob_get_clean());

    $decoded = json_decode($output, true);
    if (!is_array($decoded)) {
        $errors[] = 'cleanup JSON output should decode. output=' . $output;
        return [$exitCode, []];
    }

    return [$exitCode, $decoded];
}

/**
 * @return array<string,mixed>
 */
function run_cleanup_json(array $args, int $expectedExitCode, array &$errors): array
{
    [$exitCode, $decoded] = run_cleanup_json_with_code($args, $errors);
    assert_same_cleanup($exitCode, $expectedExitCode, 'cleanup exit code', $errors);
    return $decoded;
}

function mkdir_p_cleanup(string $path): void
{
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
}

function write_file_cleanup(string $path, string $contents = "{}\n", ?int $mtime = null): void
{
    mkdir_p_cleanup(dirname($path));
    file_put_contents($path, $contents);
    if ($mtime !== null) {
        @touch($path, $mtime);
    }
}

function create_run_cleanup(string $runsRoot, string $name, int $mtime): string
{
    $dir = Paths::normalize($runsRoot . '/' . $name);
    mkdir_p_cleanup($dir);
    write_file_cleanup($dir . '/meta_latest.json', "{}\n", $mtime);
    write_file_cleanup($dir . '/meta_20260614_120000.json', "{}\n", $mtime);
    @touch($dir, $mtime);
    return $dir;
}

function create_profile_shard_cleanup(string $artifactRoot, string $profile, string $name, int $mtime): string
{
    $dir = Paths::normalize($artifactRoot . '/' . $profile . '/shards/' . $name);
    mkdir_p_cleanup($dir);
    write_file_cleanup($dir . '/profile.json', "{}\n", $mtime);
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

/**
 * @return array<int,string>
 */
function file_names_cleanup(string $root, string $pattern = '/.*/'): array
{
    if (!is_dir($root)) {
        return [];
    }

    $names = [];
    foreach ((array)@scandir($root) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        if (is_file($root . '/' . $item) && preg_match($pattern, $item) === 1) {
            $names[] = $item;
        }
    }
    sort($names);
    return $names;
}

function candidate_path_exists_cleanup(array $payload, string $path): bool
{
    foreach (($payload['candidates'] ?? []) as $candidate) {
        if (is_array($candidate) && ($candidate['path'] ?? null) === $path) {
            return true;
        }
    }
    return false;
}

function rm_rf_cleanup(string $path): void
{
    if ($path === '' || $path === '/' || (!file_exists($path) && !is_link($path))) {
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
    'TEST_COVERAGE_ROOT',
    'TEST_COVERAGE_DIR',
    'TEST_LOCK_STALE_TTL_SEC',
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
    set_env_cleanup('TEST_COVERAGE_ROOT', null);
    set_env_cleanup('TEST_COVERAGE_DIR', null);
    set_env_cleanup('TEST_LOCK_STALE_TTL_SEC', '60');

    $now = time();

    $coverageDir = $artifactRoot . '/coverage/back_php';
    write_file_cleanup($coverageDir . '/coverage-1.json', '{"ok":true}' . "\n");

    $dryCoverage = run_cleanup_json(['coverage', '--dry-run'], 0, $errors);
    assert_same_cleanup($dryCoverage['mode'] ?? null, 'dry_run', 'coverage dry-run mode should be persisted', $errors);
    assert_same_cleanup($dryCoverage['groups']['coverage']['paths_scanned'] ?? null, 1, 'coverage dry-run should scan .testkit/coverage/back_php', $errors);
    assert_same_cleanup($dryCoverage['groups']['coverage']['paths_delete'] ?? null, 1, 'coverage dry-run should select .testkit/coverage/back_php', $errors);
    assert_true_cleanup(candidate_path_exists_cleanup($dryCoverage, '.testkit/coverage/back_php'), 'coverage dry-run should include .testkit/coverage/back_php candidate', $errors);
    assert_true_cleanup(is_dir($coverageDir), 'coverage dry-run must not delete .testkit/coverage/back_php', $errors);

    $applyCoverage = run_cleanup_json(['coverage', '--apply'], 0, $errors);
    assert_same_cleanup($applyCoverage['groups']['coverage']['paths_delete'] ?? null, 1, 'coverage apply should select one coverage path', $errors);
    assert_same_cleanup($applyCoverage['summary']['deleted'] ?? null, 1, 'coverage apply should delete one candidate', $errors);
    assert_false_cleanup(is_dir($coverageDir), 'coverage apply should delete .testkit/coverage/back_php', $errors);

    write_file_cleanup($coverageDir . '/coverage-2.json', '{"ok":true}' . "\n");
    set_env_cleanup('TEST_COVERAGE_DIR', '.testkit/coverage/back_php');
    $envCoverage = run_cleanup_json(['coverage', '--dry-run'], 0, $errors);
    assert_true_cleanup(candidate_path_exists_cleanup($envCoverage, '.testkit/coverage/back_php'), 'TEST_COVERAGE_DIR should reach cleanup runtime and resolve relative to repo root', $errors);

    set_env_cleanup('TEST_COVERAGE_DIR', '.testkit');
    $unsafeCoverage = run_cleanup_json(['coverage', '--dry-run'], 1, $errors);
    assert_same_cleanup($unsafeCoverage['groups']['coverage']['skipped_unsafe'] ?? null, 1, 'unsafe TEST_COVERAGE_DIR should be rejected', $errors);
    assert_true_cleanup((int)($unsafeCoverage['summary']['errors'] ?? 0) > 0, 'unsafe TEST_COVERAGE_DIR should produce an error', $errors);
    set_env_cleanup('TEST_COVERAGE_DIR', null);

    foreach ([
        '20260614T115700Z_fourth' => $now - 400,
        '20260614T115800Z_third' => $now - 300,
        '20260614T115900Z_second' => $now - 200,
        '20260614T120000Z_newest' => $now - 100,
    ] as $runName => $mtime) {
        create_run_cleanup($runsRoot, $runName, $mtime);
    }

    $allMaxRuns = run_cleanup_json(['all', '--max-runs=1', '--apply'], 0, $errors);
    assert_same_cleanup($allMaxRuns['groups']['reports']['run_dirs_delete_by_max_runs'] ?? null, 3, 'cleanup all --max-runs=1 should delete three run dirs', $errors);
    assert_same_cleanup(child_dir_names_cleanup($runsRoot), ['20260614T120000Z_newest'], 'cleanup all --max-runs=1 should keep only newest run dir', $errors);

    // Rebuild artifacts to validate operational pruning across reports, profiles, history, cleanup audits and coverage.
    create_run_cleanup($runsRoot, '20260614T120100Z_newer', $now + 100);
    create_run_cleanup($runsRoot, '20260614T120200Z_newest', $now + 200);
    create_profile_shard_cleanup($artifactRoot, 'mysql_profile', 'old', $now - 300);
    create_profile_shard_cleanup($artifactRoot, 'mysql_profile', 'new', $now + 300);
    create_profile_shard_cleanup($artifactRoot, 'influx_profile', 'old', $now - 300);
    create_profile_shard_cleanup($artifactRoot, 'influx_profile', 'new', $now + 300);

    write_file_cleanup($artifactRoot . '/reports/meta_latest.json', "{}\n", $now);
    write_file_cleanup($artifactRoot . '/reports/meta_20260614_115900.json', "{}\n", $now - 20);
    write_file_cleanup($artifactRoot . '/reports/meta_20260614_120000.json', "{}\n", $now + 20);
    write_file_cleanup($artifactRoot . '/reports/cleanup/cleanup_latest.json', "{}\n", $now);
    write_file_cleanup($artifactRoot . '/reports/cleanup/cleanup_20260614_115900.json', "{}\n", $now - 20);
    write_file_cleanup($artifactRoot . '/reports/cleanup/cleanup_20260614_120000.json', "{}\n", $now + 20);
    write_file_cleanup($artifactRoot . '/history/back_php_20260614T115900Z.json', "{}\n", $now - 20);
    write_file_cleanup($artifactRoot . '/history/back_php_20260614T120000Z.json', "{}\n", $now + 20);
    write_file_cleanup($artifactRoot . '/coverage/back_php/coverage-3.json', '{"ok":true}' . "\n");

    mkdir_p_cleanup($artifactRoot . '/locks/stale_lock');
    @touch($artifactRoot . '/locks/stale_lock', $now - 7200);
    mkdir_p_cleanup($artifactRoot . '/locks/active_lock');
    @touch($artifactRoot . '/locks/active_lock', $now);

    write_file_cleanup($artifactRoot . '/database/db.sqlite', 'not a cleanup target');
    write_file_cleanup($artifactRoot . '/docker/volumes/mysql/data', 'not a cleanup target');
    write_file_cleanup($repoRoot . '/test/seeds/seed.sql', 'not a cleanup target');
    write_file_cleanup($repoRoot . '/test/back/FooTest.php', '<?php // source test' . "\n");
    write_file_cleanup($repoRoot . '/.env.test', 'APP_ENV=test' . "\n");

    $pruned = run_cleanup_json(['all', '--prune-to-latest', '--apply'], 0, $errors);
    assert_same_cleanup($pruned['options']['prune_to_latest'] ?? null, true, '--prune-to-latest option should be persisted in audit payload', $errors);
    assert_same_cleanup(child_dir_names_cleanup($runsRoot), ['20260614T120200Z_newest'], '--prune-to-latest should keep one report run dir', $errors);
    assert_same_cleanup(child_dir_names_cleanup($artifactRoot . '/mysql_profile/shards'), ['new'], '--prune-to-latest should keep one mysql profile shard', $errors);
    assert_same_cleanup(child_dir_names_cleanup($artifactRoot . '/influx_profile/shards'), ['new'], '--prune-to-latest should keep one influx profile shard', $errors);
    assert_same_cleanup(file_names_cleanup($artifactRoot . '/reports', '/^meta_\d/'), ['meta_20260614_120000.json'], '--prune-to-latest should keep newest timestamped report JSON by prefix', $errors);
    assert_same_cleanup(file_names_cleanup($artifactRoot . '/history', '/^back_php_/'), ['back_php_20260614T120000Z.json'], '--prune-to-latest should keep newest timestamped history JSON by prefix', $errors);
    assert_false_cleanup(is_dir($artifactRoot . '/coverage'), '--prune-to-latest should remove regenerable coverage root', $errors);
    assert_false_cleanup(is_dir($artifactRoot . '/locks/stale_lock'), '--prune-to-latest should delete stale locks', $errors);
    assert_true_cleanup(is_dir($artifactRoot . '/locks/active_lock'), '--prune-to-latest must preserve active locks', $errors);
    assert_true_cleanup(is_file($artifactRoot . '/database/db.sqlite'), 'cleanup must not delete database files', $errors);
    assert_true_cleanup(is_file($artifactRoot . '/docker/volumes/mysql/data'), 'cleanup must not delete docker volume files', $errors);
    assert_true_cleanup(is_file($repoRoot . '/test/seeds/seed.sql'), 'cleanup must not delete seeds', $errors);
    assert_true_cleanup(is_file($repoRoot . '/test/back/FooTest.php'), 'cleanup must not delete test source files', $errors);
    assert_true_cleanup(is_file($repoRoot . '/.env.test'), 'cleanup must not delete env files', $errors);
    assert_true_cleanup(is_dir($artifactRoot), 'cleanup must not delete .testkit root', $errors);
    assert_true_cleanup(is_file($artifactRoot . '/reports/cleanup/cleanup_latest.json'), 'cleanup_latest.json should still be written', $errors);
    assert_true_cleanup(count(file_names_cleanup($artifactRoot . '/reports/cleanup', '/^cleanup_\d{8}_\d{6}\.json$/')) <= 1, '--prune-to-latest should leave at most one timestamped cleanup audit after apply', $errors);
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
