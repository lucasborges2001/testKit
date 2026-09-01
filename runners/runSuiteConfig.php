<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/utils/php/ui.php';
require_once dirname(__DIR__) . '/core/php/reporting/ConsoleMode.php';

use Testkit\Core\Reporting\ConsoleMode;

/**
 * Generic declarative suite runner for host projects.
 *
 * The project owns the suite config. testkit owns the execution contract:
 * validate suite metadata, run commands from explicit working directories,
 * compose suites without shell recursion, and return a non-zero exit code when
 * any required command fails.
 *
 * Optional root config:
 *   'output' => 'live'|'failures'  (default: live)
 *   'success_stderr' => 'hide'|'show'  (default: hide)
 *
 * A suite must declare exactly one execution source:
 *   'commands' => ['...']
 *   'suites'   => ['child_a', 'child_b']
 */

/**
 * @param array<int,string> $argv
 * @return array{config:string,suite:string,list:bool,result_json:?string}
 */
function testkit_suite_config_parse_args(array $argv): array
{
    $args = array_values(array_slice($argv, 1));
    $list = false;
    $resultJson = null;
    $positional = [];

    for ($index = 0, $count = count($args); $index < $count; $index++) {
        $arg = $args[$index];
        if ($arg === '--help' || $arg === '-h' || $arg === 'help') {
            testkit_suite_config_print_help();
            exit(0);
        }

        if ($arg === '--list') {
            $list = true;
            continue;
        }

        if ($arg === '--result-json') {
            if ($resultJson !== null || !isset($args[$index + 1]) || $args[$index + 1] === '') {
                fwrite(STDERR, "--result-json requiere un unico path no vacio.\n");
                exit(2);
            }
            $resultJson = $args[++$index];
            continue;
        }

        if (str_starts_with($arg, '--')) {
            fwrite(STDERR, "Opcion no soportada: {$arg}\n");
            exit(2);
        }

        $positional[] = $arg;
    }

    if (count($positional) < 1 || count($positional) > 2 || ($list && $resultJson !== null)) {
        testkit_suite_config_print_help(STDERR);
        exit(2);
    }

    return [
        'config' => $positional[0],
        'suite' => $positional[1] ?? 'all',
        'list' => $list,
        'result_json' => $resultJson,
    ];
}

/** @param resource|null $stream */
function testkit_suite_config_print_help($stream = null): void
{
    $stream = $stream ?? STDOUT;
    fwrite($stream, "Uso:\n");
    fwrite($stream, "  php runSuiteConfig.php <config.php> [suite] [--result-json <path>]\n");
    fwrite($stream, "  php runSuiteConfig.php <config.php> --list\n\n");
    fwrite($stream, "La config debe retornar ['suites' => [...]] y puede declarar output=live|failures.\n");
    fwrite($stream, "--result-json escribe un envelope atomico caller-owned sin stdout/stderr de comandos.\n");
    fwrite($stream, "success_stderr=hide|show controla stderr de comandos exitosos en salida capturada.\n");
    fwrite($stream, "Cada suite usa commands=[...] o suites=[...] para composicion nativa.\n");
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

/** @return array<string,mixed> */
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

    $output = (string)($config['output'] ?? 'live');
    if (!in_array($output, ['live', 'failures'], true)) {
        fwrite(STDERR, "Config de suites invalida: output debe ser live o failures.\n");
        exit(2);
    }

    $successStderr = (string)($config['success_stderr'] ?? 'hide');
    if (!in_array($successStderr, ['hide', 'show'], true)) {
        fwrite(STDERR, "Config de suites invalida: success_stderr debe ser hide o show.\n");
        exit(2);
    }

    $config['output'] = $output;
    $config['success_stderr'] = $successStderr;
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

        foreach (['label', 'working_directory', 'required', 'description'] as $field) {
            if (!array_key_exists($field, $suite)) {
                fwrite(STDERR, "Suite {$key} invalida: falta {$field}.\n");
                exit(2);
            }
        }

        $hasCommands = array_key_exists('commands', $suite);
        $hasSuites = array_key_exists('suites', $suite);
        if ($hasCommands === $hasSuites) {
            fwrite(STDERR, "Suite {$key} invalida: debe declarar exactamente uno de commands o suites.\n");
            exit(2);
        }

        if ($hasCommands && !is_array($suite['commands'])) {
            fwrite(STDERR, "Suite {$key} invalida: commands debe ser array.\n");
            exit(2);
        }

        if ($hasSuites && !is_array($suite['suites'])) {
            fwrite(STDERR, "Suite {$key} invalida: suites debe ser array.\n");
            exit(2);
        }

        if (isset($suite['output']) && !in_array((string)$suite['output'], ['live', 'failures'], true)) {
            fwrite(STDERR, "Suite {$key} invalida: output debe ser live o failures.\n");
            exit(2);
        }

        if (isset($suite['success_stderr']) && !in_array((string)$suite['success_stderr'], ['hide', 'show'], true)) {
            fwrite(STDERR, "Suite {$key} invalida: success_stderr debe ser hide o show.\n");
            exit(2);
        }

        $indexed[$key] = $suite;
    }

    foreach ($indexed as $key => $suite) {
        if (!isset($suite['suites'])) {
            continue;
        }
        foreach ($suite['suites'] as $childKey) {
            if (!is_string($childKey) || $childKey === '' || !isset($indexed[$childKey])) {
                fwrite(STDERR, "Suite {$key} invalida: referencia suite inexistente o invalida.\n");
                exit(2);
            }
        }
    }

    return $indexed;
}

