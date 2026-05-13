<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../core/php/common/Env.php';
require_once __DIR__ . '/../../../core/php/common/Paths.php';
require_once __DIR__ . '/../../../core/php/references/ReferenceConfigException.php';
require_once __DIR__ . '/../../../core/php/references/ReferenceConfig.php';
require_once __DIR__ . '/../../../core/php/references/ReferenceRootResolver.php';
require_once __DIR__ . '/../../../core/php/references/PhpIncludeExtractor.php';
require_once __DIR__ . '/../../../core/php/references/PhpIncludeResolver.php';
require_once __DIR__ . '/../../../core/php/references/ReferenceContractResult.php';
require_once __DIR__ . '/../../../core/php/references/ReferenceConsoleRenderer.php';
require_once __DIR__ . '/../../../core/php/references/PhpIncludeScanner.php';

use Testkit\Core\References\PhpIncludeScanner;
use Testkit\Core\References\ReferenceConfig;
use Testkit\Core\References\ReferenceConfigException;
use Testkit\Core\References\ReferenceConsoleRenderer;
use Testkit\Core\References\ReferenceContractResult;
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
        'TESTKIT_REFERENCE_IGNORE_REFS',
        'TESTKIT_REFERENCE_IGNORE_REF_REGEX',
        'TESTKIT_REFERENCE_IGNORE_FILES',
        'TESTKIT_REFERENCE_IGNORE_FILE_REGEX',
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

function ref_scan(string $repo, string $root, string $severity = 'warn'): ReferenceContractResult
{
    ref_env_reset($repo);
    ref_putenv('TESTKIT_REFERENCE_ROOT', $root);
    ref_putenv('TESTKIT_REFERENCE_DYNAMIC_SEVERITY', $severity);
    $config = ReferenceConfig::fromEnv();
    $resolved = ReferenceRootResolver::resolve($repo, $config);
    $scanner = new PhpIncludeScanner($config, $repo, (string)$resolved['absolute_root'], (string)$resolved['relative_root']);
    return $scanner->scan();
}

function ref_expect_config_error(string $repo, string $key, string $value, string $causeCode): void
{
    ref_env_reset($repo);
    ref_putenv($key, $value);
    try {
        ReferenceConfig::fromEnv();
        fwrite(STDERR, "FAIL: {$key} must fail\n");
        exit(1);
    } catch (ReferenceConfigException $e) {
        ref_assert($e->causeCode, $causeCode, "{$key} cause code");
    }
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
ref_assert($resolved['relative_root'], 'front', 'front scope resolves TK_FRONT_DIR');

ref_env_reset($repo);
ref_putenv('TESTKIT_REFERENCE_SCOPE', 'front');
ref_putenv('TK_PUBLIC_DIR', 'public');
$resolved = ReferenceRootResolver::resolve($repo, ReferenceConfig::fromEnv());
ref_assert($resolved['source'], 'TK_PUBLIC_DIR', 'front scope falls back to TK_PUBLIC_DIR');
ref_assert($resolved['relative_root'], 'public', 'front fallback resolves TK_PUBLIC_DIR');

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
    ref_assert(ReferenceRootResolver::causeCodeFor($e), 'reference_root_outside_repo', 'outside root cause');
}

ref_write($repo . '/file.php', "<?php\n");
ref_env_reset($repo);
ref_putenv('TESTKIT_REFERENCE_ROOT', 'file.php');
try {
    ReferenceRootResolver::resolve($repo, ReferenceConfig::fromEnv());
    fwrite(STDERR, "FAIL: file root must fail\n");
    exit(1);
} catch (Throwable $e) {
    ref_assert(ReferenceRootResolver::causeCodeFor($e), 'reference_root_not_directory', 'file root cause');
}

// Config validation.
ref_expect_config_error($repo, 'TESTKIT_REFERENCE_SCOPE', 'public', 'reference_invalid_scope');
ref_expect_config_error($repo, 'TESTKIT_REFERENCE_DYNAMIC_SEVERITY', 'notice', 'reference_invalid_dynamic_severity');
ref_expect_config_error($repo, 'TESTKIT_REFERENCE_IGNORE_REF_REGEX', '~unterminated', 'reference_invalid_regex');
ref_expect_config_error($repo, 'TESTKIT_REFERENCE_MAX_FILES', '0', 'reference_invalid_limit');
ref_expect_config_error($repo, 'TESTKIT_REFERENCE_TIMEOUT_SEC', '-1', 'reference_invalid_limit');
ref_expect_config_error($repo, 'TESTKIT_REFERENCE_MAX_BYTES_PER_FILE', 'nope', 'reference_invalid_limit');

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
echo "include file";
require_once $path;
PHP_SRC);

