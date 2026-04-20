function Invoke-TestkitDoctor {
  Write-Host ''
  Write-Host '== TESTKIT DOCTOR =='

  $envFile = Get-TestkitEnvFile
  $ok = $true
  if ($envFile) {
    Write-Host "[OK] env: $envFile"
    Import-TestkitEnvKV $envFile.Path
  } else {
    Write-Host '[FAIL] falta env de tests: test/.env.test (preferido) o .env.test (root).'
    $ok = $false
  }

  $stackCsv = Convert-TestkitStack $env:TESTKIT_STACK
  Write-Host "[INFO] TESTKIT_STACK=$stackCsv"
  Write-Host "[INFO] TESTKIT_ROOT(host)=$script:ResolvedTestkitRoot"
  Write-Host "[INFO] TESTKIT_PROJECT_ROOT(host)=$script:ProjectRoot"

  if (-not (Test-Path (Join-Path $script:ResolvedTestkitRoot 'runTest.php'))) { Write-Host '[FAIL] TESTKIT_ROOT no parece repo completo'; $ok = $false }
  if (-not (Test-Path $script:ProjectRoot)) { Write-Host '[FAIL] TESTKIT_PROJECT_ROOT no existe'; $ok = $false }

  if ($ok) { Write-Host "`nDoctor: OK"; return 0 }
  Write-Host "`nDoctor: FAIL (ver arriba)"
  return 1
}
