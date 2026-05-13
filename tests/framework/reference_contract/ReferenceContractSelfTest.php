<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../core/php/common/Env.php';
require_once __DIR__ . '/../../../core/php/common/Paths.php';
require_once __DIR__ . '/../../../core/php/references/ReferenceConfig.php';
require_once __DIR__ . '/../../../core/php/references/ReferenceRootResolver.php';
require_once __DIR__ . '/../../../core/php/references/PhpIncludeExtractor.php';
require_once __DIR__ . '/../../../core/php/references/PhpIncludeResolver.php';
require_once __DIR__ . '/../../../core/php/references/ReferenceContractResult.php';
require_once __DIR__ . '/../../../core/php/references/PhpIncludeScanner.php';

use Testkit\Core\References\PhpIncludeScanner;
use Testkit\Core\References\ReferenceConfig;
use Testkit\Core\References\ReferenceRootResolver;

/** @param mixed $actual */
function ref_assert($actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

/** @param mixed $actual */
function ref_assert_true($actual, string $message): void
{
    if (!$actual) {
        fwrite(STDERR, "FAIL: {$message}\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function ref_env_reset(string $repo): void
{
    foreach ([
        'TESTKIT_PROJECT_ROOT',
        'TESTKIT_REFERENCE_ROOT',
        'TESTKIT_REFERENCE_SCOPE',
        'TESTKIT_REFERENCE_DYNAMIC_SEVERITY',
        'TESTKIT_REFERENCE_MAX_BYTES_PER_FILE',
        'TESTKIT_REFERENCE_MAX_FILES',
        'TESTKIT_REFERENCE_MAX_VIOLATIONS',
        'TESTKIT_REFERENCE_TIMEOUT_SEC',
        'TESTKIT_REFERENCE_IGNORE_DIRS',
        'TK_BACK_DIR',
        'TK_FRONT_DIR',
        'TK_PUBLIC_DIR',
    ] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    putenv('TESTKIT_PROJECT_ROOT=' . $repo);
    $_ENV['TESTKIT_PROJECT_ROOT'] = $repo;
    $_SERVER['TESTKIT_PROJECT_ROOT'] = $repo;
}

function ref_putenv(string $key, string $value): void
{
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

function ref_mkdir(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

function ref_write(string $path, string $content): void
{
    ref_mkdir(dirname($path));
    file_put_contents($path, $content);
}

function ref_tmp_repo(): string
{
    $base = sys_get_temp_dir() . '/reference_contract_selftest_' . bin2hex(random_bytes(4));
    ref_mkdir($base . '/back');
    ref_mkdir($base . '/front');
    ref_mkdir($base . '/public');
    return realpath($base) ?: $base;
}

function ref_scan(string $repo, string $root, string $severity = 'warn'): \Testkit\Core\References\ReferenceContractResult
{
    ref_env_reset($repo);
    ref_putenv('TESTKIT_REFERENCE_ROOT', $root);
    ref_putenv('TESTKIT_REFERENCE_DYNAMIC_SEVERITY', $severity);
    $config = ReferenceConfig::fromEnv();
    $resolved = ReferenceRootResolver::resolve($repo, $config);
    $scanner = new PhpIncludeScanner($config, $repo, (string)$resolved['absolute_root'], (string)$resolved['relative_root']);
    return $scanner->scan();
}

$repo = ref_tmp_repo();

// Root resolution.
ref_env_reset($repo);
ref_putenv('TESTKIT_REFERENCE_ROOT', 'back');
$resolved = ReferenceRootResolver::resolve($repo, ReferenceConfig::fromEnv());
ref_assert($resolved['relative_root'], 'back', 'explicit TESTKIT_REFERENCE_ROOT resolves under repo');

ref_env_reset($repo);
ref_putenv('TK_BACK_DIR', 'back');
$resolved = ReferenceRootResolver::resolve($repo, ReferenceConfig::fromEnv());
ref_assert($resolved['source'], 'TK_BACK_DIR', 'back scope uses TK_BACK_DIR');

ref_env_reset($repo);
ref_putenv('TESTKIT_REFERENCE_SCOPE', 'front');
ref_putenv('TK_FRONT_DIR', 'front');
$resolved = ReferenceRootResolver::resolve($repo, ReferenceConfig::fromEnv());
ref_assert($resolved['source'], 'TK_FRONT_DIR', 'front scope uses TK_FRONT_DIR');

ref_env_reset($repo);
ref_putenv('TESTKIT_REFERENCE_SCOPE', 'front');
ref_putenv('TK_PUBLIC_DIR', 'public');
$resolved = ReferenceRootResolver::resolve($repo, ReferenceConfig::fromEnv());
ref_assert($resolved['source'], 'TK_PUBLIC_DIR', 'front scope falls back to TK_PUBLIC_DIR');

ref_env_reset($repo);
try {
    ReferenceRootResolver::resolve($repo, ReferenceConfig::fromEnv());
    fwrite(STDERR, "FAIL: missing root must fail\n");
    exit(1);
} catch (Throwable $e) {
    ref_assert(ReferenceRootResolver::causeCodeFor($e), 'reference_root_missing', 'missing root cause');
}

ref_env_reset($repo);
ref_putenv('TESTKIT_REFERENCE_ROOT', '../outside');
try {
    ReferenceRootResolver::resolve($repo, ReferenceConfig::fromEnv());
    fwrite(STDERR, "FAIL: relative root outside repo must fail\n");
    exit(1);
} catch (Throwable $e) {
    ref_assert(ReferenceRootResolver::causeCodeFor($e), 'reference_root_invalid', 'outside root cause');
}

// PHP include extraction and resolution.
ref_write($repo . '/back/ok.php', "<?php\n");
ref_write($repo . '/sibling.php', "<?php\n");
ref_write($repo . '/back/main.php', <<<'PHP_SRC'
<?php
require_once __DIR__ . '/ok.php';
require_once __DIR__ . '/missing.php';
include '../sibling.php';
// require_once __DIR__ . '/commented.php';
/* include __DIR__ . '/block_comment.php'; */
$string = "require_once __DIR__ . '/fake.php';";
require_once $path;
PHP_SRC);

$result = ref_scan($repo, 'back');
ref_assert($result->referencesFound, 4, 'comments and strings are ignored by token_get_all');
ref_assert($result->brokenReferences, 1, 'missing literal include fails');
ref_assert($result->dynamicReferences, 1, 'dynamic include is counted');
ref_assert(count($result->warnings), 1, 'dynamic include warns by default');
ref_assert($result->suiteStatus(), 'failed', 'missing include fails suite');

$result = ref_scan($repo, 'back', 'ignore');
ref_assert($result->dynamicReferences, 1, 'dynamic include is still counted when ignored');
ref_assert(count($result->warnings), 0, 'dynamic ignore does not emit warning');

$result = ref_scan($repo, 'back', 'error');
ref_assert_true(count(array_filter($result->failures, static fn(array $f): bool => ($f['cause_code'] ?? '') === 'dynamic_php_include')) === 1, 'dynamic error emits failure');

// Limits.
$limitRepo = ref_tmp_repo();
ref_write($limitRepo . '/back/big.php', "<?php\n" . str_repeat('x', 64));
ref_env_reset($limitRepo);
ref_putenv('TESTKIT_REFERENCE_ROOT', 'back');
ref_putenv('TESTKIT_REFERENCE_MAX_BYTES_PER_FILE', '8');
$config = ReferenceConfig::fromEnv();
$resolved = ReferenceRootResolver::resolve($limitRepo, $config);
$result = (new PhpIncludeScanner($config, $limitRepo, (string)$resolved['absolute_root'], (string)$resolved['relative_root']))->scan();
ref_assert($result->skippedFiles, 1, 'oversized file is skipped');
ref_assert(count($result->warnings), 1, 'oversized file emits warning');

$limitRepo = ref_tmp_repo();
ref_write($limitRepo . '/back/a.php', "<?php\n");
ref_write($limitRepo . '/back/b.php', "<?php\n");
ref_env_reset($limitRepo);
ref_putenv('TESTKIT_REFERENCE_ROOT', 'back');
ref_putenv('TESTKIT_REFERENCE_MAX_FILES', '1');
$config = ReferenceConfig::fromEnv();
$resolved = ReferenceRootResolver::resolve($limitRepo, $config);
$result = (new PhpIncludeScanner($config, $limitRepo, (string)$resolved['absolute_root'], (string)$resolved['relative_root']))->scan();
ref_assert_true($result->truncated, 'max files truncates');
ref_assert(($result->failures[0]['cause_code'] ?? ''), 'reference_max_files_exceeded', 'max files failure cause');

$limitRepo = ref_tmp_repo();
ref_write($limitRepo . '/back/main.php', "<?php\nrequire __DIR__ . '/m1.php';\nrequire __DIR__ . '/m2.php';\n");
ref_env_reset($limitRepo);
ref_putenv('TESTKIT_REFERENCE_ROOT', 'back');
ref_putenv('TESTKIT_REFERENCE_MAX_VIOLATIONS', '1');
$config = ReferenceConfig::fromEnv();
$resolved = ReferenceRootResolver::resolve($limitRepo, $config);
$result = (new PhpIncludeScanner($config, $limitRepo, (string)$resolved['absolute_root'], (string)$resolved['relative_root']))->scan();
ref_assert_true($result->truncated, 'max violations truncates');

$limitRepo = ref_tmp_repo();
ref_write($limitRepo . '/back/main.php', "<?php\nrequire __DIR__ . '/ok.php';\n");
ref_env_reset($limitRepo);
ref_putenv('TESTKIT_REFERENCE_ROOT', 'back');
ref_putenv('TESTKIT_REFERENCE_TIMEOUT_SEC', '0');
$config = ReferenceConfig::fromEnv();
$resolved = ReferenceRootResolver::resolve($limitRepo, $config);
$result = (new PhpIncludeScanner($config, $limitRepo, (string)$resolved['absolute_root'], (string)$resolved['relative_root']))->scan();
ref_assert(($result->failures[0]['cause_code'] ?? ''), 'reference_scan_timeout', 'timeout failure cause');

echo "reference-contract self-test passed\n";
