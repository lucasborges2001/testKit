# CI de testKit

Este documento describe el workflow principal `CI` de `testKit` y cómo reproducir sus validaciones principales en local.

## Objetivo

El CI valida `testKit` como plataforma compartida de testing, separando cuatro superficies:

1. sintaxis y estructura sin Docker;
2. contratos internos del framework sin Docker;
3. runtime Docker/MySQL como camino cerrado principal;
4. smoke mínimo del runner Browser E2E reusable contra un fixture local controlado.

El workflow no usa secrets, no depende de aplicaciones host externas y mantiene permisos mínimos:

```yaml
permissions:
  contents: read
```

Los jobs usan `ubuntu-24.04` en vez de `ubuntu-latest` para reducir variabilidad de plataforma. Además, los checks host fijan versiones de herramientas:

- PHP `8.4` mediante `shivammathur/setup-php@v2`;
- Node `20` mediante `actions/setup-node@v4`.

`shivammathur/setup-php@v2` se usa solo en los jobs host que necesitan PHP sin levantar Docker. Esto mantiene `static` y `framework-self-tests` rápidos y evita usar una imagen Docker solo para lint/self-tests. La acción no requiere secrets y el workflow conserva `contents: read` como único permiso.

## Contrato de selectores en CI

Toda invocación de `runTest.php` desde CI debe declarar exactamente uno de:

```text
--suite
--group
--category
```

No se aceptan targets posicionales, `TEST_TARGET`, `TESTKIT_TARGET_*` ni `doctor --target`.

El gate `tests/framework/test_ci_typed_selectors.php`, ejecutado por `php tests/framework/run.php`, falla si el workflow vuelve a introducir esas superficies legacy o si pierde los comandos tipados esperados del runtime MySQL.

## Jobs blocking

### `static`

Valida estructura básica sin Docker.

Controles:

- checkout con `actions/checkout@v4`;
- PHP 8.4 explícito;
- Node 20 explícito;
- existencia de archivos críticos:
  - `bin/testkit`;
  - `scripts/seed.sh`;
  - `tests/framework/run.php`;
  - `runTest.php`;
- sintaxis PHP con `php -l`, excluyendo `vendor/`;
- sintaxis Bash con `bash -n` sobre `bin`, `scripts` y `lib`;
- sintaxis Node/ESM con `node --check` sobre `runners`, `utils` y fixtures browser.

Este job no levanta contenedores. Debe fallar temprano cuando se rompe la estructura mínima del framework.

### `framework-self-tests`

Ejecuta los self-tests internos del framework con PHP 8.4:

```bash
php tests/framework/run.php
```

Depende de `static`.

Este job no requiere Docker. Su objetivo es validar contratos internos como concurrencia, locks, reporting, selección de suites, contratos de seed state, contratos de ejecución y ausencia de selectores legacy en el workflow.

### `windows-static`

Valida la ruta Windows/PowerShell sin Docker, en paralelo a `static` (no
depende de él ni lo bloquea).

Controles, sobre `windows-latest`:

- checkout con `actions/checkout@v4`;
- PHP 8.4 explícito, mismo setup que los jobs Ubuntu;
- existencia de los archivos PowerShell requeridos (`bin/testkit.ps1`,
  `bin/testkit-ui.ps1`, `lib/powershell/*.ps1`, `ui/powershell/**/*.ps1`);
- parseo de **todos** los `.ps1`/`.psm1` del repo con
  `[System.Management.Automation.Language.Parser]::ParseFile`;
- detección de CRLF en `bin/testkit` y `*.sh` (además de lo que ya fija
  `.gitattributes`, esto es un chequeo redundante deliberado);
- `tests/powershell/run.ps1` — harness propio sin Pester (ver
  [`docs/WINDOWS.md`](WINDOWS.md) y el propio directorio `tests/powershell/`);
- `php tests/framework/run.php` — los mismos self-tests que corre
  `framework-self-tests`, ahora también en Windows.

Este job no levanta contenedores ni requiere Docker Desktop en el runner.
No cubre los smokes `no-store`/MySQL sobre Windows — esos quedan pendientes
porque los runners `windows-latest` de GitHub no garantizan soporte de Linux
containers.

### `runtime-mysql`

Valida el camino runtime principal con Docker y MySQL.

Depende de:

```yaml
needs: [static, framework-self-tests]
```

Esto evita gastar runtime Docker/MySQL cuando fallan sintaxis, estructura o contratos internos del framework.

El job copia `.env.test.example` a `.env.test` y fuerza explícitamente:

```env
TESTKIT_STACK=mysql
DB_DRIVER=mysql
TEST_DB_STRATEGY=shared
TEST_JOBS=1
```

Secuencia principal:

```bash
TESTKIT_STACK=mysql ./bin/testkit doctor --compact
TESTKIT_STACK=mysql ./bin/testkit doctor --full --suite migration-contract
TESTKIT_STACK=mysql ./bin/testkit up -d
TESTKIT_STACK=mysql ./bin/testkit ps
TESTKIT_STACK=mysql ./scripts/seed.sh
TESTKIT_STACK=mysql ./bin/testkit run --rm testkit php runTest.php --group all --list
TESTKIT_STACK=mysql ./bin/testkit run --rm testkit php runTest.php --group all
TESTKIT_STACK=mysql ./bin/testkit inspect latest
TESTKIT_STACK=mysql ./bin/testkit down -vc
```

`runtime-mysql` captura diagnósticos antes del teardown y sube artifacts aunque falle una etapa previa.

### `browser-runner-smoke`

Valida la superficie mínima de `runners/runBrowserE2e.mjs` sin depender de `Pruebas`, `Kiara`, `Locker` ni otra app host.

Depende de:

```yaml
needs: [static, framework-self-tests]
```

Esto evita correr el smoke del runner si los contratos internos del framework ya están rotos.

El job usa:

- `TEST_STORE_DRIVER=none`;
- `TEST_STORE_PROVISION=external`;
- `TESTKIT_STACK=` vacío;
- un fixture HTML local bajo `tests/fixtures/browser/public/`;
- un spec mínimo bajo `tests/fixtures/browser/smoke.spec.mjs`;
- el contenedor `testkit`, que instala Playwright y Chromium;
- `bash -lc` como shell explícita dentro del contenedor, porque el bloque usa `set -euo pipefail` y el repo estandariza scripts Bash.

No prueba una UI real de negocio. Solo verifica que el runner reusable pueda:

- resolver un spec ESM local;
- abrir Chromium headless;
- navegar contra una URL controlada;
- usar `expect` y `request` de Playwright;
- escribir artifacts bajo `test-results/browser`.

## Qué queda fuera del CI principal

No se implementan como blocking en este workflow:

- PostgreSQL como runtime obligatorio;
- Redis como servicio obligatorio;
- Influx como profiling obligatorio;
- coverage extendido;
- performance o stress tests.

Estas superficies pueden agregarse como workflows manuales o jobs no blocking cuando exista un contrato cerrado equivalente.

## Por qué MySQL es el runtime principal

MySQL es el único camino cerrado primario del framework en esta fase. Es el contrato que cubre provision, reset, baseline por capas, snapshot restore, clone por worker y `migration-contract`.

Por eso el CI principal usa `TESTKIT_STACK=mysql` y `DB_DRIVER=mysql` explícitamente, en vez de heredar el default histórico `mysql,redis` de `.env.test.example`.

## Por qué Postgres, Redis e Influx no son obligatorios

PostgreSQL está clasificado como parcial/experimental. No debe tratarse como equivalente a MySQL ni como camino cerrado para snapshot/clone o `migration-contract`.

Redis es auxiliar: puede estar disponible para un proyecto host, pero no participa en el lifecycle estructural del core PHP.

Influx es auxiliar/profiling: puede usarse para reporting o profiling donde esté habilitado, pero no es store driver primario ni forma parte del seed/bootstrap estructural.

## Reproducción local

Desde la raíz del repo `testKit`:

```bash
git status --short

find .github/workflows -maxdepth 1 -type f -print -exec sed -n '1,280p' {} \;

php -v
node --version

php tests/framework/test_ci_typed_selectors.php
php tests/framework/run.php

find . -type f -name '*.php' \
  -not -path './vendor/*' \
  -print0 | xargs -0 -n1 php -l

find bin scripts lib -type f \
  \( -name '*.sh' -o -name 'testkit' \) \
  -print0 | xargs -0 -n1 bash -n

find runners utils tests/fixtures/browser -type f -name '*.mjs' \
  -print0 | xargs -0 -n1 node --check
```

Para reproducir mejor el CI host, usar PHP 8.4 y Node 20.

## Reproducción local de `windows-static`

Desde PowerShell 7, en la raíz del repo `testKit`:

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

```bash
cp .env.test.example .env.test
sed -i 's/^TESTKIT_STACK=.*/TESTKIT_STACK=mysql/' .env.test
sed -i 's/^DB_DRIVER=.*/DB_DRIVER=mysql/' .env.test
sed -i 's/^TEST_DB_STRATEGY=.*/TEST_DB_STRATEGY=shared/' .env.test
sed -i 's/^TEST_JOBS=.*/TEST_JOBS=1/' .env.test

TESTKIT_STACK=mysql ./bin/testkit doctor --compact
TESTKIT_STACK=mysql ./bin/testkit doctor --full --suite migration-contract
TESTKIT_STACK=mysql ./bin/testkit up -d
TESTKIT_STACK=mysql ./bin/testkit ps
TESTKIT_STACK=mysql ./scripts/seed.sh
TESTKIT_STACK=mysql ./bin/testkit run --rm testkit php runTest.php --group all --list
TESTKIT_STACK=mysql ./bin/testkit run --rm testkit php runTest.php --group all
TESTKIT_STACK=mysql ./bin/testkit inspect latest
TESTKIT_STACK=mysql ./bin/testkit down -vc
```

Si falla cualquier comando antes del teardown, capturar diagnóstico antes de apagar:

```bash
mkdir -p ci-artifacts
TESTKIT_STACK=mysql ./bin/testkit ps > ci-artifacts/ci-testkit-ps.txt 2>&1 || true
TESTKIT_STACK=mysql ./bin/testkit logs --no-color --timestamps > ci-artifacts/ci-docker-logs.txt 2>&1 || true
TESTKIT_STACK=mysql ./bin/testkit down -vc || true
```

## Reproducción local del browser smoke

```bash
cp .env.test.example .env.test
sed -i 's/^TESTKIT_STACK=.*/TESTKIT_STACK=/' .env.test
cat >> .env.test <<'ENV'
TEST_STORE_DRIVER=none
TEST_STORE_PROVISION=external
ENV

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

## Revisión de estado Git

```bash
git status --short

git diff -- .github/workflows/ci.yml docs/CI.md tests/framework/run.php tests/framework/test_ci_typed_selectors.php
```

No debería aparecer ningún cambio fuera de esas rutas, salvo artifacts locales generados por las pruebas.

## Artifacts de diagnóstico

Todos los uploads usan:

```yaml
if: always()
if-no-files-found: ignore
```

### `runtime-mysql`

Revisar primero:

- `ci-artifacts/ci-testkit-ps.txt`;
- `ci-artifacts/ci-docker-logs.txt`;
- `ci-artifacts/ci-runtest-list.txt`;
- `ci-artifacts/ci-runtest-all.txt`;
- `ci-artifacts/ci-inspect-latest.txt`;
- `.testkit/**`;
- `reports/**`;
- `coverage/**`;
- `test/coverage/**`;
- `test/querylog.jsonl`.

### `browser-runner-smoke`

Revisar:

- `test-results/browser/fixture-server.log`;
- screenshots generados en fallo;
- traces generados con `retain-on-failure`.

## Riesgos conocidos

- `runtime-mysql` usa `--group all` como validación final. Si el grupo incorpora una suite experimental o inestable, debe cambiarse el registro contractual o el alcance explícito del job; no volver a un target posicional.
- `doctor --full --suite migration-contract` puede reportar estado no completamente cerrado si no hay snapshot visible en `.env.test`; se mantiene para exponer el contrato.
- El smoke browser depende de la imagen Docker de `testkit`, porque Playwright y Chromium están instalados dentro del contenedor.
- El workflow no hace obligatorios PostgreSQL, Redis ni Influx. Agregarlos como blocking sin contrato cerrado aumentaría falsos negativos.
- La validación local no sustituye una corrida real de GitHub Actions; el cierre requiere revisar el workflow ejecutado sobre el SHA candidato.
