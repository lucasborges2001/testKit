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

/**
 * @param array<string,string> $env
 * @param array<int,string> $phpArgs
 * @return array{code:int,output:string}
 */
function run_runner(
    string $runner,
    string $root,
    string $config,
    string $suite,
    array $env = [],
    array $phpArgs = []
): array {
    $envPrefix = 'TESTKIT_PROJECT_ROOT=' . escapeshellarg($root);
    foreach ($env as $key => $value) {
        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) {
            fail_test('invalid env key for runner fixture');
        }
        $envPrefix .= ' ' . $key . '=' . escapeshellarg($value);
    }

    $phpCommand = 'php';
    foreach ($phpArgs as $arg) {
        $phpCommand .= ' ' . escapeshellarg($arg);
    }

    $cmd = $envPrefix
        . ' ' . $phpCommand
        . ' ' . escapeshellarg($runner)
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
            'commands' => ["php -r 'echo \"PASS_NOISE\\n\"; fwrite(STDERR, \"PASS_WARNING\\n\");'"],
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
    assert_test(!str_contains($failed['output'], 'PASS_WARNING'), 'successful child stderr stays hidden by default');
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
    assert_test(!str_contains($passed['output'], 'PASS_WARNING'), 'passing stderr stays hidden by default');
    assert_test(str_contains($passed['output'], 'Summary: suites=1 passed=1 failed=0'), 'passing summary is emitted');
    assert_test(str_contains($passed['output'], 'OK all'), 'passing composite closes with OK');

    $colorPassed = run_runner($runner, $root, 'pass.php', 'all', ['FORCE_COLOR' => '1']);
    assert_test($colorPassed['code'] === 0, 'forced color preserves passing exit code');
    assert_test(str_contains($colorPassed['output'], "\033["), 'forced color emits ANSI sequences');
    assert_test(str_contains($colorPassed['output'], "\033[36mSummary:\033[0m"), 'summary label uses TestKit cyan UI color');
    assert_test(str_contains($colorPassed['output'], "\033[32mOK\033[0m"), 'passing status uses TestKit green UI color');

    $colorFailed = run_runner($runner, $root, 'failures.php', 'all', ['FORCE_COLOR' => '1']);
    assert_test($colorFailed['code'] === 1, 'forced color preserves failing exit code');
    assert_test(str_contains($colorFailed['output'], "\033[31mFAIL\033[0m"), 'failing status uses TestKit red UI color');
    assert_test(str_contains($colorFailed['output'], 'FAIL_DETAIL'), 'forced color preserves child failure stderr');

    $noColorPassed = run_runner(
        $runner,
        $root,
        'pass.php',
        'all',
        ['FORCE_COLOR' => '1', 'NO_COLOR' => '1']
    );
    assert_test($noColorPassed['code'] === 0, 'NO_COLOR preserves passing exit code');
    assert_test(!str_contains($noColorPassed['output'], "\033["), 'NO_COLOR suppresses ANSI sequences');

    $phpProbe = <<<'PHP'
$marker = getenv('TESTKIT_ENV_INHERIT_MARKER');
$path = getenv('PATH');
if ($marker !== 'visible') {
    fwrite(STDERR, "ENV_MARKER_MISSING\n");
    exit(11);
}
if (!is_string($path) || $path === '') {
    fwrite(STDERR, "PATH_MISSING\n");
    exit(12);
}
if (PHP_BINARY === '') {
    fwrite(STDERR, "PHP_BINARY_MISSING\n");
    exit(13);
}
PHP;

    $pythonProbe = <<<'PY'
import os
import sys
if os.environ.get('TESTKIT_ENV_INHERIT_MARKER') != 'visible':
    print('PY_ENV_MARKER_MISSING', file=sys.stderr)
    raise SystemExit(21)
if not os.environ.get('PATH'):
    print('PY_PATH_MISSING', file=sys.stderr)
    raise SystemExit(22)
if not sys.executable:
    print('PY_EXECUTABLE_MISSING', file=sys.stderr)
    raise SystemExit(23)
PY;

    write_config($root . '/environment.php', [
        'output' => 'failures',
        'suites' => [[
            'key' => 'environment',
            'label' => 'child environment contract',
            'working_directory' => '.',
            'commands' => [
                'php -r ' . escapeshellarg($phpProbe),
                'python3 -c ' . escapeshellarg($pythonProbe),
            ],
            'required' => true,
            'description' => 'fixture for inherited environment',
            'fail_fast' => false,
        ]],
    ]);

    $environment = run_runner(
        $runner,
        $root,
        'environment.php',
        'environment',
        ['TESTKIT_ENV_INHERIT_MARKER' => 'visible'],
        ['-d', 'variables_order=GPCS']
    );
    assert_test($environment['code'] === 0, 'child environment is inherited when $_ENV is empty: ' . $environment['output']);
    assert_test(str_contains($environment['output'], 'Summary: suites=1 passed=1 failed=0 commands=2 passed_commands=2 failed_commands=0'), 'environment probe summary is green');
    assert_test(str_contains($environment['output'], 'OK environment'), 'environment probe closes with OK');

    write_config($root . '/show-success-stderr.php', [
        'output' => 'failures',
        'success_stderr' => 'show',
        'suites' => $passSuites,
    ]);

    $showSuccessStderr = run_runner($runner, $root, 'show-success-stderr.php', 'all');
    assert_test($showSuccessStderr['code'] === 0, 'success_stderr=show preserves successful exit code');
    assert_test(!str_contains($showSuccessStderr['output'], 'PASS_NOISE'), 'success_stderr=show still hides successful stdout');
    assert_test(str_contains($showSuccessStderr['output'], 'PASS_WARNING'), 'success_stderr=show preserves successful stderr');
    assert_test(str_contains($showSuccessStderr['output'], 'Summary: suites=1 passed=1 failed=0'), 'success_stderr=show keeps compact summary');
    assert_test(str_contains($showSuccessStderr['output'], 'OK all'), 'success_stderr=show keeps aggregate PASS');

    $colorSuccessStderr = run_runner(
        $runner,
        $root,
        'show-success-stderr.php',
        'all',
        ['FORCE_COLOR' => '1']
    );
    assert_test($colorSuccessStderr['code'] === 0, 'colored success_stderr preserves successful exit');
    assert_test(str_contains($colorSuccessStderr['output'], 'PASS_WARNING'), 'colored success_stderr preserves child stderr');
    assert_test(
        preg_match('/\x1b\[[0-9;]*mPASS_WARNING/', $colorSuccessStderr['output']) !== 1,
        'child stderr is not recolored by suite reporter'
    );

    $largeOutputProbe = <<<'PHP'
