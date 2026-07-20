#Requires -Version 7.0
<#
Testkit PowerShell self-test runner.

Usage:  pwsh -File tests/powershell/run.ps1
Exit:   0 if all pass, 1 if any fail.

Each test file is a standalone PowerShell script that exits 0 on success,
non-zero on failure. Mirrors the layout and behavior of
tests/framework/run.php for the PHP self-tests.
#>
$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot

$tests = [ordered]@{
  'Path containment (Test-TestkitPathUnderRoot)'    = Join-Path $root 'test_path_containment.ps1'
  'Stack resolution (Convert-TestkitStack)'         = Join-Path $root 'test_stack_resolution.ps1'
  'Compose file selection (Get-TestkitComposeFiles)' = Join-Path $root 'test_compose_files.ps1'
  'Env file resolution and container path mapping'  = Join-Path $root 'test_env_resolution.ps1'
  'Run command rewrite (Convert-TestkitRunArgs)'    = Join-Path $root 'test_command_rewrite.ps1'
  'Doctor --readonly does not write to disk'        = Join-Path $root 'test_doctor_readonly.ps1'
  'External process exit code propagation'          = Join-Path $root 'test_exit_code_propagation.ps1'
}

$pass = 0
$fail = 0
$width = ($tests.Keys | ForEach-Object { $_.Length } | Measure-Object -Maximum).Maximum

Write-Host ('-' * ($width + 12))
Write-Host ' Testkit PowerShell self-tests'
Write-Host ('-' * ($width + 12))

foreach ($name in $tests.Keys) {
  $file = $tests[$name]

  if (-not (Test-Path $file)) {
    Write-Host ("  [SKIP] {0,-$width}  (file not found: {1})" -f $name, $file)
    continue
  }

  $output = & pwsh -NoProfile -NonInteractive -File $file 2>&1
  $exitCode = $LASTEXITCODE

  if ($exitCode -eq 0) {
    Write-Host ("  [PASS] {0}" -f $name)
    $pass++
  } else {
    Write-Host ("  [FAIL] {0}" -f $name)
    foreach ($line in $output) {
      Write-Host ("         {0}" -f $line)
    }
    $fail++
  }
}

Write-Host ('-' * ($width + 12))
Write-Host ("  {0} passed, {1} failed" -f $pass, $fail)
Write-Host ('-' * ($width + 12))

if ($fail -gt 0) { exit 1 }
exit 0
