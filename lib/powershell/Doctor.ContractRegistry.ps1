function Invoke-TestkitContractCli([string[]]$Arguments) {
  $script = Join-Path $script:ResolvedTestkitRoot 'scripts/contract.php'
  & php $script @Arguments
  return $LASTEXITCODE
}

function Assert-TestkitDoctorSelector([string]$Kind, [string]$Name) {
  if ([string]::IsNullOrWhiteSpace($Kind) -and [string]::IsNullOrWhiteSpace($Name)) { return }
  if ([string]::IsNullOrWhiteSpace($Kind) -or [string]::IsNullOrWhiteSpace($Name)) {
    throw 'doctor: selector incompleto; usá --suite, --group o --category.'
  }
  & php (Join-Path $script:ResolvedTestkitRoot 'scripts/contract.php') validate-selector $Kind $Name *> $null
  if ($LASTEXITCODE -ne 0) {
    throw "doctor: selector no soportado '$Kind`:$Name'. Consultá scripts/contract.php --json."
  }
}

function Test-TestkitDoctorKnownTarget([string]$Target) {
  try {
    Assert-TestkitDoctorSelector $script:TestkitDoctorSelectorKind $Target
    return $true
  } catch {
    return $false
  }
}

function Get-TestkitDoctorTargetKind([string]$Target) {
  switch ($script:TestkitDoctorSelectorKind) {
    'suite' { return 'suite' }
    'group' { return 'aggregate' }
    'category' { return 'category' }
    default { return 'unknown' }
  }
}

function Parse-TestkitDoctorArgs([string[]]$DoctorArgs) {
  $mode = Normalize-TestkitDoctorToken $env:TESTKIT_DOCTOR_MODE
  if ($mode -notin @('compact','full')) { $mode = 'full' }

  $target = ''
  $selectorKind = ''
  $dump = $false
  $readOnly = $false

  for ($i = 0; $i -lt $DoctorArgs.Count; $i++) {
    $arg = [string]$DoctorArgs[$i]
    switch -Regex ($arg) {
      '^--dump$' { $dump = $true; continue }
      '^--compact$' { $mode = 'compact'; continue }
      '^--full$' { $mode = 'full'; continue }
      '^--mode=compact$' { $mode = 'compact'; continue }
      '^--mode=full$' { $mode = 'full'; continue }
      '^--readonly$' { $readOnly = $true; continue }
      '^--(suite|group|category)=(.+)$' {
        if (-not [string]::IsNullOrWhiteSpace($selectorKind)) { throw 'doctor: declaraste más de un selector.' }
        $selectorKind = Normalize-TestkitDoctorToken $Matches[1]
        $target = Normalize-TestkitDoctorToken $Matches[2]
        continue
      }
      '^--(suite|group|category)$' {
        if (-not [string]::IsNullOrWhiteSpace($selectorKind)) { throw 'doctor: declaraste más de un selector.' }
        if (($i + 1) -ge $DoctorArgs.Count) { throw "doctor: $arg exige valor." }
        $selectorKind = Normalize-TestkitDoctorToken $Matches[1]
        $i++
        $target = Normalize-TestkitDoctorToken ([string]$DoctorArgs[$i])
        continue
      }
      '^--target(?:=|$)' { throw 'doctor: --target fue eliminado; usá --suite, --group o --category.' }
      '^--' { throw "doctor: opción no soportada '$arg'." }
      default { throw "doctor: no se aceptan selectores posicionales '$arg'." }
    }
  }

  $script:TestkitDoctorSelectorKind = $selectorKind
  Assert-TestkitDoctorSelector $selectorKind $target

  return [PSCustomObject]@{
    Mode = $mode
    Target = $target
    SelectorKind = $selectorKind
    Dump = $dump
    ReadOnly = $readOnly
  }
}