fwrite(STDOUT, "LARGE_STDOUT_MARKER\n");
for ($i = 0; $i < 24576; $i++) {
    fwrite(STDOUT, str_repeat('X', 1024));
}
fwrite(STDERR, "LARGE_STDERR_MARKER\n");
PHP;

    $largeOutputCommand = 'php -r ' . escapeshellarg($largeOutputProbe);
    $largeOutputSuite = [
        'key' => 'large-pass',
        'label' => 'large successful output',
        'working_directory' => '.',
        'commands' => [$largeOutputCommand],
        'required' => true,
        'description' => 'large successful capture regression',
    ];

    write_config($root . '/large-pass-hidden.php', [
        'output' => 'failures',
        'success_stderr' => 'hide',
        'suites' => [$largeOutputSuite],
    ]);

    $largeHidden = run_runner(
        $runner,
        $root,
        'large-pass-hidden.php',
        'large-pass',
        [],
        ['-d', 'memory_limit=16M']
    );

    assert_test(
        $largeHidden['code'] === 0,
        'large successful stdout must not exhaust runner memory: ' . $largeHidden['output']
    );
    assert_test(
        !str_contains($largeHidden['output'], 'LARGE_STDOUT_MARKER'),
        'large successful stdout stays hidden'
    );
    assert_test(
        !str_contains($largeHidden['output'], 'LARGE_STDERR_MARKER'),
        'large successful stderr stays hidden when success_stderr=hide'
    );

    write_config($root . '/large-pass-show-stderr.php', [
        'output' => 'failures',
        'success_stderr' => 'show',
        'suites' => [$largeOutputSuite],
    ]);

    $largeShowStderr = run_runner(
        $runner,
        $root,
        'large-pass-show-stderr.php',
        'large-pass',
        [],
        ['-d', 'memory_limit=16M']
    );

    assert_test(
        $largeShowStderr['code'] === 0,
        'success_stderr=show must not load large successful stdout: ' . $largeShowStderr['output']
    );
    assert_test(
        !str_contains($largeShowStderr['output'], 'LARGE_STDOUT_MARKER'),
        'success_stderr=show still hides large successful stdout'
    );
    assert_test(
        str_contains($largeShowStderr['output'], 'LARGE_STDERR_MARKER'),
        'success_stderr=show still preserves successful stderr'
    );

    $compositeOverrideSuites = $passSuites;
    $compositeOverrideSuites[2]['success_stderr'] = 'show';
    write_config($root . '/composite-override.php', [
        'output' => 'failures',
        'success_stderr' => 'hide',
        'suites' => $compositeOverrideSuites,
    ]);

    $compositeOverride = run_runner($runner, $root, 'composite-override.php', 'all');
    assert_test($compositeOverride['code'] === 0, 'composite success_stderr override preserves successful exit');
    assert_test(str_contains($compositeOverride['output'], 'PASS_WARNING'), 'composite success_stderr override is inherited by child suites');

    write_config($root . '/invalid-root-success-stderr.php', [
        'output' => 'failures',
        'success_stderr' => 'invalid',
        'suites' => [$baseSuites[0]],
    ]);

    $invalidRoot = run_runner($runner, $root, 'invalid-root-success-stderr.php', 'pass');
    assert_test($invalidRoot['code'] === 2, 'invalid root success_stderr returns config error');
    assert_test(str_contains($invalidRoot['output'], 'success_stderr debe ser hide o show'), 'invalid root success_stderr explains allowed values');

    $invalidSuite = $baseSuites[0];
    $invalidSuite['success_stderr'] = 'invalid';
    write_config($root . '/invalid-suite-success-stderr.php', [
        'output' => 'failures',
        'suites' => [$invalidSuite],
    ]);

    $invalidSuiteResult = run_runner($runner, $root, 'invalid-suite-success-stderr.php', 'pass');
    assert_test($invalidSuiteResult['code'] === 2, 'invalid suite success_stderr returns config error');
    assert_test(str_contains($invalidSuiteResult['output'], 'success_stderr debe ser hide o show'), 'invalid suite success_stderr explains allowed values');

    write_config($root . '/live.php', [
        'suites' => [$baseSuites[0]],
    ]);
    $live = run_runner($runner, $root, 'live.php', 'pass');
    assert_test($live['code'] === 0, 'legacy live mode returns zero');
    assert_test(str_contains($live['output'], 'PASS_NOISE'), 'legacy live mode preserves child stdout');
    assert_test(str_contains($live['output'], 'PASS_WARNING'), 'legacy live mode preserves child stderr');
    assert_test(str_contains($live['output'], 'OK pass'), 'legacy live mode preserves suite OK');
    assert_test(!str_contains($live['output'], 'Summary:'), 'legacy live mode does not add summary noise');
} finally {
    remove_tree($root);
}

echo "OK run_suite_config_output_contract\n";
