Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
. (Join-Path $scriptRoot 'lib\Testkit.UI.Console.ps1')
. (Join-Path $scriptRoot 'lib\Testkit.UI.Plan.ps1')

function Start-TestkitInteractiveUi {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [string]$RepositoryRoot
    )

    if ($PSVersionTable.PSVersion.Major -lt 5) {
        throw 'Esta UI requiere PowerShell 5.x o superior.'
    }

    $catalog = Get-TestkitUiCatalog
    $paths = Resolve-TestkitUiPaths -RepositoryRoot $RepositoryRoot

    Clear-Host
    Write-UiBanner -Title 'testkit UI interactiva (PowerShell)' -Lines @(
        'Estas en modo interactivo para humanos.',
        'El modo canonical y no interactivo sigue siendo .\bin\testkit.ps1 y los wrappers existentes.',
        'Esta UI no reemplaza el CLI: lo envuelve, te muestra el comando real y te pide confirmacion antes de ejecutar.'
    )

    $selection = New-TestkitUiSelection -Catalog $catalog
    $selection.Action = Select-UiMenuOption -Title 'Accion' -Options $catalog.Actions -DefaultIndex 3
    if ($null -eq $selection.Action) {
        Write-UiWarning -Message 'Cancelado por el usuario antes de seleccionar accion.'
        return
    }

    $needsStackPrompt = $selection.Action.Key -in @('up', 'seed', 'run-tests')
    if ($needsStackPrompt) {
        Write-UiInfo -Message 'Se pide stack porque forma parte del lenguaje operativo esperado, pero esta UI no inventa su traduccion tecnica si el contrato real no esta expuesto.'
        $selection.Stack = Select-UiMenuOption -Title 'Stack' -Options $catalog.Stacks -DefaultIndex 0 -AllowSkip -SkipLabel 'No seleccionar stack'
        if ($selection.Action.Key -eq 'run-tests') {
            Write-UiInfo -Message 'La seleccion de stack queda visible en el resumen, pero no se exporta como env var/flag sin contrato real comprobable.'
        }
    }

    if ($selection.Action.Key -eq 'run-tests') {
        $selection.Target = Select-UiMenuOption -Title 'Target' -Options $catalog.Targets -DefaultIndex 0
        if ($null -eq $selection.Target) {
            Write-UiWarning -Message 'Cancelado por el usuario antes de seleccionar target.'
            return
        }

        $selection.Scope = Select-UiMenuOption -Title 'Scope' -Options $catalog.Scopes -DefaultIndex 0
        if ($null -eq $selection.Scope) {
            Write-UiWarning -Message 'Cancelado por el usuario antes de seleccionar scope.'
            return
        }

        $selection.Category = Select-UiMenuOption -Title 'Category' -Options $catalog.Categories -DefaultIndex 0
        if ($null -eq $selection.Category) {
            Write-UiWarning -Message 'Cancelado por el usuario antes de seleccionar category.'
            return
        }

        Write-UiSection -Title 'Filtros adicionales'
        Write-UiInfo -Message 'Selector libre -> usa TEST_MATCH. No se inventan TEST_PATH ni TEST_MODULE.'
        $selection.Match = Read-UiText -Prompt 'TEST_MATCH (selector libre por match/path/modulo si el runner ya lo soporta)' -AllowEmpty
        $selection.FailFast = Read-UiYesNo -Prompt 'Activar fail-fast (TEST_FAIL_FAST)' -Default:$false
        $selection.Jobs = Read-UiPositiveInt -Prompt 'TEST_JOBS (entero positivo, vacio para no emitir)' -AllowEmpty
        $selection.Coverage = Read-UiYesNo -Prompt 'Activar coverage (TEST_COVERAGE)' -Default:$false
        $selection.ListOnly = Read-UiYesNo -Prompt 'List-only (TEST_LIST)' -Default:$false
    }

    $plan = Build-TestkitExecutionPlan -Selection $selection -Paths $paths

    Write-UiSection -Title 'Resumen de seleccion'
    Write-UiKeyValueTable -Rows $plan.SummaryRows

    if ($plan.Notes.Count -gt 0) {
        Write-UiSection -Title 'Notas de honestidad del wrapper'
        Write-UiIndentedBlock -Lines $plan.Notes
    }

    Write-UiSection -Title 'Comando real final'
    Write-UiIndentedBlock -Lines @($plan.Command)

    Write-UiSection -Title 'Bloque PowerShell reproducible'
    Write-UiIndentedBlock -Lines $plan.ReproBlock

    $confirm = Read-UiYesNo -Prompt 'Confirmar ejecucion' -Default:$false
    if (-not $confirm) {
        Write-UiWarning -Message 'Cancelado. No se ejecuto ningun comando.'
        return
    }

    Write-UiSection -Title 'Ejecucion'
    $exitCode = Invoke-TestkitExecutionPlan -Plan $plan

    if ($null -eq $exitCode) {
        $exitCode = 0
    }

    if ([int]$exitCode -eq 0) {
        Write-UiSuccess -Message 'Ejecucion finalizada con exit code 0.'
    }
    else {
        Write-UiErrorLine -Message ('Ejecucion finalizada con exit code ' + $exitCode + '.')
    }
}