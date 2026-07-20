#Requires -Version 7.0
$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
. (Join-Path $PSScriptRoot 'TestHelpers.ps1')
. (Join-Path $repoRoot 'lib\powershell\Stack.ps1')

Remove-Item Env:\TEST_STORE_DRIVER -ErrorAction SilentlyContinue

Assert-Equal (Convert-TestkitStack '') 'mysql,redis' `
  'with no TESTKIT_STACK and no TEST_STORE_DRIVER, default stack must be mysql,redis'

$env:TEST_STORE_DRIVER = 'none'
Assert-Equal (Convert-TestkitStack '') '' `
  'TEST_STORE_DRIVER=none with empty raw stack must yield an empty effective stack'
Remove-Item Env:\TEST_STORE_DRIVER -ErrorAction SilentlyContinue

Assert-Equal (Convert-TestkitStack 'postgres') 'pg' 'postgres must normalize to pg'
Assert-Equal (Convert-TestkitStack 'postgresql') 'pg' 'postgresql must normalize to pg'
Assert-Equal (Convert-TestkitStack 'influxdb') 'influx' 'influxdb must normalize to influx'

Assert-Equal (Convert-TestkitStack 'mysql,mysql,redis') 'mysql,redis' `
  'duplicate tokens must be deduped, preserving first-seen order'

Assert-Equal (Convert-TestkitStack 'MySQL,ReDis') 'mysql,redis' `
  'tokens must be case-normalized'

$threw = $false
try {
  Convert-TestkitStack 'not-a-real-token' | Out-Null
} catch {
  $threw = $true
}
Assert-True $threw 'an unrecognized stack token must throw instead of being silently accepted'

Complete-TestkitAssertions