$result = ref_scan($repo, 'back');
ref_assert($result->referencesFound, 4, 'comments and strings are ignored by token_get_all');
ref_assert($result->okReferences, 2, 'ok includes are counted');
ref_assert($result->brokenReferences, 1, 'missing literal include fails');
ref_assert($result->dynamicReferences, 1, 'dynamic include is counted');
ref_assert(count($result->warnings), 1, 'dynamic include warns by default');
ref_assert($result->suiteStatus(), 'failed', 'missing include fails suite');

$result = ref_scan($repo, 'back', 'ignore');
ref_assert($result->dynamicReferences, 1, 'dynamic include is still counted when ignored');
ref_assert(count($result->warnings), 0, 'dynamic ignore does not emit warning');

$result = ref_scan($repo, 'back', 'error');
ref_assert_true(count(array_filter($result->failures, static fn(array $f): bool => ($f['cause_code'] ?? '') === 'dynamic_php_include')) === 1, 'dynamic error emits failure');

// Configurable reference ignores.
$ignoreRepo = ref_tmp_repo();
ref_write($ignoreRepo . '/back/main.php', "<?php\nrequire __DIR__ . '/missing.php';\nrequire __DIR__ . '/also_missing.php';\n");
ref_env_reset($ignoreRepo);
ref_putenv('TESTKIT_REFERENCE_ROOT', 'back');
ref_putenv('TESTKIT_REFERENCE_IGNORE_REFS', '/missing.php');
$config = ReferenceConfig::fromEnv();
$resolved = ReferenceRootResolver::resolve($ignoreRepo, $config);
$result = (new PhpIncludeScanner($config, $ignoreRepo, (string)$resolved['absolute_root'], (string)$resolved['relative_root']))->scan();
ref_assert($result->ignoredReferences, 1, 'IGNORE_REFS ignores one reference');
ref_assert($result->brokenReferences, 1, 'IGNORE_REFS does not hide the whole file');

ref_env_reset($ignoreRepo);
ref_putenv('TESTKIT_REFERENCE_ROOT', 'back');
ref_putenv('TESTKIT_REFERENCE_IGNORE_REF_REGEX', '~/also_missing\.php$~');
$config = ReferenceConfig::fromEnv();
$resolved = ReferenceRootResolver::resolve($ignoreRepo, $config);
$result = (new PhpIncludeScanner($config, $ignoreRepo, (string)$resolved['absolute_root'], (string)$resolved['relative_root']))->scan();
ref_assert($result->ignoredReferences, 1, 'IGNORE_REF_REGEX ignores one reference');
ref_assert($result->brokenReferences, 1, 'IGNORE_REF_REGEX keeps other broken reference visible');

ref_env_reset($ignoreRepo);
ref_putenv('TESTKIT_REFERENCE_ROOT', 'back');
ref_putenv('TESTKIT_REFERENCE_IGNORE_FILES', 'back/main.php');
$config = ReferenceConfig::fromEnv();
$resolved = ReferenceRootResolver::resolve($ignoreRepo, $config);
$result = (new PhpIncludeScanner($config, $ignoreRepo, (string)$resolved['absolute_root'], (string)$resolved['relative_root']))->scan();
ref_assert($result->skippedFiles, 1, 'IGNORE_FILES increments skipped_files');
ref_assert($result->referencesFound, 0, 'IGNORE_FILES skips source file');

ref_env_reset($ignoreRepo);
ref_putenv('TESTKIT_REFERENCE_ROOT', 'back');
ref_putenv('TESTKIT_REFERENCE_IGNORE_FILE_REGEX', '~back/main\.php$~');
$config = ReferenceConfig::fromEnv();
$resolved = ReferenceRootResolver::resolve($ignoreRepo, $config);
$result = (new PhpIncludeScanner($config, $ignoreRepo, (string)$resolved['absolute_root'], (string)$resolved['relative_root']))->scan();
ref_assert($result->skippedFiles, 1, 'IGNORE_FILE_REGEX increments skipped_files');

