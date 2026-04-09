Set-StrictMode -Version 2.0

function Get-TestkitUiCatalog {
    $actions = @(
        [pscustomobject]@{ Key = 'doctor'; Label = 'doctor'; Description = 'Diagnostico del entorno via bin/testkit.ps1 doctor --dump' },
        [pscustomobject]@{ Key = 'up'; Label = 'up'; Description = 'Levanta la infraestructura via bin/testkit.ps1 up -d' },
        [pscustomobject]@{ Key = 'seed'; Label = 'seed'; Description = 'Dispara el wrapper de seed existente' },
        [pscustomobject]@{ Key = 'run-tests'; Label = 'run tests'; Description = 'Ejecuta runTest.php con target + env vars reales' },
        [pscustomobject]@{ Key = 'report'; Label = 'report'; Description = 'Genera reporte humano via scripts/report.php' },
        [pscustomobject]@{ Key = 'down'; Label = 'down'; Description = 'Baja la infraestructura via bin/testkit.ps1 down' }
    )

    $stacks = @(
        [pscustomobject]@{ Key = 'mysql'; Label = 'mysql' },
        [pscustomobject]@{ Key = 'mysql,redis'; Label = 'mysql,redis' },
        [pscustomobject]@{ Key = 'pg'; Label = 'pg' }
    )

    $targets = @(
        [pscustomobject]@{ Key = 'all'; Label = 'all' },
        [pscustomobject]@{ Key = 'back'; Label = 'back' },
        [pscustomobject]@{ Key = 'back-php'; Label = 'back-php' },
        [pscustomobject]@{ Key = 'back-py'; Label = 'back-py' },
        [pscustomobject]@{ Key = 'front'; Label = 'front' },
        [pscustomobject]@{ Key = 'front-php'; Label = 'front-php' },
        [pscustomobject]@{ Key = 'front-js'; Label = 'front-js' },
        [pscustomobject]@{ Key = 'php'; Label = 'php' },
        [pscustomobject]@{ Key = 'js'; Label = 'js' }
    )

    $scopes = @(
        [pscustomobject]@{ Key = 'all'; Label = 'all (sin TEST_SCOPE explicito)' },
        [pscustomobject]@{ Key = 'unit'; Label = 'unit' },
        [pscustomobject]@{ Key = 'integration'; Label = 'integration' },
        [pscustomobject]@{ Key = 'e2e'; Label = 'e2e' }
    )

    $categories = @(
        [pscustomobject]@{ Key = 'all'; Label = 'all (sin TEST_CATEGORY explicito)' },
        [pscustomobject]@{ Key = 'smoke'; Label = 'smoke' },
        [pscustomobject]@{ Key = 'perf'; Label = 'perf' },
        [pscustomobject]@{ Key = 'stress'; Label = 'stress' },
        [pscustomobject]@{ Key = 'contract'; Label = 'contract' },
        [pscustomobject]@{ Key = 'critical'; Label = 'critical' },
        [pscustomobject]@{ Key = 'slow'; Label = 'slow' }
    )

    return [pscustomobject]@{
        Actions = $actions
        Stacks = $stacks
        Targets = $targets
        Scopes = $scopes
        Categories = $categories
    }
}

function Resolve-TestkitUiPaths {
    param([Parameter(Mandatory = $true)][string]$RepositoryRoot)

    $paths = [ordered]@{
        RepositoryRoot = $RepositoryRoot
        TestkitScript = Join-Path $RepositoryRoot 'bin\testkit.ps1'
        SeedScript = Join-Path $RepositoryRoot 'scripts\seed.ps1'
        ReportPhp = '/workspace/testkit/scripts/report.php'
        RunTestPhp = '/workspace/testkit/runTest.php'
    }

    return [pscustomobject]$paths
}

function New-TestkitUiSelection {
    param([Parameter(Mandatory = $true)][psobject]$Catalog)

    return [pscustomobject]@{
        Action = $null
        Stack = $null
        Target = ($Catalog.Targets | Where-Object { $_.Key -eq 'all' } | Select-Object -First 1)
        Scope = ($Catalog.Scopes | Where-Object { $_.Key -eq 'all' } | Select-Object -First 1)
        Category = ($Catalog.Categories | Where-Object { $_.Key -eq 'all' } | Select-Object -First 1)
        Match = ''
        FailFast = $false
        Jobs = ''
        Coverage = $false
        ListOnly = $false
    }
}

