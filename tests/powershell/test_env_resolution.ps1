#Requires -Version 7.0
$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
. (Join-Path $PSScriptRoot 'TestHelpers.ps1')
. (Join-Path $repoRoot 'lib\powershell\Env.ps1')

$tempProject = Join-Path ([System.IO.Path]::GetTempPath()) ("testkit-envcheck-" + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $tempProject -Force | Out-Null

try {
  $script:ProjectRoot = Resolve-Path $tempProject
  Remove-Item Env:\TESTKIT_ENV_FILE -ErrorAction SilentlyContinue

  Assert-True ($null -eq (Get-TestkitEnvFile)) `
    'with neither test/.env.test nor .env.test present, resolution must return null'

  $rootEnv = Join-Path $tempProject '.env.test'
  Set-Content -Path $rootEnv -Value 'FOO=bar'
  $foundRoot = Get-TestkitEnvFile
  Assert-Equal $foundRoot.Path (Resolve-Path $rootEnv).Path `
    'with only root .env.test present, it must be the one resolved'

  $testDir = Join-Path $tempProject 'test'
  New-Item -ItemType Directory -Path $testDir -Force | Out-Null
  $nestedEnv = Join-Path $testDir '.env.test'
  Set-Content -Path $nestedEnv -Value 'FOO=baz'
  $foundNested = Get-TestkitEnvFile
  Assert-Equal $foundNested.Path (Resolve-Path $nestedEnv).Path `
    'test/.env.test must take precedence over root .env.test when both exist'

  $overrideEnv = Join-Path $tempProject 'override.env'
  Set-Content -Path $overrideEnv -Value 'FOO=override'
  $env:TESTKIT_ENV_FILE = $overrideEnv
  $foundOverride = Get-TestkitEnvFile
  Assert-Equal $foundOverride.Path (Resolve-Path $overrideEnv).Path `
    'TESTKIT_ENV_FILE override must win over both default locations'
  Remove-Item Env:\TESTKIT_ENV_FILE -ErrorAction SilentlyContinue

  $containerPathRoot = Convert-TestkitEnvFileToContainerPath $rootEnv
  Assert-Equal $containerPathRoot '/workspace/project/.env.test' `
    'root .env.test must map to /workspace/project/.env.test'

  $containerPathNested = Convert-TestkitEnvFileToContainerPath $nestedEnv
  Assert-Equal $containerPathNested '/workspace/project/test/.env.test' `
    'nested env file must map with backslashes converted to forward slashes'
} finally {
  Remove-Item Env:\TESTKIT_ENV_FILE -ErrorAction SilentlyContinue
  Remove-Item -Recurse -Force $tempProject -ErrorAction SilentlyContinue
}

Complete-TestkitAssertions
