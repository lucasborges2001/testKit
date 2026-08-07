Set-StrictMode -Version 2.0

function Get-TestkitUiCatalog {
    $actions = @(
        [pscustomobject]@{ Key = 'doctor'; Label = 'doctor'; Description = 'Diagnostico del entorno' },
        [pscustomobject]@{ Key = 'up'; Label = 'up'; Description = 'Levanta infraestructura' },
        [pscustomobject]@{ Key = 'seed'; Label = 'seed'; Description = 'Ejecuta el wrapper de seed' },
        [pscustomobject]@{ Key = 'run-tests'; Label = 'run tests'; Description = 'Ejecuta runTest.php con selector tipado' },
        [pscustomobject]@{ Key = 'report'; Label = 'report'; Description = 'Genera reporte humano' },
        [pscustomobject]@{ Key = 'down'; Label = 'down'; Description = 'Baja infraestructura' }
    )

    $selectors = @(
        [pscustomobject]@{ Kind = 'group'; Key = 'all'; Label = 'group: all' },
        [pscustomobject]@{ Kind = 'group'; Key = 'back'; Label = 'group: back' },
        [pscustomobject]@{ Kind = 'group'; Key = 'front'; Label = 'group: front' },
        [pscustomobject]@{ Kind = 'group'; Key = 'infra'; Label = 'group: infra' },
        [pscustomobject]@{ Kind = 'group'; Key = 'php'; Label = 'group: php' },
        [pscustomobject]@{ Kind = 'group'; Key = 'js'; Label = 'group: js' },
        [pscustomobject]@{ Kind = 'suite'; Key = 'back-php'; Label = 'suite: back-php' },
        [pscustomobject]@{ Kind = 'suite'; Key = 'back-python'; Label = 'suite: back-python' },
        [pscustomobject]@{ Kind = 'suite'; Key = 'front-php'; Label = 'suite: front-php' },
        [pscustomobject]@{ Kind = 'suite'; Key = 'front-js'; Label = 'suite: front-js' },
        [pscustomobject]@{ Kind = 'suite'; Key = 'infra-php'; Label = 'suite: infra-php' },
        [pscustomobject]@{ Kind = 'suite'; Key = 'migration-contract'; Label = 'suite: migration-contract' },
        [pscustomobject]@{ Kind = 'suite'; Key = 'reference-contract'; Label = 'suite: reference-contract' },
        [pscustomobject]@{ Kind = 'suite'; Key = 'sql-observability'; Label = 'suite: sql-observability' },
        [pscustomobject]@{ Kind = 'category'; Key = 'smoke'; Label = 'category: smoke' },
        [pscustomobject]@{ Kind = 'category'; Key = 'perf'; Label = 'category: perf' },
        [pscustomobject]@{ Kind = 'category'; Key = 'stress'; Label = 'category: stress' },
        [pscustomobject]@{ Kind = 'category'; Key = 'contract'; Label = 'category: contract' },
        [pscustomobject]@{ Kind = 'category'; Key = 'critical'; Label = 'category: critical' },
        [pscustomobject]@{ Kind = 'category'; Key = 'security'; Label = 'category: security' },
        [pscustomobject]@{ Kind = 'category'; Key = 'slow'; Label = 'category: slow' }
    )

    return [pscustomobject]@{
        Actions = $actions
        Selectors = $selectors
        Stacks = @(
            [pscustomobject]@{ Key = 'mysql'; Label = 'mysql' },
            [pscustomobject]@{ Key = 'mysql,redis'; Label = 'mysql,redis' },
            [pscustomobject]@{ Key = 'pg'; Label = 'pg' }
        )
        Scopes = @(
            [pscustomobject]@{ Key = 'all'; Label = 'all' },
            [pscustomobject]@{ Key = 'unit'; Label = 'unit' },
            [pscustomobject]@{ Key = 'integration'; Label = 'integration' },
            [pscustomobject]@{ Key = 'e2e'; Label = 'e2e' }
        )
    }
}