function ConvertTo-TestkitBoolString {
    param([Parameter(Mandatory = $true)][bool]$Value)

    if ($Value) {
        return '1'
    }

    return '0'
}

function Build-TestkitExecutionPlan {
    param(
        [Parameter(Mandatory = $true)][psobject]$Selection,
        [Parameter(Mandatory = $true)][psobject]$Paths
    )

    if ($null -eq $Selection.Action) {
        throw 'No hay accion seleccionada.'
    }

    if (-not (Test-Path -LiteralPath $Paths.TestkitScript)) {
        throw "No se encontro el entrypoint no interactivo esperado: $($Paths.TestkitScript)"
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
        Notes = New-Object System.Collections.Generic.List[string]
    }

    if ($Selection.Stack) {
        $plan.Notes.Add('Stack seleccionado: ' + $Selection.Stack.Key)
        $plan.Notes.Add('No se emite ninguna flag/env var de stack desde esta UI porque el contrato real de stack no aparece en el material provisto. El hook queda encapsulado en Build-TestkitExecutionPlan para cablearlo sin tocar la UI.')
    }

    switch ($Selection.Action.Key) {
        'doctor' {
            $plan.Command = Format-UiCommandLine -Command $relativeTestkit -Arguments @('doctor', '--dump')
            $plan.CommandPath = $Paths.TestkitScript
            $plan.Arguments = @('doctor', '--dump')
        }
        'up' {
            $plan.Command = Format-UiCommandLine -Command $relativeTestkit -Arguments @('up', '-d')
            $plan.CommandPath = $Paths.TestkitScript
            $plan.Arguments = @('up', '-d')
        }
        'seed' {
            if (-not (Test-Path -LiteralPath $Paths.SeedScript)) {
                throw "No se encontro scripts/seed.ps1 en $($Paths.SeedScript). Esta UI evita inventar otro wrapper."
            }

            $plan.Command = Format-UiCommandLine -Command $relativeSeed
            $plan.CommandPath = $Paths.SeedScript
            $plan.Arguments = @()
        }
        'run-tests' {
            $target = 'all'
            if ($Selection.Target) {
                $target = $Selection.Target.Key
            }

            if ($Selection.Scope -and $Selection.Scope.Key -ne 'all') {
                $plan.EnvVars['TEST_SCOPE'] = $Selection.Scope.Key
            }

            if ($Selection.Category -and $Selection.Category.Key -ne 'all') {
                $plan.EnvVars['TEST_CATEGORY'] = $Selection.Category.Key
            }

            if (-not [string]::IsNullOrWhiteSpace($Selection.Match)) {
                $plan.EnvVars['TEST_MATCH'] = $Selection.Match.Trim()
            }

            if ($Selection.FailFast) {
                $plan.EnvVars['TEST_FAIL_FAST'] = (ConvertTo-TestkitBoolString -Value $true)
            }

            if (-not [string]::IsNullOrWhiteSpace($Selection.Jobs)) {
                $plan.EnvVars['TEST_JOBS'] = [string]$Selection.Jobs
            }

            if ($Selection.Coverage) {
                $plan.EnvVars['TEST_COVERAGE'] = (ConvertTo-TestkitBoolString -Value $true)
            }

            if ($Selection.ListOnly) {
                $plan.EnvVars['TEST_LIST'] = (ConvertTo-TestkitBoolString -Value $true)
            }

            $plan.Command = Format-UiCommandLine -Command $relativeTestkit -Arguments @('run', '--rm', 'testkit', 'php', $Paths.RunTestPhp, $target)
            $plan.CommandPath = $Paths.TestkitScript
            $plan.Arguments = @('run', '--rm', 'testkit', 'php', $Paths.RunTestPhp, $target)
        }
        'report' {
            $plan.Command = Format-UiCommandLine -Command $relativeTestkit -Arguments @('run', '--rm', 'testkit', 'php', $Paths.ReportPhp)
            $plan.CommandPath = $Paths.TestkitScript
            $plan.Arguments = @('run', '--rm', 'testkit', 'php', $Paths.ReportPhp)
        }
        'down' {
            $plan.Command = Format-UiCommandLine -Command $relativeTestkit -Arguments @('down')
            $plan.CommandPath = $Paths.TestkitScript
            $plan.Arguments = @('down')
        }
        default {
            throw ('Accion no soportada: ' + $Selection.Action.Key)
        }
    }

    $summaryRows = New-Object System.Collections.Generic.List[object]
    $summaryRows.Add([pscustomobject]@{ Label = 'modo'; Value = 'interactivo' })
    $summaryRows.Add([pscustomobject]@{ Label = 'accion'; Value = $Selection.Action.Label })

    if ($Selection.Stack) {
        $summaryRows.Add([pscustomobject]@{ Label = 'stack'; Value = $Selection.Stack.Key })
    }

    if ($Selection.Action.Key -eq 'run-tests') {
        $summaryRows.Add([pscustomobject]@{ Label = 'target'; Value = $Selection.Target.Key })
        $summaryRows.Add([pscustomobject]@{ Label = 'scope'; Value = $Selection.Scope.Key })
        $summaryRows.Add([pscustomobject]@{ Label = 'category'; Value = $Selection.Category.Key })
        $summaryRows.Add([pscustomobject]@{ Label = 'selector libre'; Value = (Get-TestkitUiDisplayValue -Value $Selection.Match) })
        $summaryRows.Add([pscustomobject]@{ Label = 'fail-fast'; Value = (Get-TestkitUiBooleanLabel -Value $Selection.FailFast) })
        $summaryRows.Add([pscustomobject]@{ Label = 'jobs'; Value = (Get-TestkitUiDisplayValue -Value $Selection.Jobs) })
        $summaryRows.Add([pscustomobject]@{ Label = 'coverage'; Value = (Get-TestkitUiBooleanLabel -Value $Selection.Coverage) })
        $summaryRows.Add([pscustomobject]@{ Label = 'list-only'; Value = (Get-TestkitUiBooleanLabel -Value $Selection.ListOnly) })
    }

    $summaryRows.Add([pscustomobject]@{ Label = 'modo canonical'; Value = '.\bin\testkit.ps1 y wrappers existentes' })
    $plan.SummaryRows = @($summaryRows)

    $repro = New-Object System.Collections.Generic.List[string]
    $repro.Add('# ejecutar desde la raiz del repo testkit')

    foreach ($name in $plan.EnvVars.Keys) {
        $repro.Add('$env:' + $name + ' = ' + (Format-UiCommandToken -Value $plan.EnvVars[$name]))
    }

    $repro.Add($plan.Command)
    $plan.ReproBlock = @($repro)

    return [pscustomobject]$plan
}

