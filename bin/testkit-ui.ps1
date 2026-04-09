Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot = (Resolve-Path (Join-Path $Here '..')).Path
$TestkitBin = (Resolve-Path (Join-Path $Here 'testkit.ps1')).Path
$SeedScript = (Resolve-Path (Join-Path $RepoRoot 'scripts/seed.ps1')).Path

function New-Choice([string]$Value, [string]$Label) {
    return [PSCustomObject]@{
        Value = $Value
        Label = $Label
    }
}

function Get-ActionChoices {
    return @(
        (New-Choice 'doctor' 'doctor'),
        (New-Choice 'up' 'up'),
        (New-Choice 'seed' 'seed'),
        (New-Choice 'run_tests' 'run tests'),
        (New-Choice 'report' 'report'),
        (New-Choice 'down' 'down')
    )
}

function Get-StackChoices {
    return @(
        (New-Choice 'mysql' 'mysql'),
        (New-Choice 'mysql,redis' 'mysql,redis'),
        (New-Choice 'pg' 'pg')
    )
}

function Get-TargetChoices {
    return @(
        (New-Choice 'all' 'all'),
        (New-Choice 'back' 'back'),
        (New-Choice 'back-php' 'back-php'),
        (New-Choice 'back-py' 'back-py'),
        (New-Choice 'front' 'front'),
        (New-Choice 'front-php' 'front-php'),
        (New-Choice 'front-js' 'front-js'),
        (New-Choice 'php' 'php'),
        (New-Choice 'js' 'js')
    )
}

function Get-ScopeChoices {
    return @(
        (New-Choice 'unit' 'unit'),
        (New-Choice 'integration' 'integration'),
        (New-Choice 'e2e' 'e2e'),
        (New-Choice 'all' 'all')
    )
}

function Get-CategoryChoices {
    return @(
        (New-Choice 'smoke' 'smoke'),
        (New-Choice 'perf' 'perf'),
        (New-Choice 'stress' 'stress'),
        (New-Choice 'contract' 'contract'),
        (New-Choice 'critical' 'critical'),
        (New-Choice 'slow' 'slow'),
        (New-Choice 'all' 'all')
    )
}

function Read-ChoiceValue {
    param(
        [string]$Title,
        [object[]]$Choices,
        [string]$DefaultValue
    )

    if (-not $Choices -or $Choices.Count -eq 0) {
        throw 'No hay opciones disponibles.'
    }

    $defaultIndex = 1
    for ($i = 0; $i -lt $Choices.Count; $i++) {
        if ($Choices[$i].Value -eq $DefaultValue) {
            $defaultIndex = $i + 1
            break
        }
    }

    while ($true) {
        Write-Host ''
        Write-Host $Title
        for ($i = 0; $i -lt $Choices.Count; $i++) {
            $suffix = ''
            if (($i + 1) -eq $defaultIndex) {
                $suffix = ' [default]'
            }
            Write-Host ('  {0}) {1}{2}' -f ($i + 1), $Choices[$i].Label, $suffix)
        }

        $raw = Read-Host ('Elegí una opción [{0}]' -f $defaultIndex)
        if ([string]::IsNullOrWhiteSpace($raw)) {
            return $Choices[$defaultIndex - 1].Value
        }

        $parsed = 0
        if ([int]::TryParse($raw.Trim(), [ref]$parsed)) {
            if ($parsed -ge 1 -and $parsed -le $Choices.Count) {
                return $Choices[$parsed - 1].Value
            }
        }

        foreach ($choice in $Choices) {
            if ($choice.Value -eq $raw.Trim() -or $choice.Label -eq $raw.Trim()) {
                return $choice.Value
            }
        }

        Write-Host 'Opción inválida. Probá de nuevo.'
    }
}

function Read-YesNo {
    param(
        [string]$Label,
        [bool]$Default = $true
    )

    $hint = if ($Default) { '[Y/n]' } else { '[y/N]' }

    while ($true) {
        $raw = Read-Host ('{0} {1}' -f $Label, $hint)
        if ([string]::IsNullOrWhiteSpace($raw)) {
            return $Default
        }

        switch ($raw.Trim().ToLowerInvariant()) {
            'y' { return $true }
            'yes' { return $true }
            's' { return $true }
            'si' { return $true }
            'n' { return $false }
            'no' { return $false }
            default { Write-Host 'Respuesta inválida. Usá y/n.' }
        }
    }
}

function Read-TextValue {
    param(
        [string]$Label,
        [string]$Default = ''
    )

    $suffix = ''
    if ($Default -ne '') {
        $suffix = ' [' + $Default + ']'
    }

    $raw = Read-Host ($Label + $suffix)
    if ([string]::IsNullOrWhiteSpace($raw)) {
        return $Default
    }

    return $raw.Trim()
}

function Read-IntegerValue {
    param(
        [string]$Label,
        [int]$Default = 1,
        [int]$Min = 1
    )

    while ($true) {
        $raw = Read-Host ('{0} [{1}]' -f $Label, $Default)
        if ([string]::IsNullOrWhiteSpace($raw)) {
            return $Default
        }

        $parsed = 0
        if ([int]::TryParse($raw.Trim(), [ref]$parsed) -and $parsed -ge $Min) {
            return $parsed
        }

        Write-Host ('Valor inválido. Ingresá un entero >= {0}.' -f $Min)
    }
}

function ConvertTo-BoolString([bool]$Value) {
    if ($Value) { return '1' }
    return '0'
}

function Quote-PowerShellLiteral([string]$Value) {
    return '''' + $Value.Replace('''', '''''') + ''''
}

function Get-RepoRelativePath([string]$Path) {
    $resolved = (Resolve-Path $Path).Path
    if ($resolved.StartsWith($RepoRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
        $relative = $resolved.Substring($RepoRoot.Length).TrimStart('\', '/')
        if ($relative -eq '') {
            return '.\'
        }
        return '.\' + ($relative -replace '/', '\')
    }

    return $resolved
}

function Format-CommandLine {
    param(
        [string]$CommandPath,
        [string[]]$CommandArgs
    )

    $parts = New-Object System.Collections.Generic.List[string]
    $parts.Add((Get-RepoRelativePath $CommandPath))

    foreach ($arg in $CommandArgs) {
        $parts.Add((Quote-PowerShellLiteral ([string]$arg)))
    }

    return ($parts -join ' ')
}

function New-BaseEnvVars([string]$Stack) {
    return [ordered]@{
        TESTKIT_STACK = $Stack
    }
}

function New-RunFilters {
    $scope = Read-ChoiceValue -Title 'scope' -Choices (Get-ScopeChoices) -DefaultValue 'all'
    $category = Read-ChoiceValue -Title 'category' -Choices (Get-CategoryChoices) -DefaultValue 'all'
    $selector = Read-TextValue -Label 'Selector libre para TEST_MATCH (nombre/path/módulo)' -Default ''
    $failFast = Read-YesNo -Label 'fail-fast' -Default $true
    $jobs = Read-IntegerValue -Label 'jobs' -Default 1 -Min 1
    $coverage = Read-YesNo -Label 'coverage' -Default $false
    $listOnly = Read-YesNo -Label 'list-only (TEST_LIST)' -Default $false

    return @{
        Scope = $scope
        Category = $category
        Selector = $selector
        FailFast = $failFast
        Jobs = $jobs
        Coverage = $coverage
        ListOnly = $listOnly
    }
}

function New-CommandSpec {
    param([hashtable]$State)

    $envVars = New-BaseEnvVars $State.Stack

    switch ($State.Action) {
        'doctor' {
            return @{
                ActionLabel = 'doctor'
                Summary = @(
                    'Acción: doctor',
                    ('Stack: {0}' -f $State.Stack)
                )
                CommandPath = $TestkitBin
                CommandArgs = @('doctor', '--dump')
                EnvVars = $envVars
            }
        }

        'up' {
            return @{
                ActionLabel = 'up'
                Summary = @(
                    'Acción: up',
                    ('Stack: {0}' -f $State.Stack)
                )
                CommandPath = $TestkitBin
                CommandArgs = @('up', '-d')
                EnvVars = $envVars
            }
        }

        'seed' {
            return @{
                ActionLabel = 'seed'
                Summary = @(
                    'Acción: seed',
                    ('Stack: {0}' -f $State.Stack),
                    'Invoca scripts/seed.ps1, que a su vez usa el CLI actual.'
                )
                CommandPath = $SeedScript
                CommandArgs = @()
                EnvVars = $envVars
            }
        }

        'run_tests' {
            $envVars['TEST_SCOPE'] = $State.Scope
            $envVars['TEST_CATEGORY'] = $State.Category
            $envVars['TEST_MATCH'] = $State.Selector
            $envVars['TEST_FAIL_FAST'] = (ConvertTo-BoolString $State.FailFast)
            $envVars['TEST_JOBS'] = [string]$State.Jobs
            $envVars['TEST_COVERAGE'] = (ConvertTo-BoolString $State.Coverage)
            $envVars['TEST_LIST'] = (ConvertTo-BoolString $State.ListOnly)

            $selectorText = if ([string]::IsNullOrWhiteSpace($State.Selector)) { '(vacío)' } else { $State.Selector }

            return @{
                ActionLabel = 'run tests'
                Summary = @(
                    'Acción: run tests',
                    ('Stack: {0}' -f $State.Stack),
                    ('Target: {0}' -f $State.Target),
                    ('scope={0} | category={1} | TEST_MATCH={2}' -f $State.Scope, $State.Category, $selectorText),
                    ('fail-fast={0} | jobs={1} | coverage={2} | list-only={3}' -f (ConvertTo-BoolString $State.FailFast), $State.Jobs, (ConvertTo-BoolString $State.Coverage), (ConvertTo-BoolString $State.ListOnly))
                )
                CommandPath = $TestkitBin
                CommandArgs = @('run', '--rm', 'testkit', 'php', '/workspace/testkit/runTest.php', $State.Target)
                EnvVars = $envVars
            }
        }

        'report' {
            return @{
                ActionLabel = 'report'
                Summary = @(
                    'Acción: report',
                    ('Stack: {0}' -f $State.Stack)
                )
                CommandPath = $TestkitBin
                CommandArgs = @('run', '--rm', 'testkit', 'php', '/workspace/testkit/scripts/report.php')
                EnvVars = $envVars
            }
        }

        'down' {
            return @{
                ActionLabel = 'down'
                Summary = @(
                    'Acción: down',
                    ('Stack: {0}' -f $State.Stack),
                    'down aplica sobre el stack seleccionado.'
                )
                CommandPath = $TestkitBin
                CommandArgs = @('down')
                EnvVars = $envVars
            }
        }

        default {
            throw ('Acción no soportada: {0}' -f $State.Action)
        }
    }
}

function Show-CommandPreview {
    param([hashtable]$Spec)

    Write-Host ''
    Write-Host '== Resumen interactivo =='
    Write-Host 'Modo: interactivo'
    foreach ($line in $Spec.Summary) {
        Write-Host ('- ' + $line)
    }

    Write-Host ''
    Write-Host 'CLI real:'
    Write-Host ('  ' + (Format-CommandLine -CommandPath $Spec.CommandPath -CommandArgs $Spec.CommandArgs))

    Write-Host ''
    Write-Host 'Bloque PowerShell reproducible:'
    Write-Host '  & {'
    Write-Host ('      Set-Location ' + (Quote-PowerShellLiteral $RepoRoot))
    foreach ($entry in $Spec.EnvVars.GetEnumerator()) {
        Write-Host ('      $env:{0} = {1}' -f $entry.Key, (Quote-PowerShellLiteral ([string]$entry.Value)))
    }
    Write-Host ('      ' + (Format-CommandLine -CommandPath $Spec.CommandPath -CommandArgs $Spec.CommandArgs))
    Write-Host '  }'
}

function Invoke-CommandSpec {
    param([hashtable]$Spec)

    $envSnapshot = @{}
    foreach ($entry in $Spec.EnvVars.GetEnumerator()) {
        $existing = [Environment]::GetEnvironmentVariable($entry.Key)
        $envSnapshot[$entry.Key] = [PSCustomObject]@{
            Exists = ($null -ne $existing)
            Value = $existing
        }
    }

    $originalLocation = (Get-Location).Path

    try {
        Set-Location $RepoRoot

        foreach ($entry in $Spec.EnvVars.GetEnumerator()) {
            Set-Item -Path ('Env:{0}' -f $entry.Key) -Value ([string]$entry.Value)
        }

        & $Spec.CommandPath @($Spec.CommandArgs)
        return $LASTEXITCODE
    }
    finally {
        foreach ($name in $envSnapshot.Keys) {
            $snapshot = $envSnapshot[$name]
            if ($snapshot.Exists) {
                Set-Item -Path ('Env:{0}' -f $name) -Value $snapshot.Value
            }
            else {
                Remove-Item -Path ('Env:{0}' -f $name) -ErrorAction SilentlyContinue
            }
        }

        Set-Location $originalLocation
    }
}

function New-InteractiveState {
    $stack = Read-ChoiceValue -Title 'stack' -Choices (Get-StackChoices) -DefaultValue 'mysql,redis'
    $action = Read-ChoiceValue -Title 'acción' -Choices (Get-ActionChoices) -DefaultValue 'run_tests'

    $state = @{
        Action = $action
        Stack = $stack
    }

    if ($action -eq 'run_tests') {
        $state['Target'] = Read-ChoiceValue -Title 'target' -Choices (Get-TargetChoices) -DefaultValue 'all'
        $filters = New-RunFilters
        $state['Scope'] = $filters.Scope
        $state['Category'] = $filters.Category
        $state['Selector'] = $filters.Selector
        $state['FailFast'] = $filters.FailFast
        $state['Jobs'] = $filters.Jobs
        $state['Coverage'] = $filters.Coverage
        $state['ListOnly'] = $filters.ListOnly
    }

    return $state
}

Write-Host '== TESTKIT UI =='
Write-Host 'Modo: interactivo'
Write-Host 'Esta UI arma un comando reproducible y luego ejecuta el flujo actual de TestKit.'
Write-Host 'El modo no interactivo sigue siendo bin/testkit.ps1 y los wrappers existentes.'

$state = New-InteractiveState
$spec = New-CommandSpec $state
Show-CommandPreview $spec

$execute = Read-YesNo -Label '¿Ejecutar este comando ahora?' -Default $true
if (-not $execute) {
    Write-Host 'Cancelado. No se ejecutó nada.'
    exit 0
}

$exitCode = Invoke-CommandSpec $spec
if ($null -eq $exitCode) { $exitCode = 0 }
exit $exitCode