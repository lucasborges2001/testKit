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

function Get-TestkitSuggestedListCommand([string]$Target) {
  switch (Get-TestkitShellKind) {
    'powershell' { return ".\bin\testkit.ps1 run --rm testkit php runTest.php $Target --list" }
    'bash' { return "./bin/testkit run --rm testkit php runTest.php $Target --list" }
    default { return "php runTest.php $Target --list" }
  }
}

function Get-TestkitSuggestedTraceCommand([string]$Target) {
  switch (Get-TestkitShellKind) {
    'powershell' { return ".\bin\testkit.ps1 run --rm -e TESTKIT_TRACE_MIGRATIONS=1 testkit php runTest.php $Target" }
    'bash' { return "./bin/testkit run --rm -e TESTKIT_TRACE_MIGRATIONS=1 testkit php runTest.php $Target" }
    default { return "TESTKIT_TRACE_MIGRATIONS=1 php runTest.php $Target" }
  }
}

function Convert-TestkitRunArgs([string[]]$InputArgs) {
  if (-not $InputArgs -or $InputArgs.Count -eq 0) { return ,$InputArgs }
  if ($InputArgs[0] -ne 'run') { return ,$InputArgs }

  $rewritten = New-Object System.Collections.Generic.List[string]
  $sawTestkit = $false
  $wrapperKind = if ($env:TESTKIT_WRAPPER_KIND) { $env:TESTKIT_WRAPPER_KIND } else { 'powershell' }

  :inputArgs foreach ($arg in $InputArgs) {
    if ($arg -eq 'testkit' -and -not $sawTestkit) {
      $sawTestkit = $true
      $rewritten.Add('-e') | Out-Null
      $rewritten.Add(("TESTKIT_WRAPPER_KIND={0}" -f $wrapperKind)) | Out-Null
      $rewritten.Add($arg) | Out-Null
      continue inputArgs
    }

    if ($sawTestkit) {
      # NOTE: `continue` alone targets the switch itself (PowerShell treats
      # switch as a loop for break/continue), not the enclosing foreach, so it
      # would fall through to the unconditional Add below and duplicate the
      # arg. The label makes it target the foreach explicitly.
      switch ($arg) {
        'runTest.php' { $rewritten.Add('/workspace/testkit/runTest.php') | Out-Null; continue inputArgs }
        './runTest.php' { $rewritten.Add('/workspace/testkit/runTest.php') | Out-Null; continue inputArgs }
        'scripts/report.php' { $rewritten.Add('/workspace/testkit/scripts/report.php') | Out-Null; continue inputArgs }
        './scripts/report.php' { $rewritten.Add('/workspace/testkit/scripts/report.php') | Out-Null; continue inputArgs }
        'scripts/inspect.php' { $rewritten.Add('/workspace/testkit/scripts/inspect.php') | Out-Null; continue inputArgs }
        './scripts/inspect.php' { $rewritten.Add('/workspace/testkit/scripts/inspect.php') | Out-Null; continue inputArgs }
      }
    }

    $rewritten.Add($arg) | Out-Null
  }
  return ,$rewritten.ToArray()
}
