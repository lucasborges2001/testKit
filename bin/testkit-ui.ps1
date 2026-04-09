[CmdletBinding()]
param()

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'

$entrypointDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
$repositoryRoot = (Resolve-Path (Join-Path $entrypointDirectory '..')).Path
$uiScript = Join-Path $repositoryRoot 'ui\powershell\Testkit.UI.ps1'

if (-not (Test-Path -LiteralPath $uiScript)) {
    throw "Interactive UI bootstrap not found: $uiScript"
}

. $uiScript
Start-TestkitInteractiveUi -RepositoryRoot $repositoryRoot