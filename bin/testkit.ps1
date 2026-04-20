Param(
  [Parameter(ValueFromRemainingArguments=$true)]
  [string[]]$Args
)

$script:TestkitRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)

. (Join-Path $script:TestkitRoot 'lib\powershell\Env.ps1')
. (Join-Path $script:TestkitRoot 'lib\powershell\Stack.ps1')
. (Join-Path $script:TestkitRoot 'lib\powershell\Rewrite.ps1')
. (Join-Path $script:TestkitRoot 'lib\powershell\Doctor.ps1')

Initialize-TestkitEnv

function Invoke-TestkitInspect([string]$EnvFilePath, [string[]]$InspectArgs) {
  $stackCsv = Convert-TestkitStack $env:TESTKIT_STACK
  $env:TESTKIT_DB_ENV_PATH = Convert-TestkitEnvFileToContainerPath $EnvFilePath
  $env:TESTKIT_PROJECT_ROOT = $script:ProjectRoot.Path
  $env:TESTKIT_ROOT = $script:ResolvedTestkitRoot.Path

  $files = Get-TestkitComposeFiles $stackCsv
  $cmd = @('compose','--env-file',$EnvFilePath) + $files + @(
    'run','--rm',
    '-e','TESTKIT_WRAPPER_KIND=powershell',
    'testkit','php','/workspace/testkit/scripts/inspect.php'
  ) + $InspectArgs

  & docker @cmd
  return $LASTEXITCODE
}

function Invoke-TestkitRuntime([string]$EnvFilePath, [string[]]$CliArgs) {
  $legacyPg = $false
  $runtimeArgs = @($CliArgs)
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
    $cmd = @('compose','--env-file',$EnvFilePath) + $files + $head + @(
      '-e','TESTKIT_WRAPPER_KIND=powershell'
    ) + $tail
  } else {
    $cmd = @('compose','--env-file',$EnvFilePath) + $files + $runArgs
  }

  & docker @cmd
  return $LASTEXITCODE
}

if ($Args.Count -gt 0 -and $Args[0] -eq 'doctor') {
  $doctorArgs = if ($Args.Count -gt 1) { @($Args[1..($Args.Count-1)]) } else { @() }
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

if ($Args.Count -gt 0 -and $Args[0] -eq 'inspect') {
  $inspectArgs = if ($Args.Count -gt 1) { @($Args[1..($Args.Count-1)]) } else { @() }
  exit (Invoke-TestkitInspect $envFile.Path $inspectArgs)
}

exit (Invoke-TestkitRuntime $envFile.Path $Args)
