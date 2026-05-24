<?php
declare(strict_types=1);

/**
 * Generic declarative suite runner for host projects.
 *
 * The project owns the suite config. testkit owns the execution contract:
 * validate suite metadata, run commands from explicit working directories, and
 * return a non-zero exit code when any required command fails.
 */

/**
 * @param array<int,string> $argv
 * @return array{config:string,suite:string,list:bool}
 */
function testkit_suite_config_parse_args(array $argv): array
{
    $args = array_values(array_slice($argv, 1));
    $list = false;

    foreach ($args as $index => $arg) {
        if ($arg === '--help' || $arg === '-h' || $arg === 'help') {
            testkit_suite_config_print_help();
            exit(0);
        }

        if ($arg === '--list') {
            $list = true;
            unset($args[$index]);
        }
    }

    $args = array_values($args);
    if (count($args) < 1 || count($args) > 2) {
        testkit_suite_config_print_help(STDERR);
        exit(2);
    }

    return [
        'config' => $args[0],
        'suite' => $args[1] ?? 'all',
        'list' => $list,
    ];
}

/** @param resource|null $stream */
function testkit_suite_config_print_help($stream = null): void
{
    $stream = $stream ?? STDOUT;
    fwrite($stream, "Uso:\n");
    fwrite($stream, "  php runSuiteConfig.php <config.php> [suite]\n");
    fwrite($stream, "  php runSuiteConfig.php <config.php> --list\n\n");
    fwrite($stream, "La config debe retornar ['suites' => [...]] con key, label, working_directory, commands, required y description.\n");
}

function testkit_suite_config_absolute_path(string $path, string $baseDirectory): string
{
    if ($path === '') {
        return '';
    }

    if ($path[0] === '/') {
        return $path;
    }

    return rtrim($baseDirectory, '/') . '/' . $path;
}

/**
 * @return array<string,mixed>
 */
function testkit_suite_config_load(string $configPath, string $projectRoot): array
{
    $resolvedConfig = testkit_suite_config_absolute_path($configPath, $projectRoot);
    if (!is_file($resolvedConfig)) {
        fwrite(STDERR, "No existe config de suites: {$resolvedConfig}\n");
        exit(2);
    }

    $config = require $resolvedConfig;
    if (!is_array($config) || !isset($config['suites']) || !is_array($config['suites'])) {
        fwrite(STDERR, "Config de suites invalida: debe retornar array con clave suites.\n");
        exit(2);
    }

    return $config;
}

/**
 * @param array<string,mixed> $config
 * @return array<string,array<string,mixed>>
 */
function testkit_suite_config_index_suites(array $config): array
{
    $indexed = [];
    foreach ($config['suites'] as $suite) {
        if (!is_array($suite)) {
            fwrite(STDERR, "Suite invalida: cada suite debe ser array.\n");
            exit(2);
        }

        $key = (string)($suite['key'] ?? '');
        if ($key === '') {
            fwrite(STDERR, "Suite invalida: falta key.\n");
            exit(2);
        }

        foreach (['label', 'working_directory', 'commands', 'required', 'description'] as $field) {
            if (!array_key_exists($field, $suite)) {
                fwrite(STDERR, "Suite {$key} invalida: falta {$field}.\n");
                exit(2);
            }
        }

        if (!is_array($suite['commands'])) {
            fwrite(STDERR, "Suite {$key} invalida: commands debe ser array.\n");
            exit(2);
        }

        $indexed[$key] = $suite;
    }

    return $indexed;
}

/**
 * @param array<string,array<string,mixed>> $suites
 */
function testkit_suite_config_list(array $suites): void
{
    foreach ($suites as $key => $suite) {
        $required = ((bool)$suite['required']) ? 'required' : 'optional';
        echo "{$key}\t{$required}\t{$suite['label']}\n";
    }
}

/**
 * @param array<string,mixed> $suite
 */
function testkit_suite_config_run_suite(array $suite, string $projectRoot): int
{
    $key = (string)$suite['key'];
    $label = (string)$suite['label'];
    $workingDirectory = testkit_suite_config_absolute_path((string)$suite['working_directory'], $projectRoot);

    if (!is_dir($workingDirectory)) {
        fwrite(STDERR, "Suite {$key}: no existe working_directory {$workingDirectory}\n");
        return ((bool)$suite['required']) ? 1 : 0;
    }

    echo "==> {$label} [{$key}]\n";

    $failed = 0;
    foreach ($suite['commands'] as $command) {
        if (!is_string($command) || trim($command) === '') {
            fwrite(STDERR, "Suite {$key}: comando invalido.\n");
            $failed++;
            continue;
        }

        echo "    $ {$command}\n";
        $descriptorSpec = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];
        $env = $_ENV;
        $env['TESTKIT_PROJECT_ROOT'] = $projectRoot;

        $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory, $env);
        if (!is_resource($process)) {
            fwrite(STDERR, "Suite {$key}: no se pudo iniciar comando.\n");
            $failed++;
            continue;
        }

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            fwrite(STDERR, "Suite {$key}: comando fallo con codigo {$exitCode}: {$command}\n");
            $failed++;
            if ((bool)($suite['fail_fast'] ?? true)) {
                break;
            }
        }
    }

    if ($failed === 0) {
        echo "OK {$key}\n";
        return 0;
    }

    return ((bool)$suite['required']) ? 1 : 0;
}

$parsed = testkit_suite_config_parse_args($argv);
$projectRoot = (string)getenv('TESTKIT_PROJECT_ROOT');
if ($projectRoot === '') {
    $projectRoot = getcwd() ?: '.';
}
$projectRoot = rtrim($projectRoot, '/');

$config = testkit_suite_config_load($parsed['config'], $projectRoot);
$suites = testkit_suite_config_index_suites($config);

if ($parsed['list']) {
    testkit_suite_config_list($suites);
    exit(0);
}

$suiteKey = $parsed['suite'];
if (!isset($suites[$suiteKey])) {
    fwrite(STDERR, "Suite no definida: {$suiteKey}\n");
    testkit_suite_config_list($suites);
    exit(2);
}

$exitCode = testkit_suite_config_run_suite($suites[$suiteKey], $projectRoot);
exit($exitCode);
