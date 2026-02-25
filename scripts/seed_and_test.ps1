Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

param(
    # Target del meta-runner (test/runTest.php)
    # Valores soportados: all|back|front|front-php|front-js|php|js
    [Parameter(Position = 0)]
    [ValidateSet('all', 'back', 'front', 'front-php', 'front-js', 'php', 'js')]
    [string]$Target = 'all',

    # Modo DB:
    # - local : XAMPP MySQL + PHP XAMPP
    # - docker: Docker TestKit (mysql_test + contenedor testkit)
    # - auto : usa TEST_DB_MODE si existe; si no, autodetecta por bin/testkit(.ps1)
    [Parameter(Position = 1)]
    [ValidateSet('auto', 'local', 'docker')]
    [string]$Mode = 'auto'
)

# =============================================================================
# /test/scripts/seed_and_test.ps1
#
# Orquestador único:
#   1) Aplica seeds
#   2) Ejecuta tests
#
# Inputs:
#   - Env de tests: <repo>/test/.env.test (preferido) o <repo>/.env.test
#   - Seeds SQL:    <repo>/test/seeds/mysql/*.sql
#   - Meta-runner:  <repo>/test/runTest.php
#
# Selección de modo:
#   - Argumento $Mode
#   - o env TEST_DB_MODE=local|docker
# =============================================================================

# -----------------------------------------------------------------------------
# 1) Paths base
# -----------------------------------------------------------------------------
$ScriptsDir = Split-Path -Parent $MyInvocation.MyCommand.Path           # <repo>/test/scripts
$TestRoot = Resolve-Path (Join-Path $ScriptsDir "..")                 # <repo>/test
$RepoRoot = Resolve-Path (Join-Path $TestRoot "..")                   # <repo>

$EnvFilePrimary = Join-Path $TestRoot ".env.test"
$EnvFileAlt = Join-Path $RepoRoot ".env.test"

$SeedsDir = Join-Path $TestRoot "seeds/mysql"
$MetaRunner = Join-Path $TestRoot "runTest.php"

# -----------------------------------------------------------------------------
# 2) Utilidades (logging + env loader KEY=VALUE)
# -----------------------------------------------------------------------------
function Log([string]$msg) { Write-Host $msg }
function Fail([string]$msg) { Write-Error $msg; exit 1 }

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
        if ($line -eq "" -or $line.StartsWith("#")) { return }
        if ($line -match '^[A-Za-z_][A-Za-z0-9_]*=') {
            $pair = $line.Split('=', 2)
            $k = $pair[0]
            $v = $pair[1]
            if ($v.Length -ge 2 -and (($v.StartsWith('"') -and $v.EndsWith('"')) -or ($v.StartsWith("'") -and $v.EndsWith("'")))) {
                $v = $v.Substring(1, $v.Length - 2)
            }
            Set-Item -Path ("Env:{0}" -f $k) -Value $v
        }
    }
}

function Resolve-Mode([string]$requested) {
    $m = $requested.ToLower().Trim()
    if ($m -eq 'auto') {
        if ($env:TEST_DB_MODE) {
            $m = $env:TEST_DB_MODE.ToLower().Trim()
        }
        else {
            # Heurística: si existe bin/testkit o bin/testkit.ps1 en el repo => docker
            $tk1 = Join-Path $RepoRoot "bin/testkit"
            $tk2 = Join-Path $RepoRoot "bin/testkit.ps1"
            if ((Test-Path $tk1) -or (Test-Path $tk2)) { $m = 'docker' } else { $m = 'local' }
        }
    }
    if ($m -ne 'local' -and $m -ne 'docker') {
        Fail ("TEST_DB_MODE inválido: {0}. Valores: local|docker|auto" -f $m)
    }
    return $m
}

# -----------------------------------------------------------------------------
# 3) Cargar env (source of truth) + publicar DB_ENV_PATH para runners
# -----------------------------------------------------------------------------
$EnvFile = Pick-EnvFile
if (-not $EnvFile) {
    Fail "Falta env de tests. Se busca: <repo>/test/.env.test (preferido) o <repo>/.env.test."
}
Load-EnvKVSafe $EnvFile
Set-Item -Path "Env:DB_ENV_PATH" -Value $EnvFile

