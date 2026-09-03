[CmdletBinding()]
param(
    [Parameter(Position = 0, Mandatory = $true)]
    [string]$ConfigPath,

    [Parameter(Position = 1, Mandatory = $true)]
    [string]$RequestPath,

    [string]$Target = $env:COMPUTERNAME,
    [string]$StackOverride = '',

    [switch]$AllowDisposable,
    [switch]$AllowNetwork,
    [switch]$AllowPersistent,
    [switch]$AllowHardware
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($Target) -or $Target -notmatch '^[A-Za-z0-9._-]+$') {
    throw 'Target must match ^[A-Za-z0-9._-]+$.'
}
if (-not [string]::IsNullOrWhiteSpace($StackOverride)
    -and $StackOverride -notmatch '^(mysql|redis|pg|influx)(,(mysql|redis|pg|influx))*$') {
    throw 'StackOverride contains an unsupported TestKit stack.'
}

$testkitRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$native = Join-Path $testkitRoot 'bin\testkit.ps1'
if (-not (Test-Path -LiteralPath $native -PathType Leaf)) {
    throw "Missing TestKit PowerShell entrypoint: $native"
}

$previousProjectRoot = $env:TESTKIT_PROJECT_ROOT
$previousStackOverride = $env:TESTKIT_STACK_OVERRIDE
if ([string]::IsNullOrWhiteSpace($previousProjectRoot)) {
    $env:TESTKIT_PROJECT_ROOT = (Get-Location).Path
}
if (-not [string]::IsNullOrWhiteSpace($StackOverride)) {
    $env:TESTKIT_STACK_OVERRIDE = $StackOverride
}

$runnerArgs = @(
    'run', '--rm',
    'testkit',
    'env', "TESTKIT_REMOTE_TARGET=$Target",
    'php', '/workspace/testkit/runners/runRemoteHostAgentCompat.php',
    $ConfigPath,
    $RequestPath,
    '--json'
)

if ($AllowDisposable) { $runnerArgs += '--allow-disposable' }
if ($AllowNetwork) { $runnerArgs += '--allow-network' }
if ($AllowPersistent) { $runnerArgs += '--allow-persistent' }
if ($AllowHardware) { $runnerArgs += '--allow-hardware' }

try {
    & $native -CliArgs $runnerArgs
    $exitCode = $LASTEXITCODE
} finally {
    if ([string]::IsNullOrWhiteSpace($previousProjectRoot)) {
        Remove-Item Env:TESTKIT_PROJECT_ROOT -ErrorAction SilentlyContinue
    } else {
        $env:TESTKIT_PROJECT_ROOT = $previousProjectRoot
    }
    if ([string]::IsNullOrWhiteSpace($previousStackOverride)) {
        Remove-Item Env:TESTKIT_STACK_OVERRIDE -ErrorAction SilentlyContinue
    } else {
        $env:TESTKIT_STACK_OVERRIDE = $previousStackOverride
    }
}
exit $exitCode