/** @param array<string,array<string,mixed>> $suites */
function testkit_suite_config_list(array $suites): void
{
    foreach ($suites as $key => $suite) {
        $required = ((bool)$suite['required']) ? 'required' : 'optional';
        $kind = isset($suite['commands']) ? 'commands' : 'composite';
        echo "{$key}\t{$required}\t{$kind}\t{$suite['label']}\n";
    }
}

/**
 * @return array{suites:int,passed:int,failed:int,commands:int,passed_commands:int,failed_commands:int,required_failures:int,time_ms:int}
 */
function testkit_suite_config_empty_result(): array
{
    return [
        'suites' => 0,
        'passed' => 0,
        'failed' => 0,
        'commands' => 0,
        'passed_commands' => 0,
        'failed_commands' => 0,
        'required_failures' => 0,
        'time_ms' => 0,
    ];
}

function testkit_suite_config_merge_result(array &$into, array $from): void
{
    foreach (array_keys($into) as $field) {
        $into[$field] += $from[$field];
    }
}

/** @return array{exit_code:int,stdout:string,stderr:string,time_ms:int} */
function testkit_suite_config_run_command(
    string $command,
    string $workingDirectory,
    string $projectRoot,
    string $outputMode,
    string $successStderr
): array
{
    $processEnv = getenv();
    $env = array_merge($_ENV, is_array($processEnv) ? $processEnv : []);
    $env['TESTKIT_PROJECT_ROOT'] = $projectRoot;
    $startedAt = microtime(true);

    if ($outputMode === 'live') {
        $descriptorSpec = [0 => STDIN, 1 => STDOUT, 2 => STDERR];
        $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory, $env);
        if (!is_resource($process)) {
            return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'no se pudo iniciar comando', 'time_ms' => 0];
        }
        $exitCode = proc_close($process);
        return [
            'exit_code' => $exitCode,
            'stdout' => '',
            'stderr' => '',
            'time_ms' => (int)round((microtime(true) - $startedAt) * 1000),
        ];
    }

    $stdoutPath = tempnam(sys_get_temp_dir(), 'testkit-suite-out-');
    $stderrPath = tempnam(sys_get_temp_dir(), 'testkit-suite-err-');
    if ($stdoutPath === false || $stderrPath === false) {
        if (is_string($stdoutPath)) @unlink($stdoutPath);
        if (is_string($stderrPath)) @unlink($stderrPath);
        return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'no se pudo crear captura temporal', 'time_ms' => 0];
    }

    $descriptorSpec = [0 => STDIN, 1 => ['file', $stdoutPath, 'w'], 2 => ['file', $stderrPath, 'w']];
    $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory, $env);
    if (!is_resource($process)) {
        @unlink($stdoutPath);
        @unlink($stderrPath);
        return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'no se pudo iniciar comando', 'time_ms' => 0];
    }

    $exitCode = proc_close($process);

    $stdout = '';
    $stderr = '';

    if ($exitCode !== 0) {
        // Un fallo conserva el diagnóstico completo histórico.
        $stdout = (string)@file_get_contents($stdoutPath);
        $stderr = (string)@file_get_contents($stderrPath);
    } elseif ($successStderr === 'show') {
        // En un PASS nunca necesitamos cargar stdout.
        // stderr sólo se materializa cuando el contrato pide mostrar warnings.
        $stderr = (string)@file_get_contents($stderrPath);
    }

    @unlink($stdoutPath);
    @unlink($stderrPath);

    return [
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'time_ms' => (int)round((microtime(true) - $startedAt) * 1000),
    ];
}

function testkit_suite_config_print_failure_output(string $stdout, string $stderr): void
{
    $stdout = rtrim($stdout, "\r\n");
    $stderr = rtrim($stderr, "\r\n");
    if ($stdout !== '') fwrite(STDERR, $stdout . PHP_EOL);
    if ($stderr !== '') fwrite(STDERR, $stderr . PHP_EOL);
}

function testkit_suite_config_print_success_stderr(string $stderr): void
{
    $stderr = rtrim($stderr, "\r\n");
    if ($stderr !== '') fwrite(STDERR, $stderr . PHP_EOL);
}

function testkit_suite_config_status(string $status): string
{
    return pvt_ui_bold(pvt_ui_status_label($status));
}

/** @param array<int,array<string,mixed>> $commandTrace */
function testkit_suite_config_run_command_suite(array $suite, string $projectRoot, string $defaultOutputMode, string $defaultSuccessStderr, array &$commandTrace): array
{
    $key = (string)$suite['key'];
    $label = (string)$suite['label'];
    $workingDirectory = testkit_suite_config_absolute_path((string)$suite['working_directory'], $projectRoot);
    $outputMode = (string)($suite['output'] ?? $defaultOutputMode);
    $successStderr = (string)($suite['success_stderr'] ?? $defaultSuccessStderr);
    $result = testkit_suite_config_empty_result();
    $result['suites'] = 1;

    if (!is_dir($workingDirectory)) {
        fwrite(STDERR, testkit_suite_config_status('FAIL') . " {$key}: no existe working_directory {$workingDirectory}\n");
        $result['failed'] = 1;
        $result['required_failures'] = ((bool)$suite['required']) ? 1 : 0;
        return $result;
    }

    if ($outputMode === 'live') {
        echo pvt_ui_bold(pvt_ui_cyan('==>')) . " {$label} [" . pvt_ui_gray($key) . "]\n";
    }

    $startedAt = microtime(true);
    foreach ($suite['commands'] as $commandIndex => $command) {
        if (!is_string($command) || trim($command) === '') {
            fwrite(STDERR, testkit_suite_config_status('FAIL') . " {$key}: comando invalido.\n");
            $result['commands']++;
            $result['failed_commands']++;
            $commandTrace[] = [
                'suite' => $key,
                'command_index' => $commandIndex + 1,
                'command' => is_string($command) ? $command : null,
                'status' => 'FAIL',
                'exit_code' => null,
                'duration_ms' => 0,
            ];
            if ((bool)($suite['fail_fast'] ?? true)) break;
            continue;
        }

        if ($outputMode === 'live') echo '    ' . pvt_ui_dim('$') . " {$command}\n";

        $commandResult = testkit_suite_config_run_command(
            $command,
            $workingDirectory,
            $projectRoot,
            $outputMode,
            $successStderr
        );
        $result['commands']++;
        $commandTrace[] = [
            'suite' => $key,
            'command_index' => $commandIndex + 1,
            'command' => $command,
            'status' => $commandResult['exit_code'] === 0 ? 'PASS' : 'FAIL',
            'exit_code' => $commandResult['exit_code'],
            'duration_ms' => $commandResult['time_ms'],
        ];
        if ($commandResult['exit_code'] === 0) {
            $result['passed_commands']++;
            if ($outputMode === 'failures' && $successStderr === 'show') {
                testkit_suite_config_print_success_stderr($commandResult['stderr']);
            }
            continue;
        }

        $result['failed_commands']++;
        fwrite(STDERR, testkit_suite_config_status('FAIL') . " {$key}\n");
        fwrite(STDERR, '  ' . pvt_ui_gray('command:') . " {$command}\n");
        fwrite(STDERR, '  ' . pvt_ui_gray('exit_code:') . ' ' . pvt_ui_red((string)$commandResult['exit_code']) . "\n");
        testkit_suite_config_print_failure_output($commandResult['stdout'], $commandResult['stderr']);
        if ((bool)($suite['fail_fast'] ?? true)) break;
    }

    $result['time_ms'] = (int)round((microtime(true) - $startedAt) * 1000);
    if ($result['failed_commands'] === 0) {
        $result['passed'] = 1;
        if ($outputMode === 'live') echo testkit_suite_config_status('OK') . " {$key}\n";
        return $result;
    }

    $result['failed'] = 1;
    $result['required_failures'] = ((bool)$suite['required']) ? 1 : 0;
    return $result;
}

/** @param array<int,array<string,mixed>> $commandTrace */
function testkit_suite_config_run_named_suite(string $suiteKey, array $suites, string $projectRoot, string $defaultOutputMode, string $defaultSuccessStderr, array &$commandTrace, array $stack = []): array
{
    if (in_array($suiteKey, $stack, true)) {
        $path = implode(' -> ', array_merge($stack, [$suiteKey]));
        fwrite(STDERR, "Suite composition cycle: {$path}\n");
        $result = testkit_suite_config_empty_result();
        $result['required_failures'] = 1;
        return $result;
    }

    $suite = $suites[$suiteKey];
    if (isset($suite['commands'])) {
        return testkit_suite_config_run_command_suite($suite, $projectRoot, $defaultOutputMode, $defaultSuccessStderr, $commandTrace);
    }

    $outputMode = (string)($suite['output'] ?? $defaultOutputMode);
    $successStderr = (string)($suite['success_stderr'] ?? $defaultSuccessStderr);
    if ($outputMode === 'live') {
        echo pvt_ui_bold(pvt_ui_cyan('==>')) . " {$suite['label']} [" . pvt_ui_gray($suiteKey) . "]\n";
    }

    $result = testkit_suite_config_empty_result();
    $startedAt = microtime(true);
    $stack[] = $suiteKey;
    foreach ($suite['suites'] as $childKey) {
        $childResult = testkit_suite_config_run_named_suite((string)$childKey, $suites, $projectRoot, $outputMode, $successStderr, $commandTrace, $stack);
        testkit_suite_config_merge_result($result, $childResult);
        if ($childResult['required_failures'] > 0 && (bool)($suite['fail_fast'] ?? true)) break;
    }

    $result['time_ms'] = (int)round((microtime(true) - $startedAt) * 1000);
    if (!(bool)$suite['required']) $result['required_failures'] = 0;
    if ($outputMode === 'live' && $result['required_failures'] === 0) echo testkit_suite_config_status('OK') . " {$suiteKey}\n";
    return $result;
}

/**
 * Machine result is an opt-in caller-owned exchange file, not a canonical
 * TestKit report. It intentionally excludes child stdout/stderr.
 *
 * @param array<string,int> $result
 * @param array<int,array<string,mixed>> $commandTrace
 */
function testkit_suite_config_write_result_json(string $path, string $projectRoot, string $suiteKey, array $result, array $commandTrace): void
{
    $resolved = testkit_suite_config_absolute_path($path, $projectRoot);
    $directory = dirname($resolved);
    if (!is_dir($directory)) {
        fwrite(STDERR, "No existe directorio para --result-json: {$directory}\n");
        exit(2);
    }

    $exitCode = $result['required_failures'] === 0 ? 0 : 1;
    $payload = [
        'schema' => 1,
        'runner' => 'runSuiteConfig',
        'suite' => $suiteKey,
        'status' => $exitCode === 0 ? 'PASS' : 'FAIL',
        'exit_code' => $exitCode,
        'summary' => [
            'suites' => (int)$result['suites'],
            'passed' => (int)$result['passed'],
            'failed' => (int)$result['failed'],
            'commands' => (int)$result['commands'],
            'passed_commands' => (int)$result['passed_commands'],
            'failed_commands' => (int)$result['failed_commands'],
            'required_failures' => (int)$result['required_failures'],
            'duration_ms' => (int)$result['time_ms'],
        ],
        'commands' => $commandTrace,
    ];

    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        fwrite(STDERR, "No se pudo serializar --result-json.\n");
        exit(2);
    }

    $temp = tempnam($directory, '.testkit-suite-result-');
    if ($temp === false) {
        fwrite(STDERR, "No se pudo crear temporal para --result-json.\n");
        exit(2);
    }

    try {
        @chmod($temp, 0600);
        if (file_put_contents($temp, $encoded . PHP_EOL) === false) {
            fwrite(STDERR, "No se pudo escribir --result-json.\n");
            exit(2);
        }
        if (!@rename($temp, $resolved)) {
            fwrite(STDERR, "No se pudo publicar --result-json atomicamente.\n");
            exit(2);
        }
        @chmod($resolved, 0600);
    } finally {
        if (is_file($temp)) @unlink($temp);
    }
}

