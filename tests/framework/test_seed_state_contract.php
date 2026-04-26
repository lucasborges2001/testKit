<?php
declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);
$projectRoot = sys_get_temp_dir() . '/tk_seed_state_contract_' . bin2hex(random_bytes(4));
@mkdir($projectRoot, 0777, true);

putenv('TESTKIT_ROOT=' . $repoRoot);
putenv('TESTKIT_PROJECT_ROOT=' . $projectRoot);
putenv('TK_REPO_ROOT=' . $projectRoot);
putenv('TESTKIT_ARTIFACTS_ROOT=' . $projectRoot . '/.testkit');

foreach ([
    'DB_NAME', 'TEST_MYSQL_DB', 'MYSQL_DATABASE', 'DB_HOST', 'TEST_MYSQL_HOST', 'MYSQL_HOST',
    'DB_USER', 'TEST_MYSQL_USER', 'MYSQL_USER', 'DB_PASS', 'TEST_MYSQL_PASSWORD', 'MYSQL_PASSWORD',
    'PG_DB', 'TEST_PG_DB', 'TEST_DB_DSN', 'DB_DRIVER', 'TEST_DB_DRIVER', 'TEST_BASELINE_MANIFEST_PATH',
] as $key) {
    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);
}

require_once $repoRoot . '/core/php/bootstrap.php';

use Testkit\Core\Common\Paths;
use Testkit\Core\Discovery\TestSeedMetadata;
use Testkit\Core\Reporting\CanonicalReport;
use Testkit\Core\Reporting\DefinitionOfDoneValidator;
use Testkit\Core\Reporting\Inspector;
use Testkit\Core\Seeding\SuiteSeedState;

$errors = [];

