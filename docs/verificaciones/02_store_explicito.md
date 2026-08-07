# Verificación — I2 Store explícito

## Estado

```text
ESTADO: PENDIENTE
CLASIFICACION: VERIFICACION_CONTRATO_STORE
IMPLEMENTACION_BASE: d0c3ffb711fae48d0a2e8a2ead2a4d617456767e
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

Las variables anteriores pueden seguir siendo datos de conexión, nombres de DB o composición de infraestructura cuando corresponda; no son selectors.

Errores contractuales:

```text
TEST_STORE_DRIVER_REQUIRED
TEST_STORE_DRIVER_INVALID
```

## Implementación existente

El corte incluye:

- `StoreRegistry` como autoridad PHP del driver;
- `ParallelGuard` delegando la resolución a `StoreRegistry`;
- bootstrap, migration suite, back-python, `store_router` y reporting sin fallback `mysql`;
- `seed.sh`, `seed.ps1`, `db_clean.sh` y `db_clean.ps1` exigiendo la variable canónica;
- doctor Bash/PowerShell rechazando ausencia y aliases antes de ejecutar checks;
- capability doctor sin resolución por `DB_DRIVER`, `TEST_DB_DRIVER` ni DSN;
- `ConfigSchema` v6 con `TEST_STORE_DRIVER` requerido y sin `DB_DRIVER` como variable de selección;
- `.env.test.example` declarando explícitamente `TEST_STORE_DRIVER=mysql`;
- CI MySQL configurando `TEST_STORE_DRIVER=mysql`;
- self-test `tests/framework/test_store_driver_contract.php` registrado en el runner.

## Gate 1 — baseline

```bash
cd ~/Escritorio/Pruebas/submodules/Base/testkit

git branch --show-current
git rev-parse HEAD
git status --short
git log --oneline -10
```

Esperado: rama `main`, working tree limpio y HEAD que contenga este corte.

## Gate 2 — contrato focalizado

```bash
php -l tests/framework/test_store_driver_contract.php
php tests/framework/test_store_driver_contract.php
```

Esperado:

```text
Store driver explicit contract PASS
```

El test debe demostrar que:

- `mysql`, `pgsql` y `none` son aceptados sin normalización;
- ausencia de `TEST_STORE_DRIVER` falla con `TEST_STORE_DRIVER_REQUIRED`;
- `pg`, `postgres`, `postgresql`, mayúsculas y valores con espacios fallan con `TEST_STORE_DRIVER_INVALID`;
- `DB_DRIVER`, `TEST_DB_DRIVER`, DSN o variables PostgreSQL no sustituyen la variable canónica.

## Gate 3 — self-tests

```bash
php tests/framework/run.php
php tests/framework/doctor_modes/run.php
```

Criterio:

- `Store driver explicit contract` aparece en PASS;
- `Seed state canonical contract` permanece PASS;
- `Store resource lock` permanece PASS;
- doctor rechaza driver ausente y `postgres`;
- no aparecen regresiones nuevas.

## Gate 4 — sintaxis

```bash
find . -type f -name '*.php' \
  -not -path './vendor/*' \
  -print0 | xargs -0 -r -n1 php -l

find bin scripts lib -type f \
  \( -name '*.sh' -o -name 'testkit' \) \
  -print0 | xargs -0 -r -n1 bash -n
```

Si existe PowerShell 7:

```bash
pwsh -NoProfile -NonInteractive -File tests/powershell/run.ps1
```

## Gate 5 — runtime MySQL

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

## Gate 6 — negativos públicos

En una copia temporal del env, comprobar que no hay fallback:

```bash
cp .env.test.example /tmp/testkit-i2.env
sed -i '/^TEST_STORE_DRIVER=/d' /tmp/testkit-i2.env
TESTKIT_ENV_FILE=/tmp/testkit-i2.env ./bin/testkit doctor --compact; test $? -ne 0

sed -i '1i TEST_STORE_DRIVER=postgres' /tmp/testkit-i2.env
TESTKIT_ENV_FILE=/tmp/testkit-i2.env ./bin/testkit doctor --compact; test $? -ne 0

rm -f /tmp/testkit-i2.env
```

La primera ejecución debe publicar `TEST_STORE_DRIVER_REQUIRED`; la segunda `TEST_STORE_DRIVER_INVALID`.

## Gate 7 — CI real

Revisar el workflow `CI` sobre el SHA candidato.

Jobs bloqueantes esperados:

```text
static
windows-static
framework-self-tests
runtime-mysql
browser-runner-smoke
```

No declarar PASS si un job requerido sigue rojo sin clasificar.

## PASS

I2 queda verificado solo si:

- contrato focalizado PASS;
- self-tests sin regresiones introducidas;
- sintaxis PHP/Bash PASS;
- PowerShell PASS cuando el entorno lo permite o queda explícitamente BLOCKED;
- runtime MySQL usa la variable canónica y funciona;
- negativos demuestran ausencia de aliases/fallback;
- CI no tiene fallos introducidos por I2.

## FAIL

Es FAIL si:

- cualquier alias o inferencia selecciona un store;
- `TEST_STORE_DRIVER` ausente termina usando MySQL;
- `pg`/`postgres`/`postgresql` son normalizados a `pgsql`;
- doctor/schema/runtime difieren;
- reporting reconstruye silenciosamente `mysql`;
- CI o self-tests muestran una regresión del corte.

## BLOCKED

Usar `BLOCKED` únicamente cuando falte infraestructura real para un gate, por ejemplo Docker, PowerShell 7 o acceso a Actions. No equivale a PASS.

## Fuera de este gate

No valida ni modifica:

- I3 stack estricto;
- I4 selección única;
- I5 coverage único;
- I6-I9;
- consumidores externos;
- gitlinks de `Base` o `Pruebas`.

## Acción después de PASS

1. registrar evidencia estable solo si aporta valor operativo;
2. borrar `docs/verificaciones/02_store_explicito.md`;
3. quitar su fila de `docs/verificaciones/README.md`;
4. actualizar primero el gitlink `Base/testkit`;
5. validar `Base`;
6. en fase separada actualizar `Pruebas/submodules/Base`;
7. continuar con I3.
