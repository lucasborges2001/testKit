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

function Test-FileHasContent([string]$Path) {
    return (Test-Path -LiteralPath $Path -PathType Leaf) -and ((Get-Item -LiteralPath $Path).Length -gt 0)
}

if ([string]::IsNullOrWhiteSpace($Target) -or $Target -notmatch '^[A-Za-z0-9._-]+$') {
    throw 'Target must match ^[A-Za-z0-9._-]+$.'
}

$testkitRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$composeFile = Join-Path $testkitRoot 'compose.yaml'
if (-not (Test-Path -LiteralPath $composeFile -PathType Leaf)) {
    throw "Missing TestKit compose file: $composeFile"
}

$projectRoot = $env:TESTKIT_PROJECT_ROOT
if ([string]::IsNullOrWhiteSpace($projectRoot)) {
    $projectRoot = (Get-Location).Path
}
$projectRoot = [System.IO.Path]::GetFullPath($projectRoot)

$previousProjectRoot = $env:TESTKIT_PROJECT_ROOT
$previousTestkitRoot = $env:TESTKIT_ROOT
$previousNativePreference = $null
$hasNativePreference = $null -ne (Get-Variable -Name PSNativeCommandUseErrorActionPreference -ErrorAction SilentlyContinue)
if ($hasNativePreference) {
    $previousNativePreference = $PSNativeCommandUseErrorActionPreference
    $PSNativeCommandUseErrorActionPreference = $false
}
$env:TESTKIT_PROJECT_ROOT = $projectRoot
$env:TESTKIT_ROOT = $testkitRoot

try {
    $admissionArgs = @(
        'compose', '-f', $composeFile,
        'run', '--rm', '--no-deps', '-T',
        '-e', 'TESTKIT_WRAPPER_KIND=powershell',
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

    $admissionStdout = [System.IO.Path]::GetTempFileName()
    $admissionStderr = [System.IO.Path]::GetTempFileName()
    try {
        & docker @admissionArgs 1> $admissionStdout 2> $admissionStderr
        $admissionExit = $LASTEXITCODE
        $admissionLines = if (Test-Path -LiteralPath $admissionStdout -PathType Leaf) {
            @(Get-Content -LiteralPath $admissionStdout)
        } else {
            @()
        }
        $jsonLine = $admissionLines |
            ForEach-Object { [string]$_ } |
            Where-Object { $_.Trim().StartsWith('{') -and $_.Trim().EndsWith('}') } |
            Select-Object -Last 1

        if ([string]::IsNullOrWhiteSpace($jsonLine)) {
            Write-RemoteJson @{
                schema = 'testkit.remote-host-native-agent.v1'
                status = 'ERROR'
                code = 'invalid_admission_evidence'
                message = 'Admission container did not return JSON.'
                admission_exit_code = [int]$admissionExit
                admission_stderr_present = [bool](Test-FileHasContent $admissionStderr)
            } 2
        }
    } finally {
        Remove-Item -LiteralPath $admissionStdout -Force -ErrorAction SilentlyContinue
        Remove-Item -LiteralPath $admissionStderr -Force -ErrorAction SilentlyContinue
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

    $logDir = Join-Path $projectRoot '.testkit\remote-host-native'
    New-Item -ItemType Directory -Force $logDir | Out-Null
    $requestId = [string]$admission.request_id
    $stdoutFile = Join-Path $logDir ($requestId + '.stdout.log')
    $stderrFile = Join-Path $logDir ($requestId + '.stderr.log')
    Remove-Item -LiteralPath $stdoutFile -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $stderrFile -Force -ErrorAction SilentlyContinue

    & $powerShellExe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $scriptPath 1> $stdoutFile 2> $stderrFile
    $nativeExit = $LASTEXITCODE
    $stdoutPresent = Test-FileHasContent $stdoutFile
    $stderrPresent = Test-FileHasContent $stderrFile

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
            stdout_present = [bool]$stdoutPresent
            stderr_present = [bool]$stderrPresent
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
        stdout_present = [bool]$stdoutPresent
        stderr_present = [bool]$stderrPresent
        result_file = [string]$admission.host_native.result_file
        evidence = $evidence
    }
    Write-RemoteJson $payload $(if ($pass) { 0 } else { 1 })
} catch {
    Write-RemoteJson @{
        schema = 'testkit.remote-host-native-agent.v1'
        status = 'ERROR'
        code = 'bridge_exception'
        message = [string]$_.Exception.Message
    } 2
} finally {
    if ($hasNativePreference) {
        $PSNativeCommandUseErrorActionPreference = $previousNativePreference
    }
    if ([string]::IsNullOrWhiteSpace($previousProjectRoot)) {
        Remove-Item Env:TESTKIT_PROJECT_ROOT -ErrorAction SilentlyContinue
    } else {
        $env:TESTKIT_PROJECT_ROOT = $previousProjectRoot
    }
    if ([string]::IsNullOrWhiteSpace($previousTestkitRoot)) {
        Remove-Item Env:TESTKIT_ROOT -ErrorAction SilentlyContinue
    } else {
        $env:TESTKIT_ROOT = $previousTestkitRoot
    }
}
