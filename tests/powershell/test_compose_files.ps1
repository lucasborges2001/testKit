#Requires -Version 7.0
$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
. (Join-Path $PSScriptRoot 'TestHelpers.ps1')
. (Join-Path $repoRoot 'lib\powershell\Stack.ps1')

$script:ResolvedTestkitRoot = Resolve-Path $repoRoot

$base = Get-TestkitComposeFiles ''
Assert-Equal $base.Count 2 'with no stack tokens, only the base compose.yaml pair (-f, path) must be present'
Assert-True ($base -contains (Join-Path $script:ResolvedTestkitRoot 'compose.yaml')) `
  'compose.yaml must always be included'

$mysqlRedis = Get-TestkitComposeFiles 'mysql,redis'
Assert-Equal $mysqlRedis.Count 6 'mysql+redis must add exactly two additional -f pairs on top of the base pair'
Assert-True ($mysqlRedis -contains (Join-Path $script:ResolvedTestkitRoot 'compose.mysql.yaml')) `
  'mysql token must add compose.mysql.yaml'
Assert-True ($mysqlRedis -contains (Join-Path $script:ResolvedTestkitRoot 'compose.redis.yaml')) `
  'redis token must add compose.redis.yaml'

$all = Get-TestkitComposeFiles 'mysql,redis,pg,influx'
Assert-Equal $all.Count 10 'all four stack tokens must add four additional -f pairs on top of the base pair'

$expectedOrder = @(
  (Join-Path $script:ResolvedTestkitRoot 'compose.yaml'),
  (Join-Path $script:ResolvedTestkitRoot 'compose.mysql.yaml'),
  (Join-Path $script:ResolvedTestkitRoot 'compose.redis.yaml'),
  (Join-Path $script:ResolvedTestkitRoot 'compose.pg.yaml'),
  (Join-Path $script:ResolvedTestkitRoot 'compose.influx.yaml')
)
$actualPaths = @($all | Where-Object { $_ -ne '-f' })
for ($i = 0; $i -lt $expectedOrder.Count; $i++) {
  Assert-Equal $actualPaths[$i] $expectedOrder[$i] "compose file order must be deterministic (index $i)"
}

Complete-TestkitAssertions