function testkit_suite_config_compact_requested(): bool
{
    $raw = getenv('TESTKIT_CONSOLE_MODE');
    return is_string($raw) && trim($raw) !== '' && ConsoleMode::isCompact();
}

function testkit_suite_config_format_duration(int $timeMs): string
{
    if ($timeMs < 1000) return $timeMs . 'ms';
    return rtrim(rtrim(number_format($timeMs / 1000, 1, '.', ''), '0'), '.') . 's';
}

function testkit_suite_config_print_compact_pass(string $label, array $result): void
{
    $total = $result['commands'] > 0 ? (int)$result['commands'] : (int)$result['suites'];
    $passed = $result['commands'] > 0 ? (int)$result['passed_commands'] : (int)$result['passed'];
    $status = testkit_suite_config_status('PASS');
    $counts = pvt_ui_green("{$passed}/{$total}");
    $time = pvt_ui_dim(testkit_suite_config_format_duration((int)$result['time_ms']));
    echo $status . ' ' . str_pad($label, 30) . ' ' . $counts . ' ' . $time . "\n";
}

function testkit_suite_config_print_summary(string $suiteKey, string $suiteLabel, array $result): void
{
    if (
        testkit_suite_config_compact_requested()
        && $result['required_failures'] === 0
        && $result['failed'] === 0
        && $result['failed_commands'] === 0
    ) {
        testkit_suite_config_print_compact_pass($suiteLabel, $result);
        return;
    }

    $summaryLabel = pvt_ui_bold(pvt_ui_cyan('Summary:'));
    $passed = pvt_ui_green((string)$result['passed']);
    $failed = $result['failed'] > 0 ? pvt_ui_red((string)$result['failed']) : pvt_ui_green('0');
    $passedCommands = pvt_ui_green((string)$result['passed_commands']);
    $failedCommands = $result['failed_commands'] > 0 ? pvt_ui_red((string)$result['failed_commands']) : pvt_ui_green('0');
    $time = pvt_ui_dim("time_ms={$result['time_ms']}");

    echo "\n{$summaryLabel} "
        . "suites={$result['suites']} "
        . "passed={$passed} "
        . "failed={$failed} "
        . "commands={$result['commands']} "
        . "passed_commands={$passedCommands} "
        . "failed_commands={$failedCommands} "
        . "{$time}\n";

    if ($result['required_failures'] === 0) {
        echo testkit_suite_config_status('OK') . " {$suiteKey}\n";
        return;
    }
    echo testkit_suite_config_status('FAIL') . " {$suiteKey}\n";
}

$parsed = testkit_suite_config_parse_args($argv);
$projectRoot = (string)getenv('TESTKIT_PROJECT_ROOT');
if ($projectRoot === '') $projectRoot = getcwd() ?: '.';
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

$commandTrace = [];
$result = testkit_suite_config_run_named_suite(
    $suiteKey,
    $suites,
    $projectRoot,
    (string)$config['output'],
    (string)$config['success_stderr'],
    $commandTrace
);
if ((string)$config['output'] === 'failures') {
    testkit_suite_config_print_summary($suiteKey, (string)$suites[$suiteKey]['label'], $result);
}
if (is_string($parsed['result_json'])) {
    testkit_suite_config_write_result_json($parsed['result_json'], $projectRoot, $suiteKey, $result, $commandTrace);
}
exit($result['required_failures'] === 0 ? 0 : 1);
