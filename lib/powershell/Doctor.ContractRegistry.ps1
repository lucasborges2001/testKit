function Invoke-TestkitContractRegistry([string[]]$Arguments) {
  $scriptPath = Join-Path $script:ResolvedTestkitRoot 'scripts\contract.php'
  $output = & php $scriptPath @Arguments 2>$null
  return [PSCustomObject]@{
    ExitCode = $LASTEXITCODE
    Output = (($output | ForEach-Object { [string]$_ }) -join "`n").Trim()
  }
}

function Assert-TestkitDoctorTarget([string]$Target) {
  if ([string]::IsNullOrWhiteSpace($Target)) { return }
  $result = Invoke-TestkitContractRegistry @('validate-target', $Target)
  if ($result.ExitCode -ne 0) {
    throw "doctor: target no soportado '$Target'. Consultá: php scripts/contract.php --json"
  }
}

function Test-TestkitDoctorKnownTarget([string]$Target) {
  if ([string]::IsNullOrWhiteSpace($Target)) { return $false }
  return (Invoke-TestkitContractRegistry @('validate-target', $Target)).ExitCode -eq 0
}

function Get-TestkitDoctorTargetKind([string]$Target) {
  if ([string]::IsNullOrWhiteSpace($Target)) { return 'unknown' }
  $result = Invoke-TestkitContractRegistry @('target-kind', $Target)
  if ($result.ExitCode -ne 0 -or [string]::IsNullOrWhiteSpace($result.Output)) { return 'unknown' }
  return $result.Output
}
