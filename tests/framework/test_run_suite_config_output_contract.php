<?php
declare(strict_types=1);

$runner = dirname(__DIR__, 2) . '/runners/runSuiteConfig.php';

function fail_test(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assert_test(bool $condition, string $message): void
{
    if (!$condition) {
        fail_test($message);
    }
}

/** @return array{code:int,output:string} */
function run_runner(string $runner, string $root, string $config, string $suite): array
{
    $cmd = 'TESTKIT_PROJECT_ROOT=' . escapeshellarg($root)
        . ' php ' . escapeshellarg($runner)
        . ' ' . escapeshellarg($config)
        . ' ' . escapeshellarg($suite)
        . ' 2>&1';
    $lines = [];
    $code = 0;
    exec($cmd, $lines, $code);
    return ['code' => $code, 'output' => implode("\n", $lines)];
}

function write_config(string $path, array $config): void
{
    $php = "<?php\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents($path, $php) === false) {
        fail_test('cannot write fixture config');
    }
}

function remove_tree(string $path): void
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
        $child = $path . '/' . $item;
        if (is_dir($child)) {
            remove_tree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}

$root = sys_get_temp_dir() . '/testkit-suite-config-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0777, true) && !is_dir($root)) {
    fail_test('cannot create fixture root');
}

try {
    $baseSuites = [
        [
            'key' => 'pass',
            'label' => 'passing suite',
            'working_directory' => '.',
            'commands' => ["php -r 'echo \"PASS_NOISE\\n\";'"],
            'required' => true,
            'description' => 'fixture pass',
        ],
        [
            'key' => 'fail',
            'label' => 'failing suite',
            'working_directory' => '.',
            'commands' => ["php -r 'fwrite(STDERR, \"FAIL_DETAIL\\n\"); exit(7);'"],
            'required' => true,
            'description' => 'fixture fail',
        ],
        [
            'key' => 'all',
            'label' => 'composite fixture',
            'working_directory' => '.',
            'suites' => ['pass', 'fail'],
            'required' => true,
            'description' => 'fixture composite',
            'fail_fast' => false,
        ],
    ];

    write_config($root . '/failures.php', [
        'output' => 'failures',
        'suites' => $baseSuites,
    ]);

    $failed = run_runner($runner, $root, 'failures.php', 'all');
    assert_test($failed['code'] === 1, 'composite returns non-zero when required child fails');
    assert_test(!str_contains($failed['output'], 'PASS_NOISE'), 'successful child stdout is hidden');
    assert_test(str_contains($failed['output'], 'FAIL fail'), 'failed suite is identified');
    assert_test(str_contains($failed['output'], 'FAIL_DETAIL'), 'failed child output is preserved');
    assert_test(str_contains($failed['output'], 'Summary:'), 'failure mode prints summary');
    assert_test(str_contains($failed['output'], 'suites=2 passed=1 failed=1'), 'summary aggregates leaf suites');
    assert_test(str_contains($failed['output'], 'commands=2 passed_commands=1 failed_commands=1'), 'summary aggregates commands');
    assert_test(str_contains($failed['output'], 'FAIL all'), 'composite failure is explicit');

    $passSuites = $baseSuites;
    $passSuites[2]['suites'] = ['pass'];
    write_config($root . '/pass.php', [
        'output' => 'failures',
        'suites' => $passSuites,
    ]);

    $passed = run_runner($runner, $root, 'pass.php', 'all');
    assert_test($passed['code'] === 0, 'passing composite returns zero');
    assert_test(!str_contains($passed['output'], 'PASS_NOISE'), 'passing stdout stays hidden');
    assert_test(str_contains($passed['output'], 'Summary: suites=1 passed=1 failed=0'), 'passing summary is emitted');
    assert_test(str_contains($passed['output'], 'OK all'), 'passing composite closes with OK');

    write_config($root . '/live.php', [
        'suites' => [$baseSuites[0]],
    ]);
    $live = run_runner($runner, $root, 'live.php', 'pass');
    assert_test($live['code'] === 0, 'legacy live mode returns zero');
    assert_test(str_contains($live['output'], 'PASS_NOISE'), 'legacy live mode preserves child stdout');
    assert_test(str_contains($live['output'], 'OK pass'), 'legacy live mode preserves suite OK');
    assert_test(!str_contains($live['output'], 'Summary:'), 'legacy live mode does not add summary noise');
} finally {
    remove_tree($root);
}

echo "OK run_suite_config_output_contract\n";
