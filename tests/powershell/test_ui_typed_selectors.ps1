#Requires -Version 7.0
$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
. (Join-Path $PSScriptRoot 'TestHelpers.ps1')
. (Join-Path $repoRoot 'ui\powershell\lib\Testkit.UI.Console.ps1')
. (Join-Path $repoRoot 'ui\powershell\lib\Testkit.UI.Plan.ps1')

$tmp = Join-Path ([System.IO.Path]::GetTempPath()) ('testkit-ui-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path (Join-Path $tmp 'bin') -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $tmp 'scripts') -Force | Out-Null
Set-Content -LiteralPath (Join-Path $tmp 'bin\testkit.ps1') -Value 'exit 0' -NoNewline
Set-Content -LiteralPath (Join-Path $tmp 'scripts\seed.ps1') -Value 'exit 0' -NoNewline

try {
    $catalog = Get-TestkitUiCatalog
    Assert-True ($catalog.PSObject.Properties.Name -contains 'Selectors') 'catalog must expose Selectors'
    Assert-True (-not ($catalog.PSObject.Properties.Name -contains 'Targets')) 'catalog must not expose Targets'
    Assert-True (-not ($catalog.Selectors.Key -contains 'back-py')) 'legacy back-py alias must be absent'

    $paths = Resolve-TestkitUiPaths -RepositoryRoot $tmp
    $selection = New-TestkitUiSelection -Catalog $catalog
    $selection.Action = $catalog.Actions | Where-Object Key -eq 'run-tests' | Select-Object -First 1
    $selection.Selector = $catalog.Selectors | Where-Object { $_.Kind -eq 'suite' -and $_.Key -eq 'back-php' } | Select-Object -First 1
    $selection.TestPath = 'test/back/auth/login.test.php'
    $selection.ListOnly = $true

    $plan = Build-TestkitExecutionPlan -Selection $selection -Paths $paths
    $joined = $plan.Arguments -join '|'
    Assert-True ($joined -like '*|--suite|back-php|--test|test/back/auth/login.test.php|--list') 'suite plan must use --suite, --test and --list'
    Assert-True (-not ($plan.EnvVars.Keys -contains 'TEST_MATCH')) 'plan must not emit TEST_MATCH'
    Assert-True (-not ($plan.EnvVars.Keys -contains 'TEST_CATEGORY')) 'plan must not emit TEST_CATEGORY'
    Assert-True (-not ($plan.EnvVars.Keys -contains 'TEST_LIST')) 'plan must not emit TEST_LIST'
    Assert-True ($plan.Command -like '*--suite*back-php*--test*login.test.php*--list*') 'displayed command must preserve typed selector arguments'

    $selection.Selector = $catalog.Selectors | Where-Object { $_.Kind -eq 'group' -and $_.Key -eq 'all' } | Select-Object -First 1
    $selection.TestPath = 'test/back/auth/login.test.php'
    $threw = $false
    try { Build-TestkitExecutionPlan -Selection $selection -Paths $paths | Out-Null }
    catch { $threw = $true }
    Assert-True $threw '--test with group must fail'
}
finally {
    Remove-Item -LiteralPath $tmp -Recurse -Force -ErrorAction SilentlyContinue
}

Complete-TestkitAssertions
