<?php
declare(strict_types=1);

$runner = dirname(__DIR__, 2) . '/runners/runSuiteConfig.php';

function compact_fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function compact_assert(bool $condition, string $message): void
{
    if (!$condition) {
        compact_fail($message);
    }
}

/** @param array<string,string> $env */
function compact_run(string $runner, string $root, string $config, string $suite, array $env): array
{
    $prefix = 'TESTKIT_PROJECT_ROOT=' . escapeshellarg($root);
    foreach ($env as $key => $value) {
        $prefix .= ' ' . $key . '=' . escapeshellarg($value);
    }
    $lines = [];
    $code = 0;
    exec($prefix . ' php ' . escapeshellarg($runner) . ' ' . escapeshellarg($config) . ' ' . escapeshellarg($suite) . ' 2>&1', $lines, $code);
    return ['code' => $code, 'output' => implode("\n", $lines)];
}

$root = sys_get_temp_dir() . '/testkit-suite-compact-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0777, true) && !is_dir($root)) {
    compact_fail('cannot create fixture root');
}

try {
    $config = [
        'output' => 'failures',
        'suites' => [
            [
                'key' => 'pass',
                'label' => 'Base PHP syntax',
                'working_directory' => '.',
                'commands' => [
                    "php -r 'echo \"PASS_NOISE\\n\";'",
                    "php -r 'echo \"MORE_NOISE\\n\";'",
                ],
                'required' => true,
                'description' => 'compact pass fixture',
                'fail_fast' => false,
            ],
            [
                'key' => 'fail',
                'label' => 'Framework tests',
                'working_directory' => '.',
                'commands' => ["php -r 'fwrite(STDERR, \"FAIL_DETAIL\\n\"); exit(7);'"],
                'required' => true,
                'description' => 'compact failure fixture',
            ],
        ],
    ];
    file_put_contents($root . '/suites.php', "<?php\nreturn " . var_export($config, true) . ";\n");

    $pass = compact_run($runner, $root, 'suites.php', 'pass', ['TESTKIT_CONSOLE_MODE' => 'compact']);
    compact_assert($pass['code'] === 0, 'compact pass preserves exit 0');
    compact_assert(str_contains($pass['output'], 'PASS Base PHP syntax'), 'compact pass prints human label');
    compact_assert(str_contains($pass['output'], '2/2'), 'compact pass prints aggregate command count');
    compact_assert(!str_contains($pass['output'], 'PASS_NOISE'), 'compact pass hides successful stdout');
    compact_assert(!str_contains($pass['output'], 'MORE_NOISE'), 'compact pass hides all successful stdout');
    compact_assert(!str_contains($pass['output'], 'Summary:'), 'compact pass removes legacy summary block');
    compact_assert(!str_contains($pass['output'], 'OK pass'), 'compact pass removes duplicate OK line');

    $colored = compact_run($runner, $root, 'suites.php', 'pass', ['TESTKIT_CONSOLE_MODE' => 'compact', 'FORCE_COLOR' => '1']);
    compact_assert(str_contains($colored['output'], "\033[32mPASS\033[0m"), 'compact PASS uses green TestKit status color');

    $noColor = compact_run($runner, $root, 'suites.php', 'pass', ['TESTKIT_CONSOLE_MODE' => 'compact', 'FORCE_COLOR' => '1', 'NO_COLOR' => '1']);
    compact_assert(!str_contains($noColor['output'], "\033["), 'NO_COLOR strips compact ANSI');

    $failure = compact_run($runner, $root, 'suites.php', 'fail', ['TESTKIT_CONSOLE_MODE' => 'compact']);
    compact_assert($failure['code'] === 1, 'compact mode preserves failing exit code');
    compact_assert(str_contains($failure['output'], 'FAIL_DETAIL'), 'compact mode preserves failure detail');
    compact_assert(str_contains($failure['output'], 'Summary:'), 'non-green result keeps diagnostic summary');
    compact_assert(str_contains($failure['output'], 'FAIL fail'), 'non-green result keeps explicit suite failure');

    $config['success_stderr'] = 'show';
    $config['suites'][0]['commands'] = ["php -r 'fwrite(STDERR, \"PASS_WARNING\\n\");'"];
    file_put_contents($root . '/stderr.php', "<?php\nreturn " . var_export($config, true) . ";\n");
    $stderrPass = compact_run($runner, $root, 'stderr.php', 'pass', ['TESTKIT_CONSOLE_MODE' => 'compact']);
    compact_assert($stderrPass['code'] === 0, 'compact success_stderr preserves exit 0');
    compact_assert(str_contains($stderrPass['output'], 'PASS_WARNING'), 'compact success_stderr preserves successful stderr');
    compact_assert(str_contains($stderrPass['output'], 'PASS Base PHP syntax'), 'compact success_stderr still prints aggregate PASS');
    compact_assert(!str_contains($stderrPass['output'], 'Summary:'), 'compact success_stderr does not restore legacy summary');

    $legacy = compact_run($runner, $root, 'suites.php', 'pass', ['TESTKIT_CONSOLE_MODE' => 'live']);
    compact_assert(str_contains($legacy['output'], 'Summary:'), 'live console mode preserves legacy captured summary');
    compact_assert(str_contains($legacy['output'], 'OK pass'), 'live console mode preserves legacy aggregate OK');
} finally {
    foreach (glob($root . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($root);
}

echo "OK run_suite_config_compact_contract\n";
