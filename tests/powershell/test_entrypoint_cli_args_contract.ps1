#Requires -Version 7.0
$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'TestHelpers.ps1')

$entrypoint = Join-Path (Split-Path -Parent (Split-Path -Parent $PSScriptRoot)) 'bin\testkit.ps1'
$content = Get-Content -LiteralPath $entrypoint -Raw

Assert-True ($content.Contains("[string[]]`$CliArgs")) 'entrypoint must declare CliArgs instead of the automatic Args variable'
Assert-True ($content.Contains("[Alias('Args')]")) 'entrypoint must keep explicit -Args compatibility'
Assert-True ($content.Contains('ValueFromRemainingArguments=$true')) 'entrypoint must keep positional passthrough'
Assert-True (-not $content.Contains("[string[]]`$Args")) 'entrypoint must not redeclare the automatic Args variable'
Assert-True ($content.Contains('Invoke-TestkitRuntime $envFile.Path $CliArgs')) 'runtime must receive the captured CLI args'

Complete-TestkitAssertions
