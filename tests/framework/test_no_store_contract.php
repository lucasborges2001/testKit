<?php
declare(strict_types=1);

$repoRoot = realpath(__DIR__ . '/../..');
if ($repoRoot === false) {
    fwrite(STDERR, "Could not resolve repo root\n");
    exit(1);
}

$errors = [];

function nostore_assert(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

function nostore_run(array $command, string $cwd, array $env): array
{
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $processEnv = [];
    foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
        if (is_scalar($value) && $value !== null) {
            $processEnv[(string)$key] = (string)$value;
        }
    }
    foreach ($env as $key => $value) {
        if ($value === null) {
            unset($processEnv[$key]);
            continue;
        }
        $processEnv[$key] = (string)$value;
    }

    $proc = proc_open($command, $descriptorSpec, $pipes, $cwd, $processEnv);
    if (!is_resource($proc)) {
        return ['code' => 127, 'stdout' => '', 'stderr' => 'proc_open failed'];
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    return ['code' => $code, 'stdout' => (string)$stdout, 'stderr' => (string)$stderr];
}

function nostore_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            nostore_rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

$tmp = sys_get_temp_dir() . '/testkit-no-store-' . bin2hex(random_bytes(6));
$projectRoot = $tmp . '/project';
$testDir = $projectRoot . '/test';
$fakeBin = $tmp . '/bin';
@mkdir($testDir, 0777, true);
@mkdir($fakeBin, 0777, true);

file_put_contents(
    $testDir . '/.env.test',
    implode(PHP_EOL, [
        'APP_ENV=test',
        'TEST_ENV=test',
        'TEST_STORE_DRIVER=none',
        'TEST_STORE_PROVISION=external',
    ]) . PHP_EOL
);

$dockerStub = $fakeBin . '/docker';
file_put_contents($dockerStub, "#!/usr/bin/env sh\nexit 0\n");
@chmod($dockerStub, 0755);

$baseEnv = [
    'PATH' => $fakeBin . PATH_SEPARATOR . (getenv('PATH') ?: ''),
    'TESTKIT_PROJECT_ROOT' => $projectRoot,
    'TESTKIT_ROOT' => $repoRoot,
    'TK_REPO_ROOT' => $projectRoot,
    'TESTKIT_STACK' => null,
    'DB_HOST' => null,
    'DB_PORT' => null,
    'DB_NAME' => null,
    'DB_USER' => null,
    'DB_PASS' => null,
    'TEST_MYSQL_HOST' => null,
    'TEST_MYSQL_PORT' => null,
    'TEST_MYSQL_DB' => null,
    'TEST_MYSQL_USER' => null,
    'TEST_MYSQL_PASSWORD' => null,
    'TEST_MYSQL_ADMIN_USER' => null,
    'TEST_MYSQL_ROOT_PASSWORD' => null,
    'MYSQL_HOST' => null,
    'MYSQL_DATABASE' => null,
    'MYSQL_USER' => null,
    'MYSQL_PASSWORD' => null,
    'MYSQL_ROOT_PASSWORD' => null,
];

try {
    $doctor = nostore_run(['bash', $repoRoot . '/bin/testkit', 'doctor', '--full'], $repoRoot, $baseEnv);
    $doctorOutput = $doctor['stdout'] . "\n" . $doctor['stderr'];
    nostore_assert($doctor['code'] === 0, 'doctor compact should pass for no-store env.', $errors);
    nostore_assert(str_contains($doctorOutput, 'STORE_DRIVER_NONE'), 'doctor should report STORE_DRIVER_NONE.', $errors);
    nostore_assert(str_contains($doctorOutput, 'TESTKIT_STACK efectivo: <empty>'), 'doctor should allow empty effective stack.', $errors);
    nostore_assert(!str_contains($doctorOutput, 'MYSQL_'), 'doctor should not require MYSQL_* for no-store env.', $errors);
    nostore_assert(!str_contains($doctorOutput, 'ENGINE_NOT_CLOSED'), 'doctor should not emit ENGINE_NOT_CLOSED for no-store env.', $errors);
    nostore_assert(!str_contains($doctorOutput, 'NON_MYSQL_BASE_CHECKS_PARTIAL'), 'doctor should not warn partial base checks for no-store env.', $errors);

    foreach ([
        'back-python' => ['--suite', 'back-python'],
        'back-php' => ['--suite', 'back-php'],
        'contract' => ['--category', 'contract'],
    ] as $target => $selector) {
        $run = nostore_run(
            array_merge([PHP_BINARY, $repoRoot . '/runTest.php'], $selector, ['--list']),
            $repoRoot,
            $baseEnv
        );
        $output = $run['stdout'] . "\n" . $run['stderr'];
        nostore_assert($run['code'] === 0, "{$target} --list should not fail without DB.", $errors);
        nostore_assert(!str_contains($output, 'driver=mysql'), "{$target} --list should not bootstrap mysql.", $errors);
        nostore_assert(!str_contains($output, 'INFRA_ERROR'), "{$target} --list should not report INFRA_ERROR.", $errors);
        if ($target === 'back-python' || $target === 'contract') {
            nostore_assert(str_contains($output, 'driver=none'), "{$target} --list should expose driver=none when bootstrap runs.", $errors);
        }
    }
} finally {
    nostore_rrmdir($tmp);
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . PHP_EOL);
    }
    exit(1);
}

echo "No-store contract PASS\n";
