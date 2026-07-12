<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Suites\TargetResolver;

$root = dirname(__DIR__, 2);
$errors = [];
$assert = static function (bool $ok, string $message) use (&$errors): void {
    if (!$ok) {
        $errors[] = $message;
    }
};

$assert(TargetResolver::resolve('sql-observability') === ['sql_observability'], 'target must resolve to native suite');

$config = (string)file_get_contents($root . '/scripts/sql-observability/config.php');
$runner = (string)file_get_contents($root . '/scripts/sql-observability/run.sh');
$bin = (string)file_get_contents($root . '/bin/testkit');

$assert(str_contains($config, 'testkit-sql-observability-host-v1'), 'host schema must be testkit owned');
$assert(str_contains($config, 'testkit-sql-observability-dataset-v1'), 'dataset schema must be testkit owned');
$assert(!str_contains($config, 'pruebas-sql-observability-host-v1'), 'old host schema must not be active');
$assert(!str_contains($config, "private const TARGETS"), 'host config must not own generic test target selection');
$assert(str_contains($runner, '"/workspace/project/$test_match"'), 'runner must execute the exact declared test file');
$assert(!str_contains($runner, 'runTest.php "$target"'), 'runner must not invoke back-php through runTest target');
$assert(str_contains($bin, 'testkit_dispatch_sql_observability'), 'host-side wrapper must own Docker lifecycle dispatch');

if ($errors !== []) {
    fwrite(STDERR, "SQL observability native tests failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "OK sql observability native contract\n";