function assert_true(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

function rrmdir(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($child) && !is_link($child)) {
            rrmdir($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}

function write_json(string $path, array $payload): void
{
    @mkdir(dirname($path), 0777, true);
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || $json === '') {
        throw new RuntimeException('could not encode json fixture');
    }
    file_put_contents($path, $json . PHP_EOL);
}

function warning_has_contract_keys(array $warning): bool
{
    foreach (['code', 'severity', 'classification', 'blocking', 'summary'] as $key) {
        if (!array_key_exists($key, $warning)) {
            return false;
        }
    }
    return true;
}

function base_suite_report(array $overrides = []): array
{
    return array_replace_recursive([
        'suite_id' => 'back_php',
        'run_kind' => 'suite',
        'suite_status' => 'passed',
        'outcome_status' => 'passed',
        'summary' => ['total' => 1, 'passed' => 1, 'failed' => 0, 'skipped' => 0, 'duration_ms' => 1],
        'tests_total' => 1,
        'pass' => 1,
        'fail' => 0,
        'skip' => 0,
        'selected_test_count' => 1,
        'selected_test_files' => ['test/back/demo/unit/seed.test.php'],
        'evidence_valid' => true,
        'warnings' => [],
    ], $overrides);
}

function seed_check_status(string $runId): ?string
{
    $dod = DefinitionOfDoneValidator::evaluate($runId);
    foreach (($dod['checks'] ?? []) as $check) {
        if (is_array($check) && str_contains((string)($check['label'] ?? ''), 'seed mode real')) {
            return (string)($check['status'] ?? '');
        }
    }
    return null;
}

function write_meta_fixture(string $runRoot): void
{
    write_json($runRoot . '/meta_latest.json', [
        'run_kind' => 'meta',
        'target' => 'all',
        'summary' => ['total' => 1, 'passed' => 1, 'failed' => 0, 'skipped' => 0, 'duration_ms' => 1],
        'canonical_report' => [
            'report_version' => 1,
            'report_kind' => 'meta',
            'final_status' => 'PASS',
            'selection' => ['target' => 'all'],
            'summary' => ['total' => 1, 'passed' => 1, 'failed' => 0, 'skipped' => 0, 'duration_ms' => 1],
            'diagnostics' => [],
            'phase_timeline' => [],
            'evidence' => ['valid' => true, 'first_failure' => null],
            'warnings' => [],
        ],
    ]);
}

try {
    $plainReport = base_suite_report(['suite_id' => 'unit_only']);
    $safe = SuiteSeedState::attachToReport($plainReport, $projectRoot);
    assert_true(($safe['suite_id'] ?? '') === 'unit_only', 'attachToReport should preserve original report fields without DB env', $errors);
    assert_true((int)($safe['fail'] ?? 0) === 0, 'attachToReport must not create a false failure when seed is not applicable', $errors);
    assert_true(is_array($safe['seed_state'] ?? null), 'attachToReport should attach a consistent unavailable seed_state when seed is not applicable', $errors);
    assert_true(($safe['seed_state']['available'] ?? null) === false, 'seed_state.available should be false without DB/env/seeds', $errors);
    assert_true(($safe['seed_state']['reason'] ?? '') === 'not_applicable', 'unavailable seed_state should explain not_applicable', $errors);

    $canonicalUnavailable = CanonicalReport::enrich($safe);
    assert_true(($canonicalUnavailable['canonical_report']['seed_state']['available'] ?? null) === false, 'CanonicalReport should preserve available=false seed_state', $errors);

    $canonicalNull = CanonicalReport::enrich(base_suite_report(['seed_state' => null]));
    assert_true(array_key_exists('seed_state', $canonicalNull['canonical_report']), 'CanonicalReport should include seed_state key even when null', $errors);
    assert_true($canonicalNull['canonical_report']['seed_state'] === null, 'CanonicalReport should accept seed_state=null', $errors);

    $seedState = [
        'available' => true,
        'contract_version' => 1,
        'source' => 'baseline_manifest',
        'driver' => 'mysql',
        'db_name' => 'app_test',
        'baseline_mode' => 'layered',
        'manifest_path' => '.testkit/baselines/mysql/app_test.manifest.json',
        'snapshot_file' => '',
        'migration_state' => [
            'source' => 'baseline_manifest',
            'available' => ['001_base', '002_extra'],
            'applied' => ['001_base'],
            'pending' => ['002_extra'],
            'target' => ['002_extra'],
        ],
        'applied_migrations' => ['001_base'],
        'pending_migrations' => ['002_extra'],
        'warnings' => [[
            'code' => 'LEGACY_TEST_SEED_MIGRATIONS_FALLBACK',
            'severity' => 'warn',
            'classification' => 'configuration',
            'blocking' => false,
            'summary' => 'legacy fallback covered by test',
        ]],
    ];

    $report = base_suite_report(['seed_state' => $seedState]);
    $attached = SuiteSeedState::attachToReport($report, $projectRoot);
    assert_true(is_array($attached['seed_state'] ?? null), 'SuiteSeedState should attach canonical seed_state', $errors);
    assert_true(($attached['seed_state']['available'] ?? null) === true, 'seed_state.available should be true', $errors);
    assert_true(($attached['seed_state']['driver'] ?? '') === 'mysql', 'seed_state.driver should be preserved', $errors);
    assert_true(($attached['seed_state']['db_name'] ?? '') === 'app_test', 'seed_state.db_name should be preserved', $errors);
    assert_true(($attached['seed_state']['baseline_mode'] ?? '') === 'layered', 'seed_state.baseline_mode should be preserved', $errors);

    $canonical = CanonicalReport::enrich($attached);
    $canonicalSeed = $canonical['canonical_report']['seed_state'] ?? null;
    assert_true(is_array($canonicalSeed), 'canonical_report.seed_state should be present', $errors);
    assert_true(($canonicalSeed['migration_state']['applied'] ?? []) === ['001_base'], 'canonical migration_state.applied should be stable', $errors);
    assert_true(($canonicalSeed['pending_migrations'] ?? []) === ['002_extra'], 'canonical pending_migrations should be stable', $errors);
    assert_true(warning_has_contract_keys(($canonicalSeed['warnings'][0] ?? [])), 'seed_state warnings should be structured', $errors);
    assert_true(warning_has_contract_keys(($canonical['canonical_report']['warnings'][0] ?? [])), 'canonical warnings should include seed_state warnings', $errors);

    $fixture = $projectRoot . '/legacy_seed.test.php';
    file_put_contents($fixture, "<?php\n// SEEDS: TEST_SEED_MIGRATIONS=010_optional\n");
    putenv('TEST_SEED_MIGRATIONS');
    unset($_ENV['TEST_SEED_MIGRATIONS'], $_SERVER['TEST_SEED_MIGRATIONS']);
    putenv(TestSeedMetadata::LEGACY_ENABLE_ENV);

    $warnings = TestSeedMetadata::applySeedEnvIfLegacyEnabled([
        ['file' => $fixture, 'rel' => 'test/back/demo/unit/legacy_seed.test.php'],
    ], 10);
    assert_true($warnings === [], 'legacy TestSeedMetadata should be disabled by default', $errors);
    assert_true((string)(getenv('TEST_SEED_MIGRATIONS') ?: '') === '', 'disabled legacy path must not mutate TEST_SEED_MIGRATIONS', $errors);

    putenv(TestSeedMetadata::LEGACY_ENABLE_ENV . '=1');
    $warnings = TestSeedMetadata::applySeedEnvIfLegacyEnabled([
        ['file' => $fixture, 'rel' => 'test/back/demo/unit/legacy_seed.test.php'],
    ], 10);
    assert_true((string)(getenv('TEST_SEED_MIGRATIONS') ?: '') === '010_optional', 'enabled legacy path should preserve compatibility', $errors);
    assert_true(isset($warnings[0]) && warning_has_contract_keys($warnings[0]), 'legacy path should emit structured deprecation warning', $errors);

    $runId = 'p021_seed_contract_present';
    $runRoot = Paths::reportRunRoot($runId);
    @mkdir($runRoot, 0777, true);
    $suiteReport = $canonical;
    $suiteReport['report_root'] = $runRoot;
    $suiteReport['report_scope_rel'] = Paths::relativeToRepo($runRoot);
    write_json($runRoot . '/back_php_latest.json', $suiteReport);
    write_meta_fixture($runRoot);

    ob_start();
    $exit = Inspector::runCli(['inspect.php', 'seed-state', '--run=' . $runId, '--suite=back_php', '--json']);
    $json = (string)ob_get_clean();
    $payload = json_decode($json, true);
    assert_true($exit === 0, 'inspect seed-state should exit 0 for canonical report fixture', $errors);
    assert_true(is_array($payload), 'inspect seed-state should emit JSON', $errors);
    assert_true(($payload['migration_contract']['applied_migrations'] ?? []) === ['001_base'], 'inspect seed-state should read applied migrations from canonical seed_state', $errors);
    assert_true(($payload['migration_contract']['pending_migrations'] ?? []) === ['002_extra'], 'inspect seed-state should read pending migrations from canonical seed_state', $errors);
    assert_true(seed_check_status($runId) === 'pass', 'DoD should pass when canonical seed_state is present', $errors);

    $notApplicableRunId = 'p021_seed_not_applicable';
    $notApplicableRoot = Paths::reportRunRoot($notApplicableRunId);
    @mkdir($notApplicableRoot, 0777, true);
    $notApplicable = $canonicalUnavailable;
    $notApplicable['report_root'] = $notApplicableRoot;
    $notApplicable['report_scope_rel'] = Paths::relativeToRepo($notApplicableRoot);
    write_json($notApplicableRoot . '/unit_only_latest.json', $notApplicable);
    write_meta_fixture($notApplicableRoot);
    assert_true(seed_check_status($notApplicableRunId) === 'pass', 'DoD should accept unavailable seed_state when seed does not apply', $errors);

    $brokenRunId = 'p021_seed_broken_wiring';
    $brokenRoot = Paths::reportRunRoot($brokenRunId);
    @mkdir($brokenRoot, 0777, true);
    $broken = base_suite_report([
        'seed_state_required' => true,
        'manifest_path' => '.testkit/baselines/mysql/app_test.manifest.json',
        'canonical_report' => [
            'report_version' => 1,
            'report_kind' => 'suite',
            'final_status' => 'PASS',
            'selection' => ['suite_id' => 'back_php'],
            'summary' => ['total' => 1, 'passed' => 1, 'failed' => 0, 'skipped' => 0, 'duration_ms' => 1],
            'diagnostics' => [],
            'phase_timeline' => [],
            'evidence' => ['valid' => true, 'first_failure' => null],
            'warnings' => [],
        ],
    ]);
    write_json($brokenRoot . '/back_php_latest.json', $broken);
    write_meta_fixture($brokenRoot);
    assert_true(seed_check_status($brokenRunId) === 'fail', 'DoD should fail when seed_state is required but canonical_report.seed_state is missing', $errors);
} finally {
    putenv('TEST_SEED_MIGRATIONS');
    putenv(TestSeedMetadata::LEGACY_ENABLE_ENV);
    rrmdir($projectRoot);
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Seed state contract PASS\n";
