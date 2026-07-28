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
        'Modo interactivo para humanos.',
        'El CLI no interactivo sigue siendo la autoridad.',
        'La UI muestra el comando exacto y pide confirmacion antes de ejecutarlo.'
    )

    $selection = New-TestkitUiSelection -Catalog $catalog
    $selection.Action = Select-UiMenuOption -Title 'Accion' -Options $catalog.Actions -DefaultIndex 3
    if ($null -eq $selection.Action) {
        Write-UiWarning -Message 'Cancelado antes de seleccionar accion.'
        return
    }

    if ($selection.Action.Key -in @('up', 'seed', 'run-tests')) {
        $selection.Stack = Select-UiMenuOption -Title 'Stack' -Options $catalog.Stacks -DefaultIndex 0 -AllowSkip -SkipLabel 'No seleccionar stack'
    }

    if ($selection.Action.Key -eq 'run-tests') {
        $selection.Selector = Select-UiMenuOption -Title 'Selector tipado' -Options $catalog.Selectors -DefaultIndex 0
        if ($null -eq $selection.Selector) {
            Write-UiWarning -Message 'Cancelado antes de seleccionar suite, group o category.'
            return
        }

        $selection.Scope = Select-UiMenuOption -Title 'Scope' -Options $catalog.Scopes -DefaultIndex 0
        if ($null -eq $selection.Scope) {
            Write-UiWarning -Message 'Cancelado antes de seleccionar scope.'
            return
        }

        Write-UiSection -Title 'Filtros adicionales'
        if ($selection.Selector.Kind -eq 'suite') {
            $selection.TestPath = Read-UiText -Prompt '--test (ruta repo-relative, vacio para toda la suite)' -AllowEmpty
        }
        else {
            $selection.TestPath = ''
            Write-UiInfo -Message '--test solo esta disponible cuando el selector es --suite.'
        }
        $selection.FailFast = Read-UiYesNo -Prompt 'Activar fail-fast (TEST_FAIL_FAST)' -Default:$false
        $selection.Jobs = Read-UiPositiveInt -Prompt 'TEST_JOBS (entero positivo, vacio para no emitir)' -AllowEmpty
        $selection.Coverage = Read-UiYesNo -Prompt 'Activar coverage (TEST_COVERAGE)' -Default:$false
        $selection.ListOnly = Read-UiYesNo -Prompt 'List-only (--list)' -Default:$false
    }

    $plan = Build-TestkitExecutionPlan -Selection $selection -Paths $paths

    Write-UiSection -Title 'Resumen de seleccion'
    Write-UiKeyValueTable -Rows $plan.SummaryRows

    if ($plan.Notes.Count -gt 0) {
        Write-UiSection -Title 'Notas de alcance'
        Write-UiIndentedBlock -Lines $plan.Notes
    }

    Write-UiSection -Title 'Comando real final'
    Write-UiIndentedBlock -Lines @($plan.Command)

    Write-UiSection -Title 'Bloque PowerShell reproducible'
    Write-UiIndentedBlock -Lines $plan.ReproBlock

    if (-not (Read-UiYesNo -Prompt 'Confirmar ejecucion' -Default:$false)) {
        Write-UiWarning -Message 'Cancelado. No se ejecuto ningun comando.'
        return
    }

    Write-UiSection -Title 'Ejecucion'
    $exitCode = Invoke-TestkitExecutionPlan -Plan $plan
    if ($null -eq $exitCode) { $exitCode = 0 }

    if ([int]$exitCode -eq 0) {
        Write-UiSuccess -Message 'Ejecucion finalizada con exit code 0.'
    }
    else {
        Write-UiErrorLine -Message ('Ejecucion finalizada con exit code ' + $exitCode + '.')
    }
}
