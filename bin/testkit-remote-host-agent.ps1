[CmdletBinding()]
param(
    [Parameter(Position = 0, Mandatory = $true)]
    [string]$ConfigPath,

    [Parameter(Position = 1, Mandatory = $true)]
    [string]$RequestPath,

    [string]$Target = $env:COMPUTERNAME,

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

$testkitRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$native = Join-Path $testkitRoot 'bin\testkit.ps1'
if (-not (Test-Path -LiteralPath $native -PathType Leaf)) {
    throw "Missing TestKit PowerShell entrypoint: $native"
}

$runnerArgs = @(
    'run', '--rm',
    'testkit',
    'env', "TESTKIT_REMOTE_TARGET=$Target",
    'php', '/workspace/testkit/runners/runRemoteHostAgent.php',
    $ConfigPath,
    $RequestPath,
    '--json'
)

if ($AllowDisposable) { $runnerArgs += '--allow-disposable' }
if ($AllowNetwork) { $runnerArgs += '--allow-network' }
if ($AllowPersistent) { $runnerArgs += '--allow-persistent' }
if ($AllowHardware) { $runnerArgs += '--allow-hardware' }

& $native -CliArgs $runnerArgs
exit $LASTEXITCODE
