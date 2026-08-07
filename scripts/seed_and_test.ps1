#Requires -Version 7.0
param(
    [string]$Suite = '',
    [string]$Group = '',
    [string]$Category = '',

    [ValidateSet('auto', 'local', 'docker')]
    [string]$Mode = 'auto'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# =============================================================================
# /testkit/scripts/seed_and_test.ps1
#
# Orquestador PowerShell:
#   1) resetea/provisiona según modo;
#   2) aplica seeds;
#   3) ejecuta runTest.php con exactamente un selector tipado.
#
# Contrato de selección:
#   -Suite <id>
#   -Group <id>
#   -Category <id>
# =============================================================================

$ScriptsDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$TestkitRoot = Resolve-Path (Join-Path $ScriptsDir '..')

function Fail([string]$Message) {
    [Console]::Error.WriteLine($Message)
    exit 2
}

if (-not $env:TESTKIT_PROJECT_ROOT) {
    Fail 'TESTKIT_PROJECT_ROOT es obligatorio.'
}
if (-not (Test-Path $env:TESTKIT_PROJECT_ROOT)) {
    Fail "TESTKIT_PROJECT_ROOT no existe: $($env:TESTKIT_PROJECT_ROOT)"
}

$ProjectRoot = (Resolve-Path $env:TESTKIT_PROJECT_ROOT).Path
$EnvFilePrimary = Join-Path $ProjectRoot 'test\.env.test'
$EnvFileAlt = Join-Path $ProjectRoot '.env.test'
$SeedsDir = Join-Path $ProjectRoot 'test\seeds\mysql'
$MetaRunner = Join-Path $TestkitRoot 'runTest.php'

function Log([string]$Message) { Write-Host $Message }

function Pick-EnvFile {
    if ($env:TESTKIT_ENV_FILE -and (Test-Path $env:TESTKIT_ENV_FILE)) {
        return (Resolve-Path $env:TESTKIT_ENV_FILE).Path
    }
    if (Test-Path $EnvFilePrimary) { return (Resolve-Path $EnvFilePrimary).Path }
    if (Test-Path $EnvFileAlt) { return (Resolve-Path $EnvFileAlt).Path }
    return $null
}

function Load-EnvKVSafe([string]$Path) {
    if (-not (Test-Path $Path)) { return }

    Get-Content $Path | ForEach-Object {
        $line = $_.Trim()
        if ($line -eq '' -or $line.StartsWith('#')) { return }
        if ($line -match '^[A-Za-z_][A-Za-z0-9_]*=') {
            $pair = $line.Split('=', 2)
            $key = $pair[0]
            $value = $pair[1]
            if ($value.Length -ge 2 -and (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'")))) {
                $value = $value.Substring(1, $value.Length - 2)
            }

            $envPath = "Env:{0}" -f $key
            if (-not (Test-Path $envPath)) {
                Set-Item -Path $envPath -Value $value
            }
        }
    }
}

function Resolve-Mode([string]$Requested) {
    $resolved = $Requested.ToLowerInvariant().Trim()
    if ($resolved -eq 'auto') {
        if ($env:TEST_DB_MODE) {
            $resolved = $env:TEST_DB_MODE.ToLowerInvariant().Trim()
        }
        else {
            $resolved = 'docker'
        }
    }

    if ($resolved -notin @('local', 'docker')) {
        Fail "TEST_DB_MODE inválido: $resolved. Valores: local|docker|auto"
    }
    return $resolved
}

function Resolve-SelectorArgs {
    $selected = @()
    if (-not [string]::IsNullOrWhiteSpace($Suite)) {
        $selected += ,@('--suite', $Suite)
    }
    if (-not [string]::IsNullOrWhiteSpace($Group)) {
        $selected += ,@('--group', $Group)
    }
    if (-not [string]::IsNullOrWhiteSpace($Category)) {
        $selected += ,@('--category', $Category)
    }

    if ($selected.Count -ne 1) {
        Fail 'Declarar exactamente uno de -Suite, -Group o -Category.'
    }

    return [string[]]$selected[0]
}

$EnvFile = Pick-EnvFile
if (-not $EnvFile) {
    Fail 'Falta env de tests en <project>/test/.env.test o <project>/.env.test.'
}
Load-EnvKVSafe $EnvFile
Set-Item -Path 'Env:DB_ENV_PATH' -Value $EnvFile

$Mode = Resolve-Mode $Mode
$SelectorArgs = Resolve-SelectorArgs
Log ("==> Mode: {0} | Selector: {1} | Env: {2}" -f $Mode, ($SelectorArgs -join ' '), $EnvFile)

function Run-DockerMode {
    $dbReset = Join-Path $ScriptsDir 'db_reset.ps1'
    $seed = Join-Path $ScriptsDir 'seed.ps1'
    $test = Join-Path $ScriptsDir 'test.ps1'

    foreach ($required in @($dbReset, $seed, $test)) {
        if (-not (Test-Path $required)) {
            Fail "Falta script requerido: $required"
        }
    }

    Log '==> DOCKER: db_reset'
    & $dbReset
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

    Log '==> DOCKER: seed'
    & $seed
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

    Log ("==> DOCKER: test ({0})" -f ($SelectorArgs -join ' '))
    & $test @SelectorArgs
    exit $LASTEXITCODE
}

function Run-LocalMode {
    if (-not (Test-Path $SeedsDir)) { Fail "Falta carpeta de seeds: $SeedsDir" }
    if (-not (Test-Path $MetaRunner)) { Fail "Falta meta-runner: $MetaRunner" }

    $MysqlBin = $env:MYSQL_BIN
    if (-not $MysqlBin) { $MysqlBin = 'C:\xampp\mysql\bin\mysql.exe' }
    $PhpBin = $env:PHP_BIN
    if (-not $PhpBin) { $PhpBin = 'C:\xampp\php\php.exe' }

    if (-not (Test-Path $MysqlBin)) { Fail "No existe MYSQL_BIN: $MysqlBin" }
    if (-not (Test-Path $PhpBin)) { Fail "No existe PHP_BIN: $PhpBin" }

    $dbHost = $env:DB_HOST
    if (-not $dbHost) { $dbHost = '127.0.0.1' }
    $dbPort = $env:DB_PORT
    if (-not $dbPort) { $dbPort = '3306' }
    $dbName = $env:DB_NAME
    if (-not $dbName) { $dbName = $env:TEST_MYSQL_DB }
    if (-not $dbName) { $dbName = 'app_test' }
    $dbUser = $env:DB_USER
    if (-not $dbUser) { $dbUser = 'root' }
    $dbPass = $env:DB_PASS

    $mysqlArgs = @('--protocol=tcp', '-h', $dbHost, '-P', $dbPort, '-u', $dbUser)
    if ($null -ne $dbPass -and $dbPass -ne '') {
        $mysqlArgs += "-p$dbPass"
    }

    Log ("==> Seeding LOCAL MySQL: {0}@{1}:{2} / db={3}" -f $dbUser, $dbHost, $dbPort, $dbName)

    ("CREATE DATABASE IF NOT EXISTS `{0}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" -f $dbName) |
        & $MysqlBin @mysqlArgs
    if ($LASTEXITCODE -ne 0) { Fail "mysql falló creando DB (exit=$LASTEXITCODE)" }

    Get-ChildItem -Path $SeedsDir -Filter '*.sql' | Sort-Object Name | ForEach-Object {
        Log ("   - {0}" -f $_.Name)
        (Get-Content -Raw $_.FullName) | & $MysqlBin @mysqlArgs $dbName
        if ($LASTEXITCODE -ne 0) { Fail "mysql falló aplicando seed: $($_.Name) (exit=$LASTEXITCODE)" }
    }

    Log ("==> Running tests (LOCAL): {0}" -f ($SelectorArgs -join ' '))
    & $PhpBin $MetaRunner @SelectorArgs
    exit $LASTEXITCODE
}

if ($Mode -eq 'docker') {
    Run-DockerMode
}
else {
    Run-LocalMode
}
