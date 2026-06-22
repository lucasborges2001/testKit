function Write-TestkitDoctorCheck($Row) {
  Write-Host "[$($Row.Status)] $($Row.Code) - $($Row.Summary)"
  if (-not [string]::IsNullOrWhiteSpace($Row.Action)) {
    Write-Host "       Acción: $($Row.Action)"
  }
}

function Get-TestkitDoctorCounts([System.Collections.Generic.List[object]]$Rows) {
  $counts = @{
    PASS = 0
    WARN = 0
    UNKNOWN = 0
    FAIL = 0
  }
  foreach ($row in $Rows) {
    if ($counts.ContainsKey($row.Status)) {
      $counts[$row.Status]++
    }
  }
  return $counts
}

function Show-TestkitDoctorFull {
  param(
    [Parameter(Mandatory=$true)]$Context,
    [Parameter(Mandatory=$false)]$EnvFile,
    [Parameter(Mandatory=$true)][string]$StackCsv
  )

  Write-Host ""
  Write-Host "== TESTKIT DOCTOR =="
  Write-Host "[INFO] mode=$($Context.Mode)"
  Write-Host "[INFO] target=$(if ([string]::IsNullOrWhiteSpace($Context.Target)) { 'generic' } else { $Context.Target })"
  Write-Host "[INFO] TESTKIT_STACK=$StackCsv"
  Write-Host "[INFO] TESTKIT_ROOT(host)=$($script:ResolvedTestkitRoot.Path)"
  Write-Host "[INFO] TESTKIT_PROJECT_ROOT(host)=$($script:ProjectRoot.Path)"

  Write-Host ""
  Write-Host "== BASE CHECKS =="
  foreach ($row in $script:TestkitDoctorBaseChecks) {
    Write-TestkitDoctorCheck $row
  }
  Write-Host "Base doctor: $script:TestkitDoctorBaseStatus"

  if ($script:TestkitDoctorCapabilityChecks.Count -gt 0) {
    Write-Host ""
    Write-Host "== CAPABILITY DOCTOR =="
    foreach ($row in $script:TestkitDoctorCapabilityChecks) {
      Write-TestkitDoctorCheck $row
    }
    Write-Host "Capability doctor: $script:TestkitDoctorCapabilityStatus"
    Write-Host "Nota: capability no cambia el exit code del wrapper; el exit sigue atado al doctor base."
  }

  Write-Host ""
  if ($script:TestkitDoctorBaseStatus -ne 'FAIL') {
    Write-Host "Doctor: OK"
  } else {
    Write-Host "Doctor: FAIL (ver arriba)"
  }
}

function Show-TestkitDoctorCompact {
  param(
    [Parameter(Mandatory=$true)]$Context,
    [Parameter(Mandatory=$false)]$EnvFile,
    [Parameter(Mandatory=$true)][string]$StackCsv
  )

  Write-Host ""
  Write-Host "== TESTKIT DOCTOR =="
  $targetLabel = if ([string]::IsNullOrWhiteSpace($Context.Target)) { 'generic' } else { $Context.Target }
  Write-Host "[INFO] mode=$($Context.Mode) target=$targetLabel"

  $baseCounts = Get-TestkitDoctorCounts $script:TestkitDoctorBaseChecks
  Write-Host "Base: status=$script:TestkitDoctorBaseStatus pass=$($baseCounts.PASS) warn=$($baseCounts.WARN) unknown=$($baseCounts.UNKNOWN) fail=$($baseCounts.FAIL)"

  if ($script:TestkitDoctorCapabilityChecks.Count -gt 0) {
    $capCounts = Get-TestkitDoctorCounts $script:TestkitDoctorCapabilityChecks
    Write-Host "Capability: status=$script:TestkitDoctorCapabilityStatus pass=$($capCounts.PASS) warn=$($capCounts.WARN) unknown=$($capCounts.UNKNOWN) fail=$($capCounts.FAIL)"
  }

  Write-Host ""
  Write-Host "Problemas relevantes:"
  $printed = $false
  foreach ($row in $script:TestkitDoctorBaseChecks) {
    if ($row.Status -in @('FAIL','WARN')) {
      Write-TestkitDoctorCheck $row
      $printed = $true
    }
  }
  foreach ($row in $script:TestkitDoctorCapabilityChecks) {
    if ($row.Status -in @('FAIL','WARN','UNKNOWN')) {
      Write-TestkitDoctorCheck $row
      $printed = $true
    }
  }
  if (-not $printed) {
    Write-Host "[PASS] NO_RELEVANT_WARNINGS - no hay warnings/fails visibles en modo compacto."
  }

  Write-Host ""
  if ($script:TestkitDoctorBaseStatus -ne 'FAIL') {
    Write-Host "Doctor: OK"
  } else {
    Write-Host "Doctor: FAIL (ver arriba)"
  }
}

function Show-TestkitDoctorDump {
  param(
    [Parameter(Mandatory=$true)]$Context,
    [Parameter(Mandatory=$true)]$EnvFile,
    [Parameter(Mandatory=$true)][string]$StackCsv
  )

  Write-Host ""
  Write-Host "-- Effective TestKit config --"
  Write-Host "TESTKIT_DOCTOR_MODE: $($Context.Mode)"
  Write-Host "TESTKIT_DOCTOR_TARGET: $($Context.Target)"
  Write-Host "TESTKIT_STACK: $StackCsv"
  Write-Host "projectRoot: $($script:ProjectRoot.Path)"
  Write-Host "testkitRootHost: $($script:ResolvedTestkitRoot.Path)"
  Write-Host "envFile: $($EnvFile.Path)"
  Write-Host "DB_ENV_PATH(in-container): $($env:TESTKIT_DB_ENV_PATH)"
  Write-Host "TESTKIT_DOCTOR_BASE_STATUS: $script:TestkitDoctorBaseStatus"
  Write-Host "TESTKIT_CAPABILITY_STATUS: $script:TestkitDoctorCapabilityStatus"
  Write-Host ""

  Write-Host "TESTKIT_DOCTOR_BASE_CHECK_COUNT: $($script:TestkitDoctorBaseChecks.Count)"
  $i = 1
  foreach ($row in $script:TestkitDoctorBaseChecks) {
    Write-Host "TESTKIT_DOCTOR_BASE_CHECK_${i}_STATUS: $($row.Status)"
    Write-Host "TESTKIT_DOCTOR_BASE_CHECK_${i}_CODE: $($row.Code)"
    Write-Host "TESTKIT_DOCTOR_BASE_CHECK_${i}_SUMMARY: $($row.Summary)"
    Write-Host "TESTKIT_DOCTOR_BASE_CHECK_${i}_ACTION: $($row.Action)"
    $i++
  }

  Write-Host "TESTKIT_CAPABILITY_CHECK_COUNT: $($script:TestkitDoctorCapabilityChecks.Count)"
  $i = 1
  foreach ($row in $script:TestkitDoctorCapabilityChecks) {
    Write-Host "TESTKIT_CAPABILITY_CHECK_${i}_STATUS: $($row.Status)"
    Write-Host "TESTKIT_CAPABILITY_CHECK_${i}_CODE: $($row.Code)"
    Write-Host "TESTKIT_CAPABILITY_CHECK_${i}_SUMMARY: $($row.Summary)"
    Write-Host "TESTKIT_CAPABILITY_CHECK_${i}_ACTION: $($row.Action)"
    $i++
  }
}
