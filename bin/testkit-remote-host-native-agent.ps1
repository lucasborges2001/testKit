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

function Write-RemoteJson([hashtable]$Payload, [int]$ExitCode) {
    $Payload | ConvertTo-Json -Depth 50 -Compress | Write-Output
    exit $ExitCode
}

function Resolve-ProjectPath([string]$ProjectRoot, [string]$RelativePath, [bool]$MustExist) {
    if ([string]::IsNullOrWhiteSpace($RelativePath)) {
        throw 'Host-native relative path is empty.'
    }

    $rootFull = [System.IO.Path]::GetFullPath($ProjectRoot)
    $candidate = [System.IO.Path]::GetFullPath((Join-Path $rootFull $RelativePath))
    $rootPrefix = $rootFull.TrimEnd([System.IO.Path]::DirectorySeparatorChar, [System.IO.Path]::AltDirectorySeparatorChar) + [System.IO.Path]::DirectorySeparatorChar

    if (-not $candidate.StartsWith($rootPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Host-native path escapes TESTKIT_PROJECT_ROOT: $RelativePath"
    }
    if ($MustExist -and -not (Test-Path -LiteralPath $candidate -PathType Leaf)) {
        throw "Host-native file missing: $RelativePath"
    }
    return $candidate
}

if ([string]::IsNullOrWhiteSpace($Target) -or $Target -notmatch '^[A-Za-z0-9._-]+$') {
    throw 'Target must match ^[A-Za-z0-9._-]+$.'
}

$testkitRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$dockerBridge = Join-Path $testkitRoot 'bin\testkit.ps1'
if (-not (Test-Path -LiteralPath $dockerBridge -PathType Leaf)) {
    throw "Missing TestKit PowerShell entrypoint: $dockerBridge"
}

$projectRoot = $env:TESTKIT_PROJECT_ROOT
if ([string]::IsNullOrWhiteSpace($projectRoot)) {
    $projectRoot = (Get-Location).Path
}
$projectRoot = [System.IO.Path]::GetFullPath($projectRoot)

$previousProjectRoot = $env:TESTKIT_PROJECT_ROOT
$env:TESTKIT_PROJECT_ROOT = $projectRoot

try {
    $admissionArgs = @(
        'run', '--rm',
        'testkit',
        'env', "TESTKIT_REMOTE_TARGET=$Target",
        'php', '/workspace/testkit/runners/runRemoteHostAgent.php',
        $ConfigPath,
        $RequestPath,
        '--json',
        '--admit-only'
    )
    if ($AllowDisposable) { $admissionArgs += '--allow-disposable' }
    if ($AllowNetwork) { $admissionArgs += '--allow-network' }
    if ($AllowPersistent) { $admissionArgs += '--allow-persistent' }
    if ($AllowHardware) { $admissionArgs += '--allow-hardware' }

    $admissionLines = @(& $dockerBridge -CliArgs $admissionArgs 2>&1)
    $admissionExit = $LASTEXITCODE
    $jsonLine = $admissionLines |
        ForEach-Object { [string]$_ } |
        Where-Object { $_.Trim().StartsWith('{') -and $_.Trim().EndsWith('}') } |
        Select-Object -Last 1

    if ([string]::IsNullOrWhiteSpace($jsonLine)) {
        Write-RemoteJson @{
            schema = 'testkit.remote-host-native-agent.v1'
            status = 'ERROR'
            code = 'invalid_admission_evidence'
            message = 'Admission bridge did not return JSON.'
        } 2
    }

    $admission = $jsonLine | ConvertFrom-Json
    if ($admissionExit -ne 0 -or $admission.status -ne 'ADMITTED') {
        Write-RemoteJson @{
            schema = 'testkit.remote-host-native-agent.v1'
            status = 'ERROR'
            code = 'admission_rejected'
            admission = $admission
        } 2
    }
    if ($admission.execution_backend -ne 'host_native') {
        Write-RemoteJson @{
            schema = 'testkit.remote-host-native-agent.v1'
            status = 'ERROR'
            code = 'backend_mismatch'
            message = 'Selected suite is not host_native.'
            admission = $admission
        } 2
    }
    if ($null -eq $admission.host_native -or $admission.host_native.kind -ne 'powershell') {
        Write-RemoteJson @{
            schema = 'testkit.remote-host-native-agent.v1'
            status = 'ERROR'
            code = 'unsupported_host_native_kind'
            admission = $admission
        } 2
    }

    $scriptPath = Resolve-ProjectPath $projectRoot ([string]$admission.host_native.script) $true
    $resultPath = Resolve-ProjectPath $projectRoot ([string]$admission.host_native.result_file) $false
    $resultParent = Split-Path -Parent $resultPath
    New-Item -ItemType Directory -Force $resultParent | Out-Null
    Remove-Item -LiteralPath $resultPath -Force -ErrorAction SilentlyContinue

    $powerShellExe = (Get-Process -Id $PID).Path
    if ([string]::IsNullOrWhiteSpace($powerShellExe) -or -not (Test-Path -LiteralPath $powerShellExe -PathType Leaf)) {
        throw 'Unable to resolve current PowerShell executable.'
    }

    $stderrFile = [System.IO.Path]::GetTempFileName()
    try {
        & $powerShellExe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $scriptPath 2> $stderrFile | Out-Host
        $nativeExit = $LASTEXITCODE
        $stderrPresent = (Test-Path -LiteralPath $stderrFile) -and ((Get-Item -LiteralPath $stderrFile).Length -gt 0)
    } finally {
        Remove-Item -LiteralPath $stderrFile -Force -ErrorAction SilentlyContinue
    }

    if (-not (Test-Path -LiteralPath $resultPath -PathType Leaf)) {
        Write-RemoteJson @{
            schema = 'testkit.remote-host-native-agent.v1'
            status = 'ERROR'
            code = 'missing_result_file'
            request_id = [string]$admission.request_id
            target = [string]$admission.target
            suite = [string]$admission.suite
            execution_backend = 'host_native'
            exit_code = $nativeExit
        } 2
    }

    $evidence = Get-Content -LiteralPath $resultPath -Raw | ConvertFrom-Json
    $pass = ($nativeExit -eq 0 -and [string]$evidence.status -eq 'PASS')
    $payload = @{
        schema = 'testkit.remote-host-native-agent.v1'
        status = $(if ($pass) { 'PASS' } else { 'FAIL' })
        request_id = [string]$admission.request_id
        target = [string]$admission.target
        suite = [string]$admission.suite
        risk = [string]$admission.risk
        requires = @($admission.requires)
        execution_backend = 'host_native'
        exit_code = $nativeExit
        stderr_present = [bool]$stderrPresent
        result_file = [string]$admission.host_native.result_file
        evidence = $evidence
    }
    Write-RemoteJson $payload $(if ($pass) { 0 } else { 1 })
} finally {
    if ([string]::IsNullOrWhiteSpace($previousProjectRoot)) {
        Remove-Item Env:TESTKIT_PROJECT_ROOT -ErrorAction SilentlyContinue
    } else {
        $env:TESTKIT_PROJECT_ROOT = $previousProjectRoot
    }
}
