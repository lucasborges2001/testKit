Param(
  [Parameter(ValueFromRemainingArguments=$true)]
  [Alias('Args')]
  [string[]]$CliArgs
)

$script:TestkitRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)

. (Join-Path $script:TestkitRoot 'lib\powershell\Env.ps1')
. (Join-Path $script:TestkitRoot 'lib\powershell\Stack.ps1')
. (Join-Path $script:TestkitRoot 'lib\powershell\Rewrite.ps1')
. (Join-Path $script:TestkitRoot 'lib\powershell\Doctor.ps1')
. (Join-Path $script:TestkitRoot 'lib\powershell\RuntimeCleanup.ps1')

Initialize-TestkitEnv

function Set-TestkitComposeProgress {
  if (-not [string]::IsNullOrWhiteSpace($env:COMPOSE_PROGRESS)) { return }
  if ($env:TESTKIT_CONSOLE_MODE -ne 'live') {
    $env:COMPOSE_PROGRESS = 'quiet'
  }
}

function Invoke-TestkitInspect([string]$EnvFilePath, [string[]]$InspectArgs) {
  $stackCsv = Convert-TestkitStack $env:TESTKIT_STACK
  $env:TESTKIT_DB_ENV_PATH = Convert-TestkitEnvFileToContainerPath $EnvFilePath
  $env:TESTKIT_PROJECT_ROOT = $script:ProjectRoot.Path
  $env:TESTKIT_ROOT = $script:ResolvedTestkitRoot.Path
  $files = Get-TestkitComposeFiles $stackCsv
  $cmd = @('compose','--env-file',$EnvFilePath) + $files + @('run','--rm','-e','TESTKIT_WRAPPER_KIND=powershell','testkit','php','/workspace/testkit/scripts/inspect.php') + $InspectArgs
  & docker @cmd
  return $LASTEXITCODE
}

function Invoke-TestkitCleanup([string]$EnvFilePath, [string[]]$CleanupArgs) {
  if ($CleanupArgs.Count -gt 0 -and $CleanupArgs[0] -eq 'runtime') {
    $runtimeArgs = if ($CleanupArgs.Count -gt 1) { @($CleanupArgs[1..($CleanupArgs.Count-1)]) } else { @() }
    $runtimeStatus = Invoke-TestkitRuntimeCleanup $EnvFilePath $runtimeArgs
    return $runtimeStatus
  }

  $stackCsv = Convert-TestkitStack $env:TESTKIT_STACK
  $env:TESTKIT_DB_ENV_PATH = Convert-TestkitEnvFileToContainerPath $EnvFilePath
  $env:TESTKIT_PROJECT_ROOT = $script:ProjectRoot.Path
  $env:TESTKIT_ROOT = $script:ResolvedTestkitRoot.Path
  $files = Get-TestkitComposeFiles $stackCsv
  $cmd = @('compose','--env-file',$EnvFilePath) + $files + @('run','--rm','-e','TESTKIT_WRAPPER_KIND=powershell','testkit','php','/workspace/testkit/scripts/cleanup.php') + $CleanupArgs
  & docker @cmd
  return $LASTEXITCODE
}

function Write-TestkitResetUsage {
  Write-Output @'
Usage:
  testkit.ps1 reset [--hard] [--json]

reset:
  Removes TestKit containers/orphans and purges reports, profiling shards,
  coverage and stale locks. Preserves Docker volumes, history, active locks
  and baselines.

reset --hard:
  Also removes Docker volumes, TestKit history and all TestKit locks.
  Baselines remain preserved.
'@
}

function Invoke-TestkitReset([string]$EnvFilePath, [string[]]$ResetArgs) {
  $hard = $false
  $forwardArgs = New-Object System.Collections.Generic.List[string]
  foreach ($arg in @($ResetArgs)) {
    switch ($arg) {
      '--hard' {
        $hard = $true
        $forwardArgs.Add('--hard') | Out-Null
      }
      '--json' {
        $forwardArgs.Add('--json') | Out-Null
      }
      '--help' {
        Write-TestkitResetUsage
        return 0
      }
      '-h' {
        Write-TestkitResetUsage
        return 0
      }
      default {
        Write-Error "reset: argumento no reconocido: $arg"
        Write-TestkitResetUsage
        return 2
      }
    }
  }

  $stackCsv = Convert-TestkitStack $env:TESTKIT_STACK
  $env:TESTKIT_DB_ENV_PATH = Convert-TestkitEnvFileToContainerPath $EnvFilePath
  $env:TESTKIT_PROJECT_ROOT = $script:ProjectRoot.Path
  $env:TESTKIT_ROOT = $script:ResolvedTestkitRoot.Path
  $files = Get-TestkitComposeFiles $stackCsv

  $downCmd = @('compose','--env-file',$EnvFilePath) + $files + @('down')
  if ($hard) {
    $downCmd += '-v'
  }
  $downCmd += '--remove-orphans'
  & docker @downCmd
  $downStatus = $LASTEXITCODE
  if ($downStatus -ne 0) {
    return $downStatus
  }

  $passthrough = @()
  foreach ($key in @('TEST_COVERAGE_DIR','TEST_COVERAGE_ROOT')) {
    $value = [Environment]::GetEnvironmentVariable($key)
    if (-not [string]::IsNullOrWhiteSpace($value)) {
      $passthrough += @('-e', $key)
    }
  }

  $resetCmd = @('compose','--env-file',$EnvFilePath) + $files + @(
    'run','--rm','--no-deps',
    '-e','TESTKIT_WRAPPER_KIND=powershell',
    '-e','TESTKIT_RESET_CONTAINERS_STOPPED=1'
  ) + $passthrough + @(
    'testkit','php','/workspace/testkit/scripts/reset.php'
  ) + $forwardArgs.ToArray()

  & docker @resetCmd
  $resetStatus = $LASTEXITCODE

  $finalDownCmd = @('compose','--env-file',$EnvFilePath) + $files + @('down','--remove-orphans')
  & docker @finalDownCmd
  $finalStatus = $LASTEXITCODE

  if ($resetStatus -ne 0) {
    return $resetStatus
  }
  return $finalStatus
}

