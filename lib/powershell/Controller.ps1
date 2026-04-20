function Invoke-TestkitMain([string[]]$CliArgs) {
  Initialize-TestkitEnv

  if ($CliArgs.Count -gt 0 -and $CliArgs[0] -eq 'doctor') {
    return (Invoke-TestkitDoctor)
  }

  $envFile = Get-TestkitEnvFile
  if (-not $envFile) {
    Write-Error 'Falta env de tests. Creá <project>/test/.env.test o <project>/.env.test.'
    return 1
  }

  if (-not (Test-TestkitPathUnderRoot $script:ProjectRoot $envFile.Path)) {
    Write-Error "El env de tests quedó fuera del repo montado: $($envFile.Path)"
    return 1
  }

  Import-TestkitEnvKV $envFile.Path

  $stackCsv = Convert-TestkitStack $env:TESTKIT_STACK
  $env:TESTKIT_DB_ENV_PATH = Convert-TestkitEnvFileToContainerPath $envFile.Path
  $env:TESTKIT_PROJECT_ROOT = $script:ProjectRoot.Path
  $env:TESTKIT_ROOT = $script:ResolvedTestkitRoot.Path

  $files = Get-TestkitComposeFiles $stackCsv

  if ($CliArgs.Count -gt 0 -and $CliArgs[0] -eq 'inspect') {
    $inspectArgs = if ($CliArgs.Count -gt 1) { $CliArgs[1..($CliArgs.Count-1)] } else { @() }
    $cmd = @('compose','--env-file',$envFile) + $files + @('run','--rm','-e','TESTKIT_WRAPPER_KIND=powershell','testkit','php','/workspace/testkit/scripts/inspect.php') + $inspectArgs
    if ($env:TESTKIT_DEBUG_WRAPPER -eq '1') {
      Write-Host ('[testkit] exec: docker ' + (($cmd | ForEach-Object { $_.ToString() }) -join ' '))
    }
    & docker @cmd
    return $LASTEXITCODE
  }

  $runArgs = Convert-TestkitRunArgs $CliArgs
  $cmd = @('compose','--env-file',$envFile) + $files + $runArgs
  if ($env:TESTKIT_DEBUG_WRAPPER -eq '1') {
    Write-Host ('[testkit] exec: docker ' + (($cmd | ForEach-Object { $_.ToString() }) -join ' '))
  }
  & docker @cmd
  return $LASTEXITCODE
}