function Get-TestkitUiDisplayValue {
    param([string]$Value)

    if ([string]::IsNullOrWhiteSpace($Value)) {
        return '(vacio)'
    }

    return $Value
}

function Get-TestkitUiBooleanLabel {
    param([Parameter(Mandatory = $true)][bool]$Value)

    if ($Value) {
        return 'si'
    }

    return 'no'
}

function Invoke-TestkitExecutionPlan {
    param([Parameter(Mandatory = $true)][psobject]$Plan)

    Push-Location -LiteralPath $Plan.WorkingDirectory
    try {
        $backup = New-Object System.Collections.Generic.List[object]

        foreach ($name in $Plan.EnvVars.Keys) {
            $existing = [Environment]::GetEnvironmentVariable($name, 'Process')
            $backup.Add([pscustomobject]@{ Name = $name; Value = $existing; HadValue = ($null -ne $existing) })
            [Environment]::SetEnvironmentVariable($name, $Plan.EnvVars[$name], 'Process')
        }

        try {
            $global:LASTEXITCODE = 0
            & $Plan.CommandPath @($Plan.Arguments)
            return $LASTEXITCODE
        }
        finally {
            foreach ($item in $backup) {
                if ($item.HadValue) {
                    [Environment]::SetEnvironmentVariable($item.Name, $item.Value, 'Process')
                }
                else {
                    [Environment]::SetEnvironmentVariable($item.Name, $null, 'Process')
                }
            }
        }
    }
    finally {
        Pop-Location
    }
}