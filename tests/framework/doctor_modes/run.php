<?php
declare(strict_types=1);

$repoRoot = realpath(__DIR__ . '/../../..');
if ($repoRoot === false) {
    fwrite(STDERR, "Could not resolve repo root\n");
    exit(1);
}

$casesFile = __DIR__ . '/cases.json';
$cases = json_decode((string) file_get_contents($casesFile), true, 512, JSON_THROW_ON_ERROR);

$wrappers = [];
$bashWrapper = $repoRoot . '/bin/testkit';
if (is_file($bashWrapper)) {
    $wrappers[] = ['name' => 'bash', 'cmd' => ['bash', $bashWrapper]];
}
$pwshPath = trim((string) shell_exec('command -v pwsh 2>/dev/null || true'));
$pwshWrapper = $repoRoot . '/bin/testkit.ps1';
if ($pwshPath !== '' && is_file($pwshWrapper)) {
    $wrappers[] = ['name' => 'pwsh', 'cmd' => [$pwshPath, '-NoProfile', '-File', $pwshWrapper]];
}
if ($wrappers === []) {
    fwrite(STDERR, "No wrapper available to test\n");
    exit(1);
}

$failures = [];
$executed = 0;
$skipped = [];

foreach ($wrappers as $wrapper) {
    foreach ($cases as $case) {
        $executed++;
        $result = runCase($repoRoot, $wrapper, $case);
        if (!$result['ok']) {
            $failures[] = $result;
        }
    }
}

if ($pwshPath === '' && is_file($pwshWrapper)) {
    $skipped[] = 'pwsh wrapper skipped: pwsh not available in PATH';
}

foreach ($skipped as $line) {
    fwrite(STDOUT, "[SKIP] {$line}\n");
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "[FAIL] {$failure['wrapper']} :: {$failure['case']}\n{$failure['message']}\n\n");
    }
    fwrite(STDERR, sprintf("Doctor mode self-tests failed: %d/%d\n", count($failures), $executed));
    exit(1);
}

fwrite(STDOUT, sprintf("Doctor mode self-tests passed: %d case executions\n", $executed));
exit(0);

function runCase(string $repoRoot, array $wrapper, array $case): array
{
    $tempRoot = sys_get_temp_dir() . '/testkit-doctor-modes-' . bin2hex(random_bytes(6));
    $projectRoot = $tempRoot . '/project';
    $testDir = $projectRoot . '/test';
    $fakeBin = $tempRoot . '/bin';

    foreach ([$testDir, $fakeBin] as $dir) {
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create temp dir: ' . $dir);
        }
    }

    $env = [
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306',
        'DB_NAME' => 'testkit_db',
        'DB_USER' => 'testkit_user',
        'DB_PASS' => 'testkit_pass',
        'TEST_MYSQL_ADMIN_USER' => 'root',
        'TEST_MYSQL_ROOT_PASSWORD' => 'rootpass',
        'TEST_STORE_DRIVER' => 'mysql',
        'TEST_DB_STRATEGY' => 'shared',
        'TEST_JOBS' => '1',
    ];
    foreach (($case['env'] ?? []) as $k => $v) {
        $env[$k] = $v;
    }

    $envFilePath = $testDir . '/.env.test';
    $envLines = [];
    foreach ($env as $key => $value) {
        $envLines[] = $key . '=' . (string) $value;
    }
    file_put_contents($envFilePath, implode(PHP_EOL, $envLines) . PHP_EOL);

    $dockerStub = $fakeBin . '/docker';
    file_put_contents($dockerStub, "#!/usr/bin/env sh\nexit 0\n");
    @chmod($dockerStub, 0755);

    $command = $wrapper['cmd'];
    $command[] = 'doctor';
    foreach (($case['args'] ?? []) as $arg) {
        $command[] = (string) $arg;
    }

    $processEnv = [
        'PATH' => $fakeBin . PATH_SEPARATOR . (getenv('PATH') ?: ''),
        'TESTKIT_PROJECT_ROOT' => $projectRoot,
        'TESTKIT_ROOT' => $repoRoot,
    ];
    foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
        if (!is_scalar($value) || $value === null) {
            continue;
        }
        if (!array_key_exists((string) $key, $processEnv)) {
            $processEnv[(string) $key] = (string) $value;
        }
    }

    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($command, $descriptorSpec, $pipes, $repoRoot, $processEnv);
    if (!is_resource($proc)) {
        rrmdir($tempRoot);
        return fail($wrapper['name'], $case['id'], 'Could not start process');
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
    rrmdir($tempRoot);

    $output = $stdout . "\n" . $stderr;
    $messages = [];

    $expectedExit = (int) ($case['expect_exit'] ?? 0);
    if ($exitCode !== $expectedExit) {
        $messages[] = "Expected exit {$expectedExit}, got {$exitCode}";
    }

    foreach (($case['require_strings'] ?? []) as $needle) {
        if (strpos($output, $needle) === false) {
            $messages[] = "Missing required string: {$needle}";
        }
    }
    foreach (($case['forbid_strings'] ?? []) as $needle) {
        if (strpos($output, $needle) !== false) {
            $messages[] = "Found forbidden string: {$needle}";
        }
    }

    if ($messages !== []) {
        return fail($wrapper['name'], $case['id'], implode("\n", $messages) . "\n--- output ---\n" . trim($output));
    }

    return ['ok' => true, 'wrapper' => $wrapper['name'], 'case' => $case['id'], 'message' => ''];
}

function fail(string $wrapper, string $case, string $message): array
{
    return ['ok' => false, 'wrapper' => $wrapper, 'case' => $case, 'message' => $message];
}

function rrmdir(string $dir): void
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
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}