function Resolve-TestkitUiPaths {
    param([Parameter(Mandatory = $true)][string]$RepositoryRoot)
    return [pscustomobject]@{
        RepositoryRoot = $RepositoryRoot
        TestkitScript = Join-Path $RepositoryRoot 'bin\testkit.ps1'
        SeedScript = Join-Path $RepositoryRoot 'scripts\seed.ps1'
        ReportPhp = '/workspace/testkit/scripts/report.php'
        RunTestPhp = '/workspace/testkit/runTest.php'
    }
}

function New-TestkitUiSelection {
    param([Parameter(Mandatory = $true)][psobject]$Catalog)
    return [pscustomobject]@{
        Action = $null
        Stack = $null
        Selector = ($Catalog.Selectors | Where-Object { $_.Kind -eq 'group' -and $_.Key -eq 'all' } | Select-Object -First 1)
        Scope = ($Catalog.Scopes | Where-Object { $_.Key -eq 'all' } | Select-Object -First 1)
        TestPath = ''
        FailFast = $false
        Jobs = ''
        Coverage = $false
        ListOnly = $false
    }
}

function ConvertTo-TestkitBoolString {
    param([Parameter(Mandatory = $true)][bool]$Value)
    if ($Value) { return '1' }
    return '0'
}

function Build-TestkitExecutionPlan {
    param(
        [Parameter(Mandatory = $true)][psobject]$Selection,
        [Parameter(Mandatory = $true)][psobject]$Paths
    )

    if ($null -eq $Selection.Action) { throw 'No hay accion seleccionada.' }
    if (-not (Test-Path -LiteralPath $Paths.TestkitScript)) {
        throw "No se encontro el entrypoint: $($Paths.TestkitScript)"
    }

    $relativeTestkit = '.\bin\testkit.ps1'
    $relativeSeed = '.\scripts\seed.ps1'
    $plan = [ordered]@{
        ActionKey = $Selection.Action.Key
        ActionLabel = $Selection.Action.Label
        WorkingDirectory = $Paths.RepositoryRoot
        Command = ''
        CommandPath = ''
        Arguments = @()
        EnvVars = [ordered]@{}
        SummaryRows = @()
        ReproBlock = @()
        Notes = @()
    }

    if ($Selection.Stack) {
        $plan.Notes += 'Stack seleccionado: ' + $Selection.Stack.Key
        $plan.Notes += 'La UI no traduce stack en esta subfase.'
    }

    switch ($Selection.Action.Key) {
        'doctor' {
            $plan.CommandPath = $Paths.TestkitScript
            $plan.Arguments = @('doctor', '--dump')
        }
        'up' {
            $plan.CommandPath = $Paths.TestkitScript
            $plan.Arguments = @('up', '-d')
        }
        'seed' {
            if (-not (Test-Path -LiteralPath $Paths.SeedScript)) { throw "Falta $($Paths.SeedScript)" }
            $plan.CommandPath = $Paths.SeedScript
            $plan.Arguments = @()
        }
        'run-tests' {
            if ($null -eq $Selection.Selector) { throw 'Falta selector tipado.' }
            $kind = [string]$Selection.Selector.Kind
            $name = [string]$Selection.Selector.Key
            if ($kind -notin @('suite', 'group', 'category')) { throw "Selector kind invalido: $kind" }
            if ([string]::IsNullOrWhiteSpace($name)) { throw 'Selector name vacio.' }
            if (-not [string]::IsNullOrWhiteSpace($Selection.TestPath) -and $kind -ne 'suite') {
                throw '--test solo se admite con --suite.'
            }

            if ($Selection.Scope -and $Selection.Scope.Key -ne 'all') {
                $plan.EnvVars['TEST_SCOPE'] = $Selection.Scope.Key
            }
            if ($Selection.FailFast) { $plan.EnvVars['TEST_FAIL_FAST'] = '1' }
            if (-not [string]::IsNullOrWhiteSpace($Selection.Jobs)) { $plan.EnvVars['TEST_JOBS'] = [string]$Selection.Jobs }
            if ($Selection.Coverage) { $plan.EnvVars['TEST_COVERAGE'] = '1' }

            $args = @('run', '--rm', 'testkit', 'php', $Paths.RunTestPhp, ('--' + $kind), $name)
            if (-not [string]::IsNullOrWhiteSpace($Selection.TestPath)) {
                $args += @('--test', $Selection.TestPath.Trim())
            }
            if ($Selection.ListOnly) { $args += '--list' }
            $plan.CommandPath = $Paths.TestkitScript
            $plan.Arguments = $args
        }
        'report' {
            $plan.CommandPath = $Paths.TestkitScript
            $plan.Arguments = @('run', '--rm', 'testkit', 'php', $Paths.ReportPhp)
        }
        'down' {
            $plan.CommandPath = $Paths.TestkitScript
            $plan.Arguments = @('down')
        }
        default { throw ('Accion no soportada: ' + $Selection.Action.Key) }
    }

    $commandDisplay = if ($Selection.Action.Key -eq 'seed') { $relativeSeed } else { $relativeTestkit }
    $plan.Command = Format-UiCommandLine -Command $commandDisplay -Arguments $plan.Arguments

    $rows = @()
    $rows += [pscustomobject]@{ Label = 'modo'; Value = 'interactivo' }
    $rows += [pscustomobject]@{ Label = 'accion'; Value = $Selection.Action.Label }
    if ($Selection.Stack) { $rows += [pscustomobject]@{ Label = 'stack'; Value = $Selection.Stack.Key } }
    if ($Selection.Action.Key -eq 'run-tests') {
        $rows += [pscustomobject]@{ Label = 'selector'; Value = ($Selection.Selector.Kind + ':' + $Selection.Selector.Key) }
        $rows += [pscustomobject]@{ Label = 'scope'; Value = $Selection.Scope.Key }
        $rows += [pscustomobject]@{ Label = 'test'; Value = (Get-TestkitUiDisplayValue -Value $Selection.TestPath) }
        $rows += [pscustomobject]@{ Label = 'list-only'; Value = (Get-TestkitUiBooleanLabel -Value $Selection.ListOnly) }
    }
    $plan.SummaryRows = $rows

    $repro = @('# ejecutar desde la raiz del repo testkit')
    foreach ($key in $plan.EnvVars.Keys) {
        $repro += '$env:' + $key + ' = ' + (Format-UiCommandToken -Value $plan.EnvVars[$key])
    }
    $repro += $plan.Command
    $plan.ReproBlock = $repro
    return [pscustomobject]$plan
}

