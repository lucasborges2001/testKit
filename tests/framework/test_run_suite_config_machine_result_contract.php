<?php
declare(strict_types=1);

$runner = dirname(__DIR__, 2) . '/runners/runSuiteConfig.php';

function machine_fail(string $message): never
{
    fwrite(STDERR, "FAIL run_suite_config_machine_result_contract: {$message}\n");
    exit(1);
}

function machine_assert(bool $condition, string $message): void
{
    if (!$condition) machine_fail($message);
}

function machine_write_config(string $path, array $config): void
{
    $php = "<?php\nreturn " . var_export($config, true) . ";\n";
    file_put_contents($path, $php) !== false || machine_fail('cannot write fixture config');
}

function machine_remove_tree(string $path): void
{
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $child = $path . '/' . $item;
        is_dir($child) ? machine_remove_tree($child) : @unlink($child);
    }
    @rmdir($path);
}

/** @return array{code:int,output:string} */
function machine_run(string $runner, string $root, string $config, string $suite, string $resultPath): array
{
    $command = 'TESTKIT_PROJECT_ROOT=' . escapeshellarg($root)
        . ' php ' . escapeshellarg($runner)
        . ' ' . escapeshellarg($config)
        . ' ' . escapeshellarg($suite)
        . ' --result-json ' . escapeshellarg($resultPath)
        . ' 2>&1';
    $lines = [];
    $code = 0;
    exec($command, $lines, $code);
    return ['code' => $code, 'output' => implode("\n", $lines)];
}

$root = sys_get_temp_dir() . '/testkit-suite-machine-' . bin2hex(random_bytes(6));
mkdir($root, 0777, true) || machine_fail('cannot create fixture root');

try {
    machine_write_config($root . '/config.php', [
        'output' => 'failures',
        'suites' => [
            [
                'key' => 'pass',
                'label' => 'machine pass',
                'working_directory' => '.',
                'commands' => [
                    "php -r 'echo \"PASS_STDOUT_MUST_NOT_ENTER_JSON\\n\";'",
                    "php -r 'usleep(10000);'",
                ],
                'required' => true,
                'description' => 'machine pass fixture',
                'fail_fast' => false,
            ],
            [
                'key' => 'fail',
                'label' => 'machine fail',
                'working_directory' => '.',
                'commands' => ["php -r 'fwrite(STDERR, \"FAIL_STDERR_MUST_NOT_ENTER_JSON\\n\"); exit(7);'"],
                'required' => true,
                'description' => 'machine fail fixture',
            ],
        ],
    ]);

    $passPath = $root . '/pass-result.json';
    $pass = machine_run($runner, $root, 'config.php', 'pass', $passPath);
    machine_assert($pass['code'] === 0, 'PASS runner exit changed: ' . $pass['output']);
    machine_assert(is_file($passPath), 'PASS machine result missing');
    $passJson = json_decode((string)file_get_contents($passPath), true, 512, JSON_THROW_ON_ERROR);
    machine_assert(($passJson['schema'] ?? null) === 1, 'schema must be 1');
    machine_assert(($passJson['runner'] ?? null) === 'runSuiteConfig', 'runner identity mismatch');
    machine_assert(($passJson['suite'] ?? null) === 'pass', 'selected suite mismatch');
    machine_assert(($passJson['status'] ?? null) === 'PASS', 'PASS status mismatch');
    machine_assert(($passJson['exit_code'] ?? null) === 0, 'PASS machine exit mismatch');
    machine_assert(($passJson['summary']['commands'] ?? null) === 2, 'PASS command count mismatch');
    machine_assert(count($passJson['commands'] ?? []) === 2, 'PASS command trace size mismatch');
    machine_assert(($passJson['commands'][0]['command_index'] ?? null) === 1, 'command index must be 1-based');
    machine_assert(($passJson['commands'][0]['status'] ?? null) === 'PASS', 'first command status mismatch');
    machine_assert(($passJson['commands'][0]['exit_code'] ?? null) === 0, 'first command exit mismatch');
    machine_assert(is_int($passJson['commands'][0]['duration_ms'] ?? null), 'duration_ms must be integer');
    machine_assert(!array_key_exists('stdout', $passJson['commands'][0]), 'stdout must not enter machine result');
    machine_assert(!array_key_exists('stderr', $passJson['commands'][0]), 'stderr must not enter machine result');
    machine_assert((fileperms($passPath) & 0777) === 0600, 'machine result permissions must be 0600');

    $failPath = $root . '/fail-result.json';
    $fail = machine_run($runner, $root, 'config.php', 'fail', $failPath);
    machine_assert($fail['code'] === 1, 'FAIL runner exit changed: ' . $fail['output']);
    machine_assert(str_contains($fail['output'], 'FAIL_STDERR_MUST_NOT_ENTER_JSON'), 'human failure diagnostic disappeared');
    $failJson = json_decode((string)file_get_contents($failPath), true, 512, JSON_THROW_ON_ERROR);
    machine_assert(($failJson['status'] ?? null) === 'FAIL', 'FAIL status mismatch');
    machine_assert(($failJson['exit_code'] ?? null) === 1, 'FAIL aggregate exit mismatch');
    machine_assert(($failJson['commands'][0]['status'] ?? null) === 'FAIL', 'failed command status mismatch');
    machine_assert(($failJson['commands'][0]['exit_code'] ?? null) === 7, 'failed child exit must be preserved');

    $missingDirectory = machine_run($runner, $root, 'config.php', 'pass', $root . '/missing/result.json');
    machine_assert($missingDirectory['code'] === 2, 'invalid result destination must use interface/config exit 2');
} finally {
    machine_remove_tree($root);
}

echo "OK run_suite_config_machine_result_contract\n";
