# Verificación — I2 Store explícito

## Estado

```text
ESTADO: PENDIENTE
CLASIFICACION: VERIFICACION_CONTRATO_STORE
IMPLEMENTACION_BASE: f7945e04ff7dc9feeb889450db57e4e8a73755f8
ULTIMA_VALIDACION_LOCAL: 11885891174e9c390e9e9cdcc2ab112765b96e10
ULTIMO_RESULTADO: PARCIAL_LOCAL_PASS_RUNTIME_CI_PENDIENTES
POWERSHELL_LOCAL: BLOCKED_PWSH_NO_DISPONIBLE
PRODUCCION_AUTORIZADA: NO
```

## Contrato implementado

`TEST_STORE_DRIVER` es la única entrada que selecciona el store estructural.

Valores válidos exactos:

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

## Evidencia local acumulada

### Primera ejecución — `70f604e4842eadd56354847b71cd937fad6f79bb`

Resultado:

- contrato focal: PASS;
- framework: `40 passed, 3 failed`;
- `doctor_modes`: 2 casos FAIL;
- `pwsh`: no disponible.

Causas corregidas posteriormente:

1. fixture de clasificación importaba `ParallelGuard` sin cargar `StoreRegistry`;
2. fixture BackPython no declaraba `TEST_STORE_DRIVER=none`;
3. fixture SQL observability no declaraba `TEST_STORE_DRIVER=mysql`;
4. `doctor_modes` conservaba selectores posicionales heredados de I1;
5. seis archivos habían cambiado accidentalmente de modo `100644` a `100755`.

### Segunda ejecución — `56ba333f778071485d3ac17b8ed4754d0468bcb1`

Resultado:

- contrato focal I2: PASS;
- `Failure classification contracts`: PASS;
- `BackPythonSuite trace coverage contract`: PASS;
- `SQL observability public exit code 5`: PASS;
- framework completo: `43 passed, 0 failed`;
- `doctor_modes`: `5/7` PASS, `2/7` FAIL;
- `pwsh`: no disponible.

Los dos fallos de doctor eran del harness: fabricaba un `TESTKIT_ROOT` incompleto que no contenía `scripts/contract.php`. Se corrigió para usar el repositorio TestKit real como root, manteniendo proyecto/env/Docker stub temporales.

### Tercera ejecución — `11885891174e9c390e9e9cdcc2ab112765b96e10`

Resultado:

- `doctor_modes`: PASS — `7 case executions`;
- PowerShell: SKIP/BLOCKED porque `pwsh` no está disponible en PATH;
- sintaxis PHP de todo el repositorio excluyendo `vendor/`: PASS;
- sintaxis Bash sobre `bin`, `scripts` y `lib`: PASS.

Combinando esta ejecución con la segunda, los gates locales de contrato, self-tests, doctor y sintaxis quedan PASS. PowerShell local permanece BLOCKED por entorno y no se interpreta como PASS.

## Gate 1 — baseline

```bash
cd ~/Escritorio/Pruebas/submodules/Base/testkit

git pull --ff-only
git branch --show-current
git rev-parse HEAD
git status --short
```

Esperado: rama `main`, working tree limpio y HEAD igual o posterior al commit que contiene esta verificación.

## Gate 2 — contrato focalizado — PASS

Evidencia obtenida:

```text
No syntax errors detected in tests/framework/test_store_driver_contract.php
Store driver explicit contract PASS
```

## Gate 3 — self-tests — PASS

Evidencia obtenida:

```text
43 passed, 0 failed
Doctor mode self-tests passed: 7 case executions
```

También PASS individual:

```text
Failure classification contracts PASS
BackPythonSuite trace coverage contract PASS
OK SQL observability public exit code 5
```

## Gate 4 — sintaxis — PASS / PowerShell BLOCKED

PHP:

```text
PASS
```

Bash:

```text
PASS
```

PowerShell local:

```text
BLOCKED: pwsh no disponible en PATH
```

Debe validarse en Windows/CI antes del cierre definitivo.

## Gate 5 — runtime MySQL — PENDIENTE

Usar un env descartable derivado de `.env.test.example`.

```bash
cp .env.test.example .env.test

TESTKIT_STACK=mysql ./bin/testkit doctor --compact
TESTKIT_STACK=mysql ./bin/testkit doctor --full --suite migration-contract
TESTKIT_STACK=mysql ./bin/testkit up -d
TESTKIT_STACK=mysql ./bin/testkit ps
TESTKIT_STACK=mysql ./scripts/seed.sh
TESTKIT_STACK=mysql ./bin/testkit run --rm testkit php runTest.php --group all --list
TESTKIT_STACK=mysql ./bin/testkit run --rm testkit php runTest.php --group all
TESTKIT_STACK=mysql ./bin/testkit inspect latest
```

Teardown obligatorio:

```bash
TESTKIT_STACK=mysql ./bin/testkit down -vc
rm -f .env.test
```

No declarar PASS si falla doctor, bootstrap, seed, ejecución, reporting o teardown sin clasificar.

## Gate 6 — negativos públicos — PENDIENTE

```bash
cp .env.test.example /tmp/testkit-i2.env
sed -i '/^TEST_STORE_DRIVER=/d' /tmp/testkit-i2.env
TESTKIT_ENV_FILE=/tmp/testkit-i2.env ./bin/testkit doctor --compact; test $? -ne 0

cp .env.test.example /tmp/testkit-i2.env
sed -i 's/^TEST_STORE_DRIVER=.*/TEST_STORE_DRIVER=postgres/' /tmp/testkit-i2.env
TESTKIT_ENV_FILE=/tmp/testkit-i2.env ./bin/testkit doctor --compact; test $? -ne 0

rm -f /tmp/testkit-i2.env
```

Esperado:

```text
TEST_STORE_DRIVER_REQUIRED
TEST_STORE_DRIVER_INVALID
```

Ningún caso debe caer a MySQL ni normalizar `postgres` a `pgsql`.

## Gate 7 — CI real — PENDIENTE

Revisar el workflow `CI` sobre el SHA candidato.

Jobs bloqueantes esperados:

```text
static
windows-static
framework-self-tests
runtime-mysql
browser-runner-smoke
```

PowerShell local puede permanecer BLOCKED únicamente si `windows-static` aporta la evidencia real requerida.

## PASS

I2 queda verificado solo si:

- contrato focal PASS;
- self-tests `43/43` PASS;
- doctor modes PASS;
- sintaxis PHP/Bash PASS;
- PowerShell validado en entorno disponible o CI real;
- runtime MySQL PASS;
- negativos demuestran ausencia de aliases/fallback;
- CI no presenta fallos introducidos por I2.

## FAIL

Es FAIL si:

- cualquier alias o inferencia selecciona un store;
- `TEST_STORE_DRIVER` ausente usa MySQL;
- `pg`, `postgres`, `postgresql`, mayúsculas o valores con espacios son normalizados;
- doctor/schema/runtime difieren;
- reporting reconstruye silenciosamente `mysql`;
- runtime o CI muestran una regresión I2.

## Fuera de este gate

No valida ni modifica:

- I3 stack estricto;
- I4 selección única;
- I5 coverage único;
- I6-I9;
- consumidores externos;
- gitlinks de `Base` o `Pruebas`.

## Acción después de PASS

1. borrar `docs/verificaciones/02_store_explicito.md`;
2. quitar su fila de `docs/verificaciones/README.md`;
3. actualizar primero `Base/testkit`;
4. validar `Base`;
5. actualizar `Pruebas/submodules/Base` en fase separada;
6. continuar con I3.
