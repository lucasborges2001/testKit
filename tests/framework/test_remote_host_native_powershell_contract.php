<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/bin/testkit-remote-host-native-agent.ps1';
$errors = [];
$assert = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};

$assert(is_file($path), 'PowerShell host-native remote agent entrypoint missing');
$source = is_file($path) ? (string)file_get_contents($path) : '';

foreach ([
    "'bin\\testkit.ps1'",
    "'/workspace/testkit/runners/runRemoteHostAgent.php'",
    "'--admit-only'",
    "'--json'",
    'TESTKIT_REMOTE_TARGET=$Target',
    "execution_backend -ne 'host_native'",
    "host_native.kind -ne 'powershell'",
    'Resolve-ProjectPath',
    'TESTKIT_PROJECT_ROOT',
    'GetFullPath',
    'OrdinalIgnoreCase',
    'host_native.script',
    'host_native.result_file',
    'Remove-Item -LiteralPath $resultPath',
    '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File $scriptPath',
    "schema = 'testkit.remote-host-native-agent.v1'",
    'evidence = $evidence',
    'stderr_present',
] as $fragment) {
    $assert(str_contains($source, $fragment), 'missing host-native bridge fragment: ' . $fragment);
}

foreach (['AllowDisposable', 'AllowNetwork', 'AllowPersistent', 'AllowHardware'] as $switch) {
    $assert(str_contains($source, $switch), 'missing local risk opt-in switch: ' . $switch);
}

$assert(!str_contains($source, 'Invoke-Expression'), 'host-native bridge must not use Invoke-Expression');
$assert(!preg_match('/(^|\s)iex\s+/im', $source), 'host-native bridge must not use iex');
$assert(!str_contains($source, 'ScriptBlock::Create'), 'host-native bridge must not construct script blocks dynamically');
$assert(!str_contains($source, 'Start-Job'), 'host-native bridge must execute synchronously');
$assert(!str_contains($source, 'Invoke-WebRequest'), 'host-native bridge must not fetch executable content');
$assert(!str_contains($source, 'Invoke-RestMethod'), 'host-native bridge must not fetch executable content');
$assert(!str_contains($source, 'git pull'), 'host-native bridge must not own Git synchronization');
$assert(!str_contains($source, 'git reset'), 'host-native bridge must not mutate Git state');
$assert(!str_contains($source, 'command'), 'host-native bridge must not accept an arbitrary remote command field');

if ($errors !== []) {
    fwrite(STDERR, "Remote host-native PowerShell contract failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "PASS remote_host_native_powershell admission=container host_execution=powershell allowlisted_paths=1 arbitrary_eval=0 git_sync=0\n";
