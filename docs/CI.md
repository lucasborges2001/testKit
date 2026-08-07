# CI de testKit

Este documento describe el workflow principal `CI` de `testKit`, su contrato vigente y cómo reproducir sus gates principales antes de habilitarlo o usarlo como evidencia de cierre.

## Objetivo

El CI valida cinco superficies separadas:

1. sintaxis y estructura sin Docker;
2. contratos internos del framework;
3. ruta Windows/PowerShell;
4. runtime Docker/MySQL contra un host fixture controlado;
5. smoke del runner Browser E2E contra un host fixture separado.

El workflow mantiene permisos mínimos:

```yaml
permissions:
  contents: read
```

## Baseline de plataforma

El baseline normalizado usa:

- Ubuntu `24.04` para jobs Linux;
- `windows-2025` para evitar la deriva implícita de `windows-latest`;
- PHP `8.4` mediante `shivammathur/setup-php@v2`;
- Node `24` LTS mediante `actions/setup-node@v7`;
- `actions/checkout@v7`;
- `actions/upload-artifact@v7`.

La imagen Docker reusable mantiene el mismo baseline Node `24` y fija Playwright `1.61.0`.

## Contrato de selectores

Toda invocación de `runTest.php` desde CI declara exactamente uno de:

```text
--suite
--group
--category
```

No se aceptan targets posicionales, `TEST_TARGET`, `TESTKIT_TARGET_*` ni `doctor --target`.

El gate `tests/framework/test_ci_typed_selectors.php` protege este contrato y también evita volver a introducir el baseline CI anterior.

## Contrato de store

`TEST_STORE_DRIVER` es el único selector del store estructural. Valores exactos:

```text
mysql
pgsql
none
```

`DB_DRIVER`, `TEST_DB_DRIVER`, DSN, nombres de DB y `TESTKIT_STACK` no seleccionan store.

## Jobs

### `static`

Runner:

```text
ubuntu-24.04
```

Valida:

- archivos críticos;
- PHP syntax;
- Bash syntax;
- sintaxis de módulos JS con Node `24` LTS.

### `framework-self-tests`

Ejecuta:

```bash
php tests/framework/run.php
```

Depende de `static`.

### `windows-static`

Runner fijado:

```text
windows-2025
```

Ejecuta:

1. verificación de archivos PowerShell requeridos;
2. parseo de `.ps1`/`.psm1` bajo `bin`, `lib`, `ui`, `scripts` y `tests/powershell`;
3. chequeo CRLF de scripts Linux críticos;
4. `tests/powershell/run.ps1`;
5. `php tests/framework/run.php`.

El harness PowerShell incluye el contrato explícito de store para `seed.ps1` y `db_clean.ps1`: ausencia e inválido fallan antes de Docker, y `none` termina sin runtime.

Este job no valida Docker Desktop ni MySQL sobre Windows.

### `runtime-mysql`

Usa un host fixture separado de TestKit:

```text
tests/fixtures/runtime-mysql-host
```

Variables del job:

```yaml
TESTKIT_STACK: mysql
TESTKIT_PROJECT_ROOT: ${{ github.workspace }}/tests/fixtures/runtime-mysql-host
TESTKIT_ENV_FILE: ${{ github.workspace }}/tests/fixtures/runtime-mysql-host/.env.test
```

El fixture contiene:

```text
test/seeds/mysql/001_runtime_probe.sql
test/back/runtime_mysql_store.test.php
```

El seed escribe un dato observable y el test lo consulta mediante PDO. La secuencia es:

```bash
./bin/testkit doctor --compact
./bin/testkit doctor --full --suite back-php
./bin/testkit up -d
./bin/testkit ps
./scripts/seed.sh
./bin/testkit run --rm testkit php runTest.php --group all --list
./bin/testkit run --rm testkit php runTest.php --group all
./bin/testkit inspect latest
./bin/testkit down -v --remove-orphans
```

`migration-contract` no forma parte de este gate porque requiere snapshot resoluble.

### `browser-runner-smoke`

Usa un host fixture no-store separado:

```text
tests/fixtures/browser
```

Variables del job:

```yaml
TESTKIT_STACK: ""
TESTKIT_PROJECT_ROOT: ${{ github.workspace }}/tests/fixtures/browser
TESTKIT_ENV_FILE: ${{ github.workspace }}/tests/fixtures/browser/.env.test
```

El env temporal fuerza:

```env
TEST_STORE_DRIVER=none
TEST_STORE_PROVISION=external
```

El contenedor TestKit monta ese fixture como `/workspace/project`, sirve `/workspace/project/public`, ejecuta el runner reusable desde `/workspace/testkit/runners/runBrowserE2e.mjs` y resuelve `smoke.spec.mjs` desde el host fixture.

Artifacts:

```text
tests/fixtures/browser/test-results/**
tests/fixtures/browser/playwright-report/**
```

El env temporal se elimina siempre al finalizar.

## Qué queda fuera del workflow principal

No son blocking actualmente:

- PostgreSQL runtime;
- Redis obligatorio;
- Influx obligatorio;
- coverage extendido;
- performance/stress;
- runtime Docker sobre Windows;
- `migration-contract` con snapshot real.

## Reproducción local — contratos estáticos

```bash
php tests/framework/test_ci_typed_selectors.php
php tests/framework/test_store_driver_contract.php
php tests/framework/run.php

find . -type f -name '*.php' \
  -not -path './vendor/*' \
  -print0 | xargs -0 -r -n1 php -l

find bin scripts lib -type f \
  \( -name '*.sh' -o -name 'testkit' \) \
  -print0 | xargs -0 -r -n1 bash -n
```

## Reproducción local — PowerShell

Desde PowerShell 7:

```powershell
$ErrorActionPreference = 'Stop'

Get-ChildItem bin,lib,ui,scripts,tests\powershell -Recurse -Include *.ps1,*.psm1 |
  ForEach-Object {
    $parseErrors = $null
    [System.Management.Automation.Language.Parser]::ParseFile(
      $_.FullName, [ref]$null, [ref]$parseErrors
    ) | Out-Null
    if ($parseErrors.Count -gt 0) {
      throw "Parse error: $($_.FullName)"
    }
  }

pwsh -NoProfile -NonInteractive -File tests\powershell\run.ps1
php tests\framework\run.php
```

## Reproducción local — runtime MySQL

```bash
ROOT="$PWD"
FIXTURE="$ROOT/tests/fixtures/runtime-mysql-host"

cp .env.test.example "$FIXTURE/.env.i2-runtime"
export TESTKIT_PROJECT_ROOT="$FIXTURE"
export TESTKIT_ENV_FILE="$FIXTURE/.env.i2-runtime"
export TESTKIT_STACK=mysql

./bin/testkit doctor --compact
./bin/testkit doctor --full --suite back-php
./bin/testkit up -d
./bin/testkit ps
./scripts/seed.sh
./bin/testkit run --rm testkit php runTest.php --group all --list
./bin/testkit run --rm testkit php runTest.php --group all
./bin/testkit inspect latest

./bin/testkit down -v --remove-orphans
unset TESTKIT_PROJECT_ROOT TESTKIT_ENV_FILE TESTKIT_STACK
rm -f "$FIXTURE/.env.i2-runtime"
rm -rf "$FIXTURE/.testkit" "$FIXTURE/reports" "$FIXTURE/coverage" "$FIXTURE/test/coverage"
```

## Reproducción local — browser smoke

```bash
ROOT="$PWD"
FIXTURE="$ROOT/tests/fixtures/browser"

cp .env.test.example "$FIXTURE/.env.test"
sed -i 's/^TESTKIT_STACK=.*/TESTKIT_STACK=/' "$FIXTURE/.env.test"
sed -i 's/^TEST_STORE_DRIVER=.*/TEST_STORE_DRIVER=none/' "$FIXTURE/.env.test"
sed -i 's/^TEST_STORE_PROVISION=.*/TEST_STORE_PROVISION=external/' "$FIXTURE/.env.test"

export TESTKIT_PROJECT_ROOT="$FIXTURE"
export TESTKIT_ENV_FILE="$FIXTURE/.env.test"
export TESTKIT_STACK=

mkdir -p "$FIXTURE/test-results/browser"
./bin/testkit run --rm \
  -e TESTKIT_BROWSER_BASE_URL=http://127.0.0.1:4173 \
  -e TESTKIT_BROWSER_HEADLESS=1 \
  -e TESTKIT_BROWSER_TRACE=retain-on-failure \
  -e TESTKIT_BROWSER_SCREENSHOT=only-on-failure \
  -e TESTKIT_BROWSER_VIDEO=off \
  -e TESTKIT_BROWSER_TIMEOUT_MS=10000 \
  -e TESTKIT_BROWSER_ARTIFACTS_DIR=/workspace/project/test-results/browser \
  testkit bash -lc '
    set -euo pipefail
    mkdir -p /workspace/project/test-results/browser
    php -S 127.0.0.1:4173 -t /workspace/project/public \
      > /workspace/project/test-results/browser/fixture-server.log 2>&1 &
    server_pid="$!"
    trap "kill ${server_pid} >/dev/null 2>&1 || true" EXIT

    for i in $(seq 1 50); do
      if curl -fsS http://127.0.0.1:4173/health.json >/dev/null; then
        break
      fi
      sleep 0.2
    done

    node /workspace/testkit/runners/runBrowserE2e.mjs smoke.spec.mjs
  '

unset TESTKIT_PROJECT_ROOT TESTKIT_ENV_FILE TESTKIT_STACK
rm -f "$FIXTURE/.env.test"
```

## Reactivación

La definición del workflow puede validarse en Git sin habilitar Actions. La reactivación es una acción remota separada y debe hacerse únicamente después de que los gates locales anteriores pasen.

Una vez autorizada:

```bash
gh workflow enable ci.yml --repo lucasborges2001/testKit
gh workflow run ci.yml --repo lucasborges2001/testKit --ref main
```

No declarar CI PASS hasta observar los jobs del run nuevo.

## Riesgos conocidos

- actualizar Node/Playwright puede revelar incompatibilidades del browser runner; el smoke existe para detectarlas;
- `runtime-mysql` y browser usan fixtures deliberadamente mínimos y no deben crecer como aplicaciones paralelas;
- la validación local no sustituye la corrida real de Actions;
- el workflow deshabilitado no produce evidencia nueva aunque su YAML sea correcto.
