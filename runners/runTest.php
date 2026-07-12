<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/bootstrap.php';

use Testkit\Core\Suites\MetaRunner;

\Testkit\Core\Common\AgentMode::applyRuntimeEnv();

/**
 * @param array<int,string> $argv
 * @return array{0:string,1:array<int,string>}
 */
function testkit_parse_run_args(array $argv): array
{
    $args = array_values(array_slice($argv, 1));
    $target = '';
    $unknown = [];

    foreach ($args as $arg) {
        $normalized = strtolower(trim((string)$arg));

        if ($normalized === 'help' || $normalized === '--help' || $normalized === '-h') {
            testkit_print_run_help();
            exit(0);
        }

        if ($normalized === '--list') {
            putenv('TEST_LIST=1');
            $_ENV['TEST_LIST'] = '1';
            $_SERVER['TEST_LIST'] = '1';
            continue;
        }

        if (str_starts_with($normalized, '--')) {
            $unknown[] = (string)$arg;
            continue;
        }

        if ($target === '') {
            $target = (string)$arg;
            continue;
        }

        $unknown[] = (string)$arg;
    }

    return [$target, $unknown];
}

/**
 * @param array<int,string> $unknown
 */
function testkit_fail_unknown_run_args(array $unknown): never
{
    fwrite(
        STDERR,
        'runTest.php: argumentos no soportados: ' . implode(' ', $unknown) . PHP_EOL
        . 'Usá php runTest.php --help para ver la superficie soportada.' . PHP_EOL
    );
    exit(2);
}

function testkit_print_run_help(): void
{
    echo "Uso:\n";
    echo "  php runTest.php [target] [--list]\n";
    echo "  php runTest.php --help\n\n";
    echo "Targets comunes:\n";
    echo "  all | back | front | back-php | back-py | front-php | front-js | php | js\n";
    echo "  smoke | perf | stress | contract | critical | slow | migration-contract\n";
    echo "  reference-contract | references | php-references | sql-observability\n\n";
    echo "Opciones soportadas:\n";
    echo "  --list     lista la selección efectiva y fuerza TEST_LIST=1 para esta corrida\n";
    echo "  --help     muestra esta ayuda\n\n";
    echo "Configuración por env (resumen):\n";
    echo "  TEST_SCOPE, TEST_CATEGORY, TEST_MATCH, TEST_REQUIRE_TESTS, TEST_JOBS,\n";
    echo "  TEST_FAIL_FAST, TEST_META_FAIL_FAST, TEST_CHILD_FAIL_FAST,\n";
    echo "  TEST_COVERAGE, TEST_COVERAGE_FORMAT, TEST_DB_STRATEGY,\n";
    echo "  TEST_BASELINE_MODE, TEST_STORE_DRIVER, TESTKIT_SKIP_STORE_BOOTSTRAP,\n";
    echo "  TESTKIT_REFERENCE_SCOPE, TESTKIT_REFERENCE_ROOT, TESTKIT_REFERENCE_TIMEOUT_SEC\n";
    echo "  Para esquema detallado: php scripts/inspect.php config-schema [--json]\n";
}

[$target, $unknownArgs] = testkit_parse_run_args($argv);
if ($unknownArgs !== []) {
    testkit_fail_unknown_run_args($unknownArgs);
}

$code = MetaRunner::run((string)$target);
exit($code);