function Get-TestkitUiDisplayValue {
    param([string]$Value)
    if ([string]::IsNullOrWhiteSpace($Value)) { return '(vacio)' }
    return $Value
}

function Get-TestkitUiBooleanLabel {
    param([Parameter(Mandatory = $true)][bool]$Value)
    if ($Value) { return 'si' }
    return 'no'
}

function Invoke-TestkitExecutionPlan {
    param([Parameter(Mandatory = $true)][psobject]$Plan)
    Push-Location -LiteralPath $Plan.WorkingDirectory
    try {
        $backup = @()
        foreach ($name in $Plan.EnvVars.Keys) {
            $existing = [Environment]::GetEnvironmentVariable($name, 'Process')
            $backup += [pscustomobject]@{ Name = $name; Value = $existing; HadValue = ($null -ne $existing) }
            [Environment]::SetEnvironmentVariable($name, $Plan.EnvVars[$name], 'Process')
        }
        try {
            $global:LASTEXITCODE = 0
            & $Plan.CommandPath @($Plan.Arguments)
            return $LASTEXITCODE
        }
        finally {
            foreach ($item in $backup) {
                if ($item.HadValue) { [Environment]::SetEnvironmentVariable($item.Name, $item.Value, 'Process') }
                else { [Environment]::SetEnvironmentVariable($item.Name, $null, 'Process') }
            }
        }
    }
    finally { Pop-Location }
}
