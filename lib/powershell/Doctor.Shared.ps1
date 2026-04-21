$script:TestkitDoctorBaseChecks = New-Object System.Collections.Generic.List[object]
$script:TestkitDoctorCapabilityChecks = New-Object System.Collections.Generic.List[object]
$script:TestkitDoctorBaseStatus = 'PASS'
$script:TestkitDoctorCapabilityStatus = 'PASS'

function Reset-TestkitDoctorState {
  $script:TestkitDoctorBaseChecks = New-Object System.Collections.Generic.List[object]
  $script:TestkitDoctorCapabilityChecks = New-Object System.Collections.Generic.List[object]
  $script:TestkitDoctorBaseStatus = 'PASS'
  $script:TestkitDoctorCapabilityStatus = 'PASS'
}

function Normalize-TestkitDoctorToken([string]$Raw) {
  if ([string]::IsNullOrWhiteSpace($Raw)) { return '' }
  return $Raw.Trim().ToLowerInvariant()
}

function Get-TestkitDoctorStatusRank([string]$Status) {
  switch ($Status) {
    'PASS' { return 1 }
    'WARN' { return 2 }
    'UNKNOWN' { return 3 }
    'FAIL' { return 4 }
    default { return 0 }
  }
}

function Update-TestkitDoctorStatus([string]$Scope, [string]$Status) {
  switch ($Scope) {
    'base' {
      if ((Get-TestkitDoctorStatusRank $Status) -gt (Get-TestkitDoctorStatusRank $script:TestkitDoctorBaseStatus)) {
        $script:TestkitDoctorBaseStatus = $Status
      }
    }
    'capability' {
      if ((Get-TestkitDoctorStatusRank $Status) -gt (Get-TestkitDoctorStatusRank $script:TestkitDoctorCapabilityStatus)) {
        $script:TestkitDoctorCapabilityStatus = $Status
      }
    }
  }
}

function Add-TestkitDoctorCheck(
  [string]$Scope,
  [string]$Status,
  [string]$Code,
  [string]$Summary,
  [string]$Action = ''
) {
  $row = [PSCustomObject]@{
    Status = $Status
    Code = $Code
    Summary = $Summary
    Action = $Action
  }

  Update-TestkitDoctorStatus $Scope $Status
  switch ($Scope) {
    'base' { $script:TestkitDoctorBaseChecks.Add($row) | Out-Null }
    'capability' { $script:TestkitDoctorCapabilityChecks.Add($row) | Out-Null }
  }
}

function Parse-TestkitDoctorArgs([string[]]$DoctorArgs) {
  $mode = Normalize-TestkitDoctorToken $env:TESTKIT_DOCTOR_MODE
  if ($mode -notin @('compact','full')) { $mode = 'full' }

  $target = ''
  $dump = $false
  $invalidArgs = New-Object System.Collections.Generic.List[string]

  foreach ($arg in $DoctorArgs) {
    switch -Regex ($arg) {
      '^--dump$' { $dump = $true; continue }
      '^--compact$' { $mode = 'compact'; continue }
      '^--full$' { $mode = 'full'; continue }
      '^--mode=compact$' { $mode = 'compact'; continue }
      '^--mode=full$' { $mode = 'full'; continue }
      '^--target=(.+)$' { $target = Normalize-TestkitDoctorToken $Matches[1]; continue }
      '^--' { $invalidArgs.Add($arg) | Out-Null; continue }
      default {
        if ([string]::IsNullOrWhiteSpace($target)) {
          $target = Normalize-TestkitDoctorToken $arg
        } else {
          $invalidArgs.Add($arg) | Out-Null
        }
      }
    }
  }

  return [PSCustomObject]@{
    Mode = $mode
    Target = $target
    Dump = $dump
    InvalidArgs = @($invalidArgs)
  }
}

function Test-TestkitDoctorEnvPresentAny([string[]]$Keys) {
  foreach ($key in $Keys) {
    $value = [Environment]::GetEnvironmentVariable($key)
    if (-not [string]::IsNullOrWhiteSpace($value)) { return $true }
  }
  return $false
}

function Test-TestkitDoctorSnapshotSourceVisible {
  return (Test-TestkitDoctorEnvPresentAny @(
    'TEST_BASELINE_SNAPSHOT_FILE',
    'TEST_BASELINE_SNAPSHOT_METADATA',
    'TEST_BASELINE_SNAPSHOT_REPORT',
    'TEST_BASELINE_SNAPSHOT_JSON'
  ))
}
