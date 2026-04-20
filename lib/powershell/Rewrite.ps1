function Get-TestkitShellKind {
  if ($env:TESTKIT_WRAPPER_KIND -in @('bash','powershell','direct')) { return $env:TESTKIT_WRAPPER_KIND }
  return 'powershell'
}

function Get-TestkitSuggestedRerunCommand([string]$Target,[string]$File) {
  switch (Get-TestkitShellKind) {
    'powershell' { return ".\bin\testkit.ps1 run --rm -e TEST_MATCH='$File' testkit php runTest.php $Target" }
    'bash' { return "./bin/testkit run --rm -e TEST_MATCH='$File' testkit php runTest.php $Target" }
    default { return "TEST_MATCH='$File' php runTest.php $Target" }
  }
}

function Get-TestkitSuggestedReportCommand {
  switch (Get-TestkitShellKind) {
    'powershell' { return '.\bin\testkit.ps1 run --rm testkit php scripts/report.php' }
    'bash' { return './bin/testkit run --rm testkit php scripts/report.php' }
    default { return 'php scripts/report.php' }
  }
}

function Convert-TestkitRunArgs([string[]]$InputArgs) {
  if (-not $InputArgs -or $InputArgs.Count -eq 0) { return ,$InputArgs }
  if ($InputArgs[0] -ne 'run') { return ,$InputArgs }

  $rewritten = New-Object System.Collections.Generic.List[string]
  $sawTestkit = $false
  foreach ($arg in $InputArgs) {
    if ($arg -eq 'testkit' -and -not $sawTestkit) {
      $sawTestkit = $true
      $rewritten.Add($arg) | Out-Null
      continue
    }

    if ($sawTestkit) {
      switch ($arg) {
        'runTest.php' { $rewritten.Add('/workspace/testkit/runTest.php') | Out-Null; continue }
        './runTest.php' { $rewritten.Add('/workspace/testkit/runTest.php') | Out-Null; continue }
        'scripts/report.php' { $rewritten.Add('/workspace/testkit/scripts/report.php') | Out-Null; continue }
        './scripts/report.php' { $rewritten.Add('/workspace/testkit/scripts/report.php') | Out-Null; continue }
        'scripts/inspect.php' { $rewritten.Add('/workspace/testkit/scripts/inspect.php') | Out-Null; continue }
      }
    }

    $rewritten.Add($arg) | Out-Null
  }
  return ,$rewritten.ToArray()
}
