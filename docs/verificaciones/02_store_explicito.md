# Verificación — I2 Store explícito

## Estado

```text
ESTADO: PENDIENTE
CLASIFICACION: VERIFICACION_CONTRATO_STORE
IMPLEMENTACION_BASE: 2f8f34c33a25c8bbdd036d3cfccece9cda976c32
ULTIMA_VALIDACION_LOCAL: 0e8479e02dee039adb1f78e2b710ea832fec44a8
ULTIMO_RESULTADO: LOCAL_RUNTIME_PASS_CI_NORMALIZADO_PENDIENTE_REVALIDAR_Y_REACTIVAR
POWERSHELL_LOCAL: BLOCKED_PWSH_NO_DISPONIBLE
WORKFLOW_REMOTE_STATE: DISABLED_MANUALLY
PRODUCCION_AUTORIZADA: NO
```

## Contrato

`TEST_STORE_DRIVER` es la única entrada que selecciona el store estructural.

Valores exactos:

```text
mysql
pgsql
none
```

No seleccionan driver:

```text
DB_DRIVER
TEST_DB_DRIVER
TEST_DB_DSN
DB_NAME
PG_DB
TEST_PG_DB
TESTKIT_STACK
```

Errores contractuales:

```text
TEST_STORE_DRIVER_REQUIRED
TEST_STORE_DRIVER_INVALID
```

## Evidencia local ya cerrada

Confirmado antes de normalizar CI:

```text
Store driver explicit contract PASS
Doctor mode self-tests passed: 7 case executions
PHP syntax: PASS
Bash syntax: PASS
Negativos públicos: PASS
Runtime MySQL fixture: PASS
Framework: 43 passed, 0 failed
Working tree: limpio
```

Runtime MySQL real validado con:

```text
tests/fixtures/runtime-mysql-host
```

Evidencia:

```text
MySQL healthy
Seeds aplicadas: 1
runtime_mysql_store.test.php descubierto
PASS=1 FAIL=0
inspect latest: evidence_valid=true
teardown: PASS
```

## Auditoría del CI deshabilitado

GitHub reportó:

```text
CI    disabled_manually    workflow_id=237398346
```

Actions del repositorio están habilitadas globalmente; el workflow individual es el que permanece deshabilitado. Los runs visibles son históricos y no sirven como evidencia del baseline I2 actual.

### Deuda encontrada

1. `actions/checkout@v4`, `actions/setup-node@v4` y `actions/upload-artifact@v4` estaban varios majors detrás del baseline vigente.
2. El job estático fijaba Node `20`, ya EOL.
3. La imagen Docker reusable también fijaba Node `20` y Playwright `1.45.0`.
4. `windows-static` usaba `windows-latest`, sujeto a cambio de imagen.
5. `browser-runner-smoke` usaba la raíz de TestKit como host implícito en vez de un `TESTKIT_PROJECT_ROOT` separado.
6. `docs/WINDOWS.md` todavía publicaba selectores posicionales y `migration-contract` como preflight genérico.
7. No existía un test PowerShell ejecutable que comprobara `TEST_STORE_DRIVER_REQUIRED`, `TEST_STORE_DRIVER_INVALID` y la precedencia del env en `seed.ps1`/`db_clean.ps1`.

## Normalización publicada

Baseline candidato posterior a la auditoría: `main` igual o posterior al commit que contiene este documento.

Cambios intencionales:

```text
.github/workflows/ci.yml
docker/Dockerfile
compose.yaml
docs/CI.md
docs/WINDOWS.md
tests/framework/test_ci_typed_selectors.php
tests/powershell/run.ps1
tests/powershell/test_store_driver_contract.ps1
```

Contrato nuevo del CI:

```text
Ubuntu: ubuntu-24.04
Windows: windows-2025
Node host: 24 LTS
Node Docker: 24
Playwright Docker: 1.61.0
checkout: v7
setup-node: v7
upload-artifact: v7
```

`runtime-mysql` mantiene su host fixture dedicado.

`browser-runner-smoke` ahora usa:

```text
TESTKIT_PROJECT_ROOT=tests/fixtures/browser
TESTKIT_ENV_FILE=tests/fixtures/browser/.env.test
TEST_STORE_DRIVER=none
TEST_STORE_PROVISION=external
```

El harness PowerShell incorpora `Store driver explicit contract` y valida `seed.ps1` y `db_clean.ps1` sin Docker:

```text
missing -> TEST_STORE_DRIVER_REQUIRED / exit 2
invalid -> TEST_STORE_DRIVER_INVALID / exit 2
exported none overrides env-file mysql -> exit 0 sin runtime
```

## Gate local antes de reactivar Actions

Linux:

```bash
php -l tests/framework/test_ci_typed_selectors.php
php tests/framework/test_ci_typed_selectors.php
php tests/framework/test_store_driver_contract.php
php tests/framework/run.php

bash -n scripts/seed.sh
bash -n scripts/db_clean.sh
```

Docker/browser, porque cambió Node/Playwright:

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
    php -S 127.0.0.1:4173 -t /workspace/project/public > /workspace/project/test-results/browser/fixture-server.log 2>&1 &
    server_pid="$!"
    trap "kill ${server_pid} >/dev/null 2>&1 || true" EXIT
    for i in $(seq 1 50); do
      if curl -fsS http://127.0.0.1:4173/health.json >/dev/null; then break; fi
      sleep 0.2
    done
    node /workspace/testkit/runners/runBrowserE2e.mjs smoke.spec.mjs
  '

unset TESTKIT_PROJECT_ROOT TESTKIT_ENV_FILE TESTKIT_STACK
rm -f "$FIXTURE/.env.test"
```

PowerShell local puede seguir BLOCKED si `pwsh` no está disponible en Ubuntu. La evidencia real debe venir de `windows-static` tras reactivar el workflow.

## Reactivación — NO EJECUTADA

La reactivación es una acción remota separada y no forma parte de esta normalización.

Cuando los gates locales anteriores pasen y exista autorización:

```bash
gh workflow enable ci.yml --repo lucasborges2001/testKit
gh workflow run ci.yml --repo lucasborges2001/testKit --ref main
```

PASS remoto requiere observar:

```text
static                 success
windows-static         success
framework-self-tests   success
runtime-mysql          success
browser-runner-smoke   success
```

## Deuda fuera de este cambio

El inventario detectó documentación histórica adicional con ejemplos posicionales, entre otros `README.md`, `docs/USO.md` y documentos de profiling. No se corrigió aquí para evitar mezclar la normalización de CI/I2 con una limpieza documental transversal.

Tampoco se modifican:

- I3 stack estricto;
- I4 selección única;
- I5 coverage único;
- I6-I9;
- consumidores externos;
- gitlinks de `Base` o `Pruebas`.

## Acción después de PASS remoto

1. cerrar/eliminar esta verificación según la política vigente;
2. actualizar `Base/testkit`;
3. validar `Base`;
4. actualizar `Pruebas/submodules/Base` en fase separada;
5. continuar con I3.