function Invoke-TestkitRuntime([string]$EnvFilePath, [string[]]$RuntimeCliArgs) {
  $legacyPg = $false
  $runtimeArgs = @($RuntimeCliArgs)
  if ($runtimeArgs.Count -gt 0 -and $runtimeArgs[0] -eq '--pg') {
    $legacyPg = $true
    $runtimeArgs = if ($runtimeArgs.Count -gt 1) { @($runtimeArgs[1..($runtimeArgs.Count-1)]) } else { @() }
  }
  $stackCsv = Convert-TestkitStack $env:TESTKIT_STACK
  if ($legacyPg -and -not (Test-TestkitStackHas $stackCsv 'pg')) {
    $stackCsv = if ([string]::IsNullOrWhiteSpace($stackCsv)) { 'pg' } else { "$stackCsv,pg" }
  }
  $env:TESTKIT_DB_ENV_PATH = Convert-TestkitEnvFileToContainerPath $EnvFilePath
  $env:TESTKIT_PROJECT_ROOT = $script:ProjectRoot.Path
  $env:TESTKIT_ROOT = $script:ResolvedTestkitRoot.Path
  $files = Get-TestkitComposeFiles $stackCsv
  $runArgs = Convert-TestkitRunArgs $runtimeArgs
  if ($runArgs.Count -gt 0 -and $runArgs[0] -eq 'run') {
    $head = @($runArgs[0..0])
    $tail = if ($runArgs.Count -gt 1) { @($runArgs[1..($runArgs.Count-1)]) } else { @() }
    $cmd = @('compose','--env-file',$EnvFilePath) + $files + $head + @('-e','TESTKIT_WRAPPER_KIND=powershell') + $tail
  } else {
    $cmd = @('compose','--env-file',$EnvFilePath) + $files + $runArgs
  }
  & docker @cmd
  return $LASTEXITCODE
}

if ($CliArgs.Count -gt 0 -and $CliArgs[0] -eq 'doctor') {
  $doctorArgs = if ($CliArgs.Count -gt 1) { @($CliArgs[1..($CliArgs.Count-1)]) } else { @() }
  exit (Invoke-TestkitDoctor $doctorArgs)
}

$envFile = Get-TestkitEnvFile
if (-not $envFile) {
  Write-Error 'Falta env de tests. Creá <project>/test/.env.test o <project>/.env.test.'
  exit 1
}
if (-not (Test-TestkitPathUnderRoot $script:ProjectRoot $envFile.Path)) {
  Write-Error "El env de tests quedó fuera del repo montado: $($envFile.Path)"
  exit 1
}

Import-TestkitEnvKV $envFile.Path
Set-TestkitComposeProgress

if ($CliArgs.Count -gt 0 -and $CliArgs[0] -eq 'inspect') {
  $inspectArgs = if ($CliArgs.Count -gt 1) { @($CliArgs[1..($CliArgs.Count-1)]) } else { @() }
  exit (Invoke-TestkitInspect $envFile.Path $inspectArgs)
}
if ($CliArgs.Count -gt 0 -and $CliArgs[0] -eq 'cleanup') {
  $cleanupArgs = if ($CliArgs.Count -gt 1) { @($CliArgs[1..($CliArgs.Count-1)]) } else { @() }
  exit (Invoke-TestkitCleanup $envFile.Path $cleanupArgs)
}
if ($CliArgs.Count -gt 0 -and $CliArgs[0] -eq 'reset') {
  $resetArgs = if ($CliArgs.Count -gt 1) { @($CliArgs[1..($CliArgs.Count-1)]) } else { @() }
  exit (Invoke-TestkitReset $envFile.Path $resetArgs)
}

$commandName = if ($CliArgs.Count -gt 0) { $CliArgs[0] } else { '' }
$autoCleanupStatus = Invoke-TestkitRuntimeAutoCleanup $envFile.Path $commandName
if ($autoCleanupStatus -ne 0) { exit $autoCleanupStatus }
exit (Invoke-TestkitRuntime $envFile.Path $CliArgs)
