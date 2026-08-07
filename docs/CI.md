# CI de testKit

Este documento describe el workflow principal `CI` de `testKit` y cómo reproducir sus validaciones principales en local.

## Objetivo

El CI valida `testKit` como plataforma compartida de testing, separando estas superficies:

1. sintaxis y estructura sin Docker;
2. contratos internos del framework sin Docker;
3. ruta Windows/PowerShell;
4. runtime Docker/MySQL contra un host fixture controlado;
5. smoke mínimo del runner Browser E2E reusable.

El workflow no usa secrets, no depende de aplicaciones host externas y mantiene permisos mínimos:

```yaml
permissions:
  contents: read
```

Los jobs host fijan versiones de herramientas:

- PHP `8.4` mediante `shivammathur/setup-php@v2`;
- Node `20` mediante `actions/setup-node@v4`.

## Contrato de selectores en CI

Toda invocación de `runTest.php` desde CI debe declarar exactamente uno de:

```text
--suite
--group
--category
```

No se aceptan targets posicionales, `TEST_TARGET`, `TESTKIT_TARGET_*` ni `doctor --target`.

El gate `tests/framework/test_ci_typed_selectors.php`, ejecutado por `php tests/framework/run.php`, falla si el workflow vuelve a introducir esas superficies legacy o si pierde los comandos tipados y el host fixture esperados del runtime MySQL.

## Contrato de store en CI

`TEST_STORE_DRIVER` es el único selector del store estructural. El workflow debe declararlo explícitamente como `mysql`, `pgsql` o `none` según el job.

No se admite seleccionar el motor mediante `DB_DRIVER`, `TEST_DB_DRIVER`, DSN, credenciales, nombres de DB o `TESTKIT_STACK`.

El gate `tests/framework/test_store_driver_contract.php` protege esta superficie junto con runtime, doctor, scripts, schema, ejemplos y CI.

## Jobs blocking

### `static`

Valida estructura básica sin Docker.

Controles:

- checkout con `actions/checkout@v4`;
- PHP 8.4 explícito;
- Node 20 explícito;
- existencia de archivos críticos;
- sintaxis PHP con `php -l`, excluyendo `vendor/`;
- sintaxis Bash con `bash -n` sobre `bin`, `scripts` y `lib`;
- sintaxis Node/ESM sobre runners, utils y fixtures browser.

### `framework-self-tests`

Ejecuta:

```bash
php tests/framework/run.php
```

Depende de `static` y valida contratos internos del framework, incluidos store explícito y selectores tipados de CI.

### `windows-static`

Valida la ruta Windows/PowerShell sin Docker en `windows-latest`.

Incluye:

- parseo de todos los `.ps1`/`.psm1`;
- detección de CRLF en scripts Linux críticos;
- `tests/powershell/run.ps1`;
- `php tests/framework/run.php` también sobre Windows.

No cubre Docker Desktop ni runtime MySQL sobre Windows.

### `runtime-mysql`

Valida el camino runtime principal con Docker y MySQL usando un **host fixture separado de TestKit**.

Fixture canónico:

```text
tests/fixtures/runtime-mysql-host
```

El fixture contiene:

```text
test/seeds/mysql/001_runtime_probe.sql
test/back/runtime_mysql_store.test.php
```

El seed crea un dato observable y el test lo consulta mediante PDO. Por lo tanto, el gate prueba bootstrap, seed, conexión y persistencia real; no se limita a imprimir `PASS`.

El job define:

```yaml
TESTKIT_STACK: mysql
TESTKIT_PROJECT_ROOT: ${{ github.workspace }}/tests/fixtures/runtime-mysql-host
TESTKIT_ENV_FILE: ${{ github.workspace }}/tests/fixtures/runtime-mysql-host/.env.test
```

El env se deriva de `.env.test.example` y fija explícitamente:

```env
TESTKIT_STACK=mysql
TEST_STORE_DRIVER=mysql
TEST_STORE_PROVISION=managed
TEST_DB_STRATEGY=shared
TEST_JOBS=1
```

Secuencia principal:

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

`migration-contract` no se usa en este gate porque exige una fuente snapshot resoluble. Ese contrato debe validarse con un fixture snapshot específico, no con el env genérico de runtime MySQL.

El job captura diagnósticos y artifacts aunque falle una etapa previa.

### `browser-runner-smoke`

Valida la superficie mínima de `runners/runBrowserE2e.mjs` sin depender de una aplicación externa.

Usa:

- `TEST_STORE_DRIVER=none`;
- `TEST_STORE_PROVISION=external`;
- `TESTKIT_STACK=` vacío;
- fixture HTML local;
- Chromium headless dentro del contenedor TestKit.

## Qué queda fuera del CI principal

No son blocking actualmente:

- PostgreSQL runtime;
- Redis como servicio obligatorio;
- Influx como profiling obligatorio;
- coverage extendido;
- performance/stress;
- runtime Docker sobre Windows;
- `migration-contract` con snapshot real.

## Reproducción local de contratos estáticos

Desde la raíz de `testKit`:

```bash
git status --short

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

## Reproducción local de `windows-static`

Desde PowerShell 7:

```powershell
$ErrorActionPreference = 'Stop'

Get-ChildItem bin,lib,ui,tests\powershell -Recurse -Include *.ps1,*.psm1 |
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

## Reproducción local del runtime MySQL

Desde la raíz de `testKit`:

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
```

Teardown obligatorio:

```bash
./bin/testkit down -v --remove-orphans

unset TESTKIT_PROJECT_ROOT
unset TESTKIT_ENV_FILE
unset TESTKIT_STACK

rm -f "$FIXTURE/.env.i2-runtime"
rm -rf \
  "$FIXTURE/.testkit" \
  "$FIXTURE/reports" \
  "$FIXTURE/coverage" \
  "$FIXTURE/test/coverage"

git status --short
```

Criterio mínimo de PASS:

```text
Doctor: OK
Seeds aplicadas: 1
runtime_mysql_store.test.php descubierto
PASS=1 FAIL=0
inspect latest con evidence_valid=true
teardown sin contenedores testkit residuales
```

## Reproducción local del browser smoke

```bash
cp .env.test.example .env.test
sed -i 's/^TESTKIT_STACK=.*/TESTKIT_STACK=/' .env.test
sed -i 's/^TEST_STORE_DRIVER=.*/TEST_STORE_DRIVER=none/' .env.test
sed -i 's/^TEST_STORE_PROVISION=.*/TEST_STORE_PROVISION=external/' .env.test

mkdir -p test-results/browser
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
    php -S 127.0.0.1:4173 -t tests/fixtures/browser/public \
      > /workspace/project/test-results/browser/fixture-server.log 2>&1 &
    server_pid="$!"
    trap "kill ${server_pid} >/dev/null 2>&1 || true" EXIT

    for i in $(seq 1 50); do
      if curl -fsS http://127.0.0.1:4173/health.json >/dev/null; then
        break
      fi
      sleep 0.2
    done

    node /workspace/testkit/runners/runBrowserE2e.mjs tests/fixtures/browser/smoke.spec.mjs
  '
```

## Artifacts de diagnóstico

### `runtime-mysql`

Revisar primero:

- `ci-artifacts/ci-testkit-ps.txt`;
- `ci-artifacts/ci-docker-logs.txt`;
- `ci-artifacts/ci-runtest-list.txt`;
- `ci-artifacts/ci-runtest-all.txt`;
- `ci-artifacts/ci-inspect-latest.txt`;
- `tests/fixtures/runtime-mysql-host/.testkit/**`.

### `browser-runner-smoke`

Revisar:

- `test-results/browser/fixture-server.log`;
- screenshots y traces cuando haya fallo.

## Riesgos conocidos

- `runtime-mysql` usa `--group all` sobre un fixture deliberadamente mínimo. Si se agregan suites al fixture, deben conservarse deterministas y cerradas.
- El fixture runtime es parte del contrato CI; no debe convertirse en una aplicación paralela ni acumular lógica de dominio.
- `migration-contract` necesita un fixture snapshot propio; no debe reintroducirse en el gate genérico.
- El workflow no hace obligatorios PostgreSQL, Redis ni Influx.
- La validación local no sustituye una corrida real de GitHub Actions sobre el SHA candidato.
