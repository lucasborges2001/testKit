<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/bin/testkit-remote-host-agent.ps1';
$envPath = $root . '/lib/powershell/Env.ps1';
$errors = [];
$assert = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};

$assert(is_file($path), 'PowerShell remote host agent entrypoint missing');
$assert(is_file($envPath), 'PowerShell env loader missing');
$source = is_file($path) ? (string)file_get_contents($path) : '';
$envSource = is_file($envPath) ? (string)file_get_contents($envPath) : '';

foreach ([
    "'bin\\testkit.ps1'",
    "'run', '--rm'",
    "'testkit'",
    "'/workspace/testkit/runners/runRemoteHostAgentCompat.php'",
    "'--json'",
    'TESTKIT_PROJECT_ROOT',
    '(Get-Location).Path',
    '$previousProjectRoot = $env:TESTKIT_PROJECT_ROOT',
    'finally {',
    'Remove-Item Env:TESTKIT_PROJECT_ROOT',
    '$env:TESTKIT_PROJECT_ROOT = $previousProjectRoot',
    '[string]$StackOverride',
    'TESTKIT_STACK_OVERRIDE',
    '$previousStackOverride',
    '$cleanupExitCode',
    "@('down', '-v', '--remove-orphans')",
    '$mysqlOverrides = [ordered]@{',
    "@('-e', (\"{0}={1}\" -f \$mysqlOverrides[\$source], \$value))",
] as $fragment) {
    $assert(str_contains($source, $fragment), 'missing PowerShell bridge fragment: ' . $fragment);
}

$assert(str_contains($source, 'TESTKIT_REMOTE_TARGET=$Target'), 'PowerShell bridge must pass the explicit local target into the container');
$assert(!preg_match('/(^|[;&|])\s*php(?:\.exe)?\s+/im', $source), 'PowerShell bridge must not require host PHP');
$assert(!str_contains($source, 'Invoke-Expression'), 'PowerShell bridge must not use Invoke-Expression');
$assert(!str_contains($source, 'iex '), 'PowerShell bridge must not use iex');
$assert(!preg_match('/\R\s+-and\s+/', $source), 'PowerShell binary -and operator must not begin a continuation line');
$assert(str_contains($source, 'AllowDisposable'), 'disposable opt-in switch missing');
$assert(str_contains($source, 'AllowNetwork'), 'network opt-in switch missing');
$assert(str_contains($source, 'AllowHardware'), 'hardware opt-in switch missing');
$assert(str_contains($source, "'^(mysql|redis|pg|influx)(,(mysql|redis|pg|influx))*$'"), 'stack override allowlist missing');
$assert(str_contains($source, '$AllowDisposable -and -not [string]::IsNullOrWhiteSpace($StackOverride)'), 'disposable cleanup must require explicit opt-in and stack override');
foreach ([
    'TESTKIT_STACK_OVERRIDE',
    'TESTKIT_MYSQL_ROOT_PASSWORD_OVERRIDE',
    'TESTKIT_MYSQL_DB_OVERRIDE',
    'TESTKIT_MYSQL_USER_OVERRIDE',
    'TESTKIT_MYSQL_PASSWORD_OVERRIDE',
] as $override) {
    $assert(str_contains($envSource, $override) || str_contains($source, $override), 'remote bridge/env loader missing explicit override: ' . $override);
}
foreach ([
    "'TESTKIT_MYSQL_ROOT_PASSWORD_OVERRIDE' = 'TEST_MYSQL_ROOT_PASSWORD'",
    "'TESTKIT_MYSQL_DB_OVERRIDE' = 'TEST_MYSQL_DB'",
    "'TESTKIT_MYSQL_USER_OVERRIDE' = 'TEST_MYSQL_USER'",
    "'TESTKIT_MYSQL_PASSWORD_OVERRIDE' = 'TEST_MYSQL_PASSWORD'",
] as $mapping) {
    $assert(str_contains($envSource, $mapping), 'env loader MySQL override mapping missing: ' . $mapping);
    $assert(str_contains($source, $mapping), 'bridge MySQL passthrough mapping missing: ' . $mapping);
}

if ($errors !== []) {
    fwrite(STDERR, "Remote host agent PowerShell contract failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "PASS remote_host_agent_powershell docker_testkit=1 compat_bridge=1 project_root_restore=1 powershell_continuation=1 stack_override=1 mysql_env_override=1 mysql_container_passthrough=1 disposable_opt_in=1 disposable_cleanup=1 host_php=0 arbitrary_eval=0\n";
