<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/bin/testkit-remote-host-agent.ps1';
$errors = [];
$assert = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};

$assert(is_file($path), 'PowerShell remote host agent entrypoint missing');
$source = is_file($path) ? (string)file_get_contents($path) : '';

foreach ([
    "'bin\\testkit.ps1'",
    "'run', '--rm'",
    "'testkit'",
    "'/workspace/testkit/runners/runRemoteHostAgent.php'",
    "'--json'",
    "'TESTKIT_PROJECT_ROOT'",
    '(Get-Location).Path',
] as $fragment) {
    $assert(str_contains($source, $fragment), 'missing PowerShell bridge fragment: ' . $fragment);
}

$assert(str_contains($source, 'TESTKIT_REMOTE_TARGET=$Target'), 'PowerShell bridge must pass the explicit local target into the container');
$assert(str_contains($source, "SetEnvironmentVariable('TESTKIT_PROJECT_ROOT', \$previousProjectRoot, 'Process')"), 'PowerShell bridge must restore TESTKIT_PROJECT_ROOT');
$assert(!preg_match('/(^|[;&|])\s*php(?:\.exe)?\s+/im', $source), 'PowerShell bridge must not require host PHP');
$assert(!str_contains($source, 'Invoke-Expression'), 'PowerShell bridge must not use Invoke-Expression');
$assert(!str_contains($source, 'iex '), 'PowerShell bridge must not use iex');
$assert(str_contains($source, 'AllowNetwork'), 'network opt-in switch missing');
$assert(str_contains($source, 'AllowHardware'), 'hardware opt-in switch missing');

if ($errors !== []) {
    fwrite(STDERR, "Remote host agent PowerShell contract failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "PASS remote_host_agent_powershell docker_testkit=1 project_root=host host_php=0 arbitrary_eval=0\n";
