<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/bootstrap.php';

use Testkit\Core\Config\ContractRegistry;
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

/** @param array<int,string> $unknown */
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
    echo ContractRegistry::renderRunHelp();
}

[$target, $unknownArgs] = testkit_parse_run_args($argv);
if ($unknownArgs !== []) {
    testkit_fail_unknown_run_args($unknownArgs);
}

exit(MetaRunner::run((string)$target));