// Summary status split.
$summaryRepo = ref_tmp_repo();
ref_write($summaryRepo . '/back/ok.php', "<?php\n");
ref_write($summaryRepo . '/back/summary.php', <<<'PHP_SRC'
<?php
include_once __DIR__ . '/ok.php';
include_once __DIR__ . '/missing.php';
include_once $dynamic;
include_once __DIR__ . '/ignored.php';
PHP_SRC);
ref_env_reset($summaryRepo);
ref_putenv('TESTKIT_REFERENCE_ROOT', 'back');
ref_putenv('TESTKIT_REFERENCE_IGNORE_REFS', '/ignored.php');
$config = ReferenceConfig::fromEnv();
$resolved = ReferenceRootResolver::resolve($summaryRepo, $config);
$result = (new PhpIncludeScanner($config, $summaryRepo, (string)$resolved['absolute_root'], (string)$resolved['relative_root']))->scan();
ref_assert($result->okReferences, 1, 'summary ok count');
ref_assert($result->brokenReferences, 1, 'summary missing count');
ref_assert($result->dynamicReferences, 1, 'summary dynamic count');
ref_assert($result->ignoredReferences, 1, 'summary ignored count');

// sistemaCargador-like fixture: calibrates declared roots and real include shapes.
$scRepo = ref_tmp_repo();
ref_write($scRepo . '/web_cargadores/PHP/auth/authConfig.php', "<?php\n");
ref_write($scRepo . '/web_cargadores/PHP/utils/logs.php', "<?php\n");
ref_write($scRepo . '/web_cargadores/PHP/utils/http.php', "<?php\n");
ref_write($scRepo . '/web_cargadores/PHP/ocpp/service/runtime/eventoCriticoService.php', "<?php\n");
ref_write($scRepo . '/web_cargadores/PHP/auth/_common.php', "<?php\nrequire_once __DIR__ . '/authConfig.php';\nrequire_once __DIR__ . '/../utils/logs.php';\n");
ref_write($scRepo . '/web_cargadores/PHP/ocpp/helper.php', "<?php\nrequire_once __DIR__ . '/service/runtime/eventoCriticoService.php';\n");
ref_write($scRepo . '/web_cargadores/public/API/ocpp_public/reanudar.php', "<?php\nrequire_once __DIR__ . '/../../../PHP/utils/http.php';\n");

ref_env_reset($scRepo);
ref_putenv('TK_BACK_DIR', 'web_cargadores/PHP');
$config = ReferenceConfig::fromEnv();
$resolved = ReferenceRootResolver::resolve($scRepo, $config);
ref_assert($resolved['relative_root'], 'web_cargadores/PHP', 'sistemaCargador back root');
$result = (new PhpIncludeScanner($config, $scRepo, (string)$resolved['absolute_root'], (string)$resolved['relative_root']))->scan();
ref_assert($result->brokenReferences, 0, 'sistemaCargador back fixture has no broken includes');

ref_env_reset($scRepo);
ref_putenv('TESTKIT_REFERENCE_SCOPE', 'front');
ref_putenv('TK_PUBLIC_DIR', 'web_cargadores/public');
$config = ReferenceConfig::fromEnv();
$resolved = ReferenceRootResolver::resolve($scRepo, $config);
ref_assert($resolved['relative_root'], 'web_cargadores/public', 'sistemaCargador front fallback root');
$result = (new PhpIncludeScanner($config, $scRepo, (string)$resolved['absolute_root'], (string)$resolved['relative_root']))->scan();
ref_assert($result->brokenReferences, 0, 'sistemaCargador public fixture has no broken includes');

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

// Console smoke.
$config = new ReferenceConfig('back', 'back', 20, 3000, 1048576, 200, 'warn', ['vendor']);
$report = (new ReferenceContractResult('back', 'web_cargadores/PHP', '/tmp/web_cargadores/PHP', ReferenceContractResult::nowMs()))->toReport([
    'summary' => ['duration_ms' => 1],
]);
$out = ReferenceConsoleRenderer::render($report, $config);
ref_assert_true(str_contains($out, 'REFERENCE CONTRACT'), 'console includes title');
ref_assert_true(str_contains($out, 'Reference Summary'), 'console includes summary heading');
ref_assert_true(str_contains($out, 'root:'), 'console includes root');
ref_assert_true(str_contains($out, 'outcome:'), 'console includes outcome');

echo "reference-contract self-test passed\n";
