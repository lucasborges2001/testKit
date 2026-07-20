#Requires -Version 7.0
$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
. (Join-Path $PSScriptRoot 'TestHelpers.ps1')
. (Join-Path $repoRoot 'lib\powershell\Rewrite.ps1')

Remove-Item Env:\TESTKIT_WRAPPER_KIND -ErrorAction SilentlyContinue

$passthrough = Convert-TestkitRunArgs @('doctor', '--compact')
Assert-Equal ($passthrough -join '|') 'doctor|--compact' `
  'non-run commands must pass through unchanged'

$env:TESTKIT_WRAPPER_KIND = 'powershell'

$rewritten = Convert-TestkitRunArgs @('run', '--rm', 'testkit', 'php', 'runTest.php', 'back-php')
Assert-Equal ($rewritten -join '|') 'run|--rm|-e|TESTKIT_WRAPPER_KIND=powershell|testkit|php|/workspace/testkit/runTest.php|back-php' `
  'run command must inject TESTKIT_WRAPPER_KIND before "testkit" and rewrite runTest.php to its container path'

$rewrittenReport = Convert-TestkitRunArgs @('run', '--rm', 'testkit', 'php', 'scripts/report.php')
Assert-True (($rewrittenReport -join '|') -like '*|/workspace/testkit/scripts/report.php') `
  'scripts/report.php must rewrite to its absolute container path'

$rewrittenInspect = Convert-TestkitRunArgs @('run', '--rm', 'testkit', 'php', 'scripts/inspect.php', 'latest')
Assert-True (($rewrittenInspect -join '|') -like '*/workspace/testkit/scripts/inspect.php*') `
  'scripts/inspect.php must rewrite to its absolute container path'

$rewrittenTwice = Convert-TestkitRunArgs @('run', '--rm', 'testkit', 'php', 'runTest.php', 'testkit')
$injections = @($rewrittenTwice | Where-Object { $_ -eq 'TESTKIT_WRAPPER_KIND=powershell' })
Assert-Equal $injections.Count 1 `
  'TESTKIT_WRAPPER_KIND must be injected only once, even if the literal token "testkit" reappears later in argv'

Remove-Item Env:\TESTKIT_WRAPPER_KIND -ErrorAction SilentlyContinue

Complete-TestkitAssertions