$Mode = Resolve-Mode $Mode
Log ("==> Mode: {0} | Target: {1} | Env: {2}" -f $Mode, $Target, $EnvFile)

# -----------------------------------------------------------------------------
# 4) DOCKER: pipeline mediante wrappers (útil CI + uso manual)
# -----------------------------------------------------------------------------
function Run-DockerMode {
    $dbReset = Join-Path $ScriptsDir "db_reset.ps1"
    $seed = Join-Path $ScriptsDir "seed.ps1"
    $test = Join-Path $ScriptsDir "test.ps1"

    if (-not (Test-Path $dbReset)) { Fail "Falta script docker: $dbReset" }
    if (-not (Test-Path $seed)) { Fail "Falta script docker: $seed" }
    if (-not (Test-Path $test)) { Fail "Falta script docker: $test" }

    Log "==> DOCKER: db_reset"
    & $dbReset
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

    Log "==> DOCKER: seed"
    & $seed
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

    Log ("==> DOCKER: test ({0})" -f $Target)
    & $test $Target
    exit $LASTEXITCODE
}

# -----------------------------------------------------------------------------
# 5) LOCAL: seeds por mysql.exe y tests por php.exe (XAMPP)
# -----------------------------------------------------------------------------
function Run-LocalMode {
    if (-not (Test-Path $SeedsDir)) { Fail "Falta carpeta de seeds: $SeedsDir" }
    if (-not (Test-Path $MetaRunner)) { Fail "Falta meta-runner: $MetaRunner" }

    # Binarios (override por env)
    $MysqlBin = $env:MYSQL_BIN; if (-not $MysqlBin) { $MysqlBin = "C:\xampp\mysql\bin\mysql.exe" }
    $PhpBin = $env:PHP_BIN; if (-not $PhpBin) { $PhpBin = "C:\xampp\php\php.exe" }

    if (-not (Test-Path $MysqlBin)) { Fail "No existe MYSQL_BIN: $MysqlBin" }
    if (-not (Test-Path $PhpBin)) { Fail "No existe PHP_BIN: $PhpBin" }

    # DB (prioridad: DB_*; fallback: TEST_MYSQL_DB)
    $dbHost = $env:DB_HOST; if (-not $dbHost) { $dbHost = "127.0.0.1" }
    $dbPort = $env:DB_PORT; if (-not $dbPort) { $dbPort = "3306" }

    $dbName = $env:DB_NAME
    if (-not $dbName) { $dbName = $env:TEST_MYSQL_DB }
    if (-not $dbName) { $dbName = "app_test" }

    $dbUser = $env:DB_USER; if (-not $dbUser) { $dbUser = "root" }
    $dbPass = $env:DB_PASS

    $mysqlArgs = @("--protocol=tcp", "-h", $dbHost, "-P", $dbPort, "-u", $dbUser)
    if ($dbPass -ne $null -and $dbPass -ne "") { $mysqlArgs += ("-p{0}" -f $dbPass) }

    Log ("==> Seeding LOCAL MySQL: {0}@{1}:{2} / db={3}" -f $dbUser, $dbHost, $dbPort, $dbName)

    ("CREATE DATABASE IF NOT EXISTS `{0}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" -f $dbName) |
    & $MysqlBin @mysqlArgs
    if ($LASTEXITCODE -ne 0) { Fail ("mysql falló creando DB (exit={0})" -f $LASTEXITCODE) }

    Get-ChildItem -Path $SeedsDir -Filter "*.sql" | Sort-Object Name | ForEach-Object {
        Log ("   - {0}" -f $_.Name)
        (Get-Content -Raw $_.FullName) | & $MysqlBin @mysqlArgs $dbName
        if ($LASTEXITCODE -ne 0) { Fail ("mysql falló aplicando seed: {0} (exit={1})" -f $_.Name, $LASTEXITCODE) }
    }

    Log ("==> Running tests (LOCAL): target={0}" -f $Target)
    & $PhpBin $MetaRunner $Target
    exit $LASTEXITCODE
}

# -----------------------------------------------------------------------------
# 6) Dispatch
# -----------------------------------------------------------------------------
if ($Mode -eq 'docker') { Run-DockerMode } else { Run-LocalMode }