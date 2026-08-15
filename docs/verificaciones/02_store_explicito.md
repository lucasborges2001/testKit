# Verificación — I2 Store explícito

## Estado

```text
ESTADO: BLOCKED
CLASIFICACION: VERIFICACION_CONTRATO_STORE
IMPLEMENTACION_BASE: 2f8f34c33a25c8bbdd036d3cfccece9cda976c32
ULTIMA_VALIDACION_LOCAL: 0e8479e02dee039adb1f78e2b710ea832fec44a8
BASELINE_DOCUMENTAL_ACTUAL: 8fd6cca8b91167c57bd4189e81365e5e4d34e3da
ULTIMO_RESULTADO_LOCAL: PASS
CI_REMOTA_ACTUAL: BLOCKED_CI_BUDGET
WORKFLOW_ACTUAL: STUB_DESHABILITADO
PRODUCCION_AUTORIZADA: NO
```

`BLOCKED_CI_BUDGET` no equivale a `PASS`. Esta verificación permanece abierta porque el HEAD actual no tiene evidencia remota nueva del workflow completo.

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

## Evidencia local histórica válida

Antes de la deshabilitación de Actions se obtuvo evidencia local de:

```text
Store driver explicit contract PASS
Doctor mode self-tests PASS
PHP syntax PASS
Bash syntax PASS
negativos públicos PASS
runtime MySQL fixture PASS
framework 43 passed, 0 failed
working tree limpio
```

El fixture runtime usado fue:

```text
tests/fixtures/runtime-mysql-host
```

Y demostró al menos:

```text
MySQL healthy
seed aplicado
runtime_mysql_store.test.php descubierto
PASS=1 FAIL=0
inspect latest: evidence_valid=true
teardown PASS
```

Esta evidencia demuestra el baseline donde fue ejecutada; no sustituye un run CI nuevo del HEAD actual.

## Estado remoto actual

En `main@8fd6cca` el archivo `.github/workflows/ci.yml` fue reemplazado temporalmente por un stub debido a presupuesto de GitHub Actions no disponible.

El workflow actual:

- sólo expone `workflow_dispatch`;
- tiene permisos vacíos;
- contiene un job con `if: false`;
- no ejecuta los gates contractuales.

Por eso el bloqueo actual se clasifica como:

```text
BLOCKED_CI_BUDGET
```

No como fallo de I2 y tampoco como PASS remoto.

## Baseline CI que debe restaurarse

La definición completa anterior separaba:

```text
static
windows-static
framework-self-tests
runtime-mysql
browser-runner-smoke
```

La restauración debe recuperar esa definición desde historia Git o desde un cambio explícito revisable. No reconstruirla de memoria.

## Gate local previo a una futura reactivación

```bash
php tests/framework/test_ci_typed_selectors.php
php tests/framework/test_store_driver_contract.php
php tests/framework/run.php
bash -n scripts/seed.sh
bash -n scripts/db_clean.sh
git diff --check
```

En un host con PowerShell 7:

```powershell
pwsh -NoProfile -NonInteractive -File tests\powershell\run.ps1
```

La deuda de `ProcessRunner` Windows permanece separada en `docs/pendientes/processrunner-timeout-windows.md` y no debe mezclarse con el contrato de store explícito.

## PASS remoto requerido

Cuando Actions vuelva a estar disponible y se autorice restaurar/reactivar el workflow completo, esta verificación puede cerrarse únicamente si un run nuevo asociado al SHA candidato demuestra éxito en todos los gates contractuales aplicables.

Como mínimo:

```text
static                 success
windows-static         success
framework-self-tests   success
runtime-mysql          success
browser-runner-smoke   success
```

## Después de PASS

1. cerrar esta verificación;
2. fijar el SHA verificado donde corresponda en consumidores mediante cambios separados;
3. validar cada consumidor con su propio baseline;
4. continuar las fases internas restantes desde `docs/pendientes/normalizacion-contratos/pendiente-interno-testkit.md`.

## Acciones excluidas

- reactivar Actions desde este documento;
- modificar `Base`, `Pruebas` o consumidores;
- actualizar gitlinks;
- declarar runtime Windows completo por un gate estático;
- presentar `BLOCKED` como `PASS`.
