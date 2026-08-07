$doctorShared = Join-Path $PSScriptRoot 'Doctor.Shared.ps1'
$doctorBase = Join-Path $PSScriptRoot 'Doctor.BaseChecks.ps1'
$doctorCapability = Join-Path $PSScriptRoot 'Doctor.CapabilityChecks.ps1'
$doctorContractRegistry = Join-Path $PSScriptRoot 'Doctor.ContractRegistry.ps1'
$doctorRender = Join-Path $PSScriptRoot 'Doctor.Render.ps1'

. $doctorShared
. $doctorBase
. $doctorCapability
. $doctorContractRegistry
. $doctorRender

function Test-TestkitDoctorStoreDriverContract {
  $driver = $env:TEST_STORE_DRIVER
  if ([string]::IsNullOrEmpty($driver)) {
    Write-Error '[TEST_STORE_DRIVER_REQUIRED] TEST_STORE_DRIVER es obligatorio. Valores exactos: mysql|pgsql|none.'
    return $false
  }
  if (@('mysql','pgsql','none') -cnotcontains $driver) {
    Write-Error "[TEST_STORE_DRIVER_INVALID] TEST_STORE_DRIVER='$driver' no es válido. Valores exactos: mysql|pgsql|none."
    return $false
  }
  return $true
}

function Invoke-TestkitDoctor([string[]]$DoctorArgs) {
  Reset-TestkitDoctorState

  $envFile = Get-TestkitEnvFile
  if ($envFile) {
    Import-TestkitEnvKV $envFile.Path
  }

  try {
    $context = Parse-TestkitDoctorArgs $DoctorArgs
  } catch {
    Write-Error $_
    return 1
  }

  if ($envFile -and -not (Test-TestkitDoctorStoreDriverContract)) {
    return 1
  }

  $stackCsv = Convert-TestkitStack $env:TESTKIT_STACK

  $ok = $true
  Invoke-TestkitDoctorBaseChecks -Context $context -EnvFile $envFile -StackCsv $stackCsv -Ok ([ref]$ok)
  if ($envFile) {
    Invoke-TestkitDoctorCapabilityChecks -Context $context
  }

  if ($context.Mode -eq 'compact') {
    Show-TestkitDoctorCompact -Context $context -EnvFile $envFile -StackCsv $stackCsv
  } else {
    Show-TestkitDoctorFull -Context $context -EnvFile $envFile -StackCsv $stackCsv
  }

  if ($context.Dump -and $envFile) {
    $env:TESTKIT_DB_ENV_PATH = Convert-TestkitEnvFileToContainerPath $envFile.Path
    $env:TESTKIT_PROJECT_ROOT = $script:ProjectRoot.Path
    $env:TESTKIT_ROOT = $script:ResolvedTestkitRoot.Path
    Show-TestkitDoctorDump -Context $context -EnvFile $envFile -StackCsv $stackCsv
  }

  if ($ok) { return 0 }
  return 1
}
