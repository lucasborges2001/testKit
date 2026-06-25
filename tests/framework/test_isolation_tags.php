<?php
declare(strict_types=1);

$testkitRoot = realpath(__DIR__ . '/../..');
if (!is_string($testkitRoot)) {
    fwrite(STDERR, "No se pudo resolver TESTKIT_ROOT\n");
    exit(1);
}
putenv('TESTKIT_ROOT=' . $testkitRoot);
putenv('TK_REPO_ROOT=' . $testkitRoot);
putenv('TESTKIT_PROJECT_ROOT=' . $testkitRoot);

require_once $testkitRoot . '/core/php/bootstrap.php';

use Testkit\Core\Discovery\TestTagger;
use Testkit\Core\Execution\ParallelGuard;

function tk_tags_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

function tk_has_error_code(array $policy, string $code): bool
{
    foreach ((array)($policy['errors'] ?? []) as $error) {
        if (is_array($error) && (string)($error['code'] ?? '') === $code) {
            return true;
        }
    }
    return false;
}

$tmp = sys_get_temp_dir() . '/testkit_tags_' . bin2hex(random_bytes(4));
@mkdir($tmp . '/test/front/example/unit', 0777, true);
$file = $tmp . '/test/front/example/unit/example_memory-isolated_db-isolated_serial_fragile.test.php';
file_put_contents($file, "<?php\n// tags: memory-isolated db-isolated serial fragile\n");
$tags = TestTagger::tagsFor($file, 10, true, '');
foreach (['memory-isolated', 'db-isolated', 'serial', 'fragile'] as $tag) {
    tk_tags_assert(in_array($tag, $tags, true), "TestTagger debe detectar tag {$tag}.");
}

putenv('TEST_STORE_DRIVER=mysql');
putenv('DB_NAME=testkit_contract_db');
putenv('TEST_DB_STRATEGY=shared');
$config = [
    'suite_id' => 'tag_contract',
    'language' => 'php',
    'jobs' => 2,
    'runner_hazards' => [],
];

$serialPolicy = ParallelGuard::evaluate([
    ['rel' => 'test/front/example/unit/serial_case.test.php', 'file' => $file, 'module' => 'example', 'tags' => ['serial']],
], $config, $testkitRoot);
tk_tags_assert(($serialPolicy['has_serial_tests'] ?? false) === true, 'ParallelGuard debe marcar has_serial_tests=true.');
tk_tags_assert(tk_has_error_code($serialPolicy, 'TEST_TAG_DECLARED_SERIAL'), 'serial + TEST_JOBS>1 debe rechazar corrida.');

$dbPolicy = ParallelGuard::evaluate([
    ['rel' => 'test/front/example/unit/db_case.test.php', 'file' => $file, 'module' => 'example', 'tags' => ['db-isolated']],
], $config, $testkitRoot);
tk_tags_assert(($dbPolicy['has_db_sensitive_tests'] ?? false) === true, 'db-isolated debe contar como DB-sensitive.');
tk_tags_assert(tk_has_error_code($dbPolicy, 'UNSAFE_PARALLEL_DB_CONFIGURATION'), 'db-isolated + shared + TEST_JOBS>1 debe rechazar por aislamiento DB.');

$memoryPolicy = ParallelGuard::evaluate([
    ['rel' => 'test/front/example/unit/memory_case.test.php', 'file' => $file, 'module' => 'example', 'tags' => ['memory-isolated']],
], array_merge($config, ['jobs' => 1]), $testkitRoot);
tk_tags_assert(tk_has_error_code($memoryPolicy, 'TEST_TAG_DECLARED_SERIAL') === false, 'memory-isolated no debe romper PHP por sí solo.');
tk_tags_assert(tk_has_error_code($memoryPolicy, 'UNSAFE_PARALLEL_DB_CONFIGURATION') === false, 'memory-isolated no debe contarse como DB-sensitive por sí solo.');

putenv('TEST_DB_STRATEGY');
putenv('DB_NAME');
putenv('TEST_STORE_DRIVER');

echo "[SUCCESS] tags de aislamiento validados\n";
exit(0);
