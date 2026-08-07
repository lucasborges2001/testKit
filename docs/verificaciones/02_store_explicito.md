# Verificación — I2 Store explícito

## Estado

```text
ESTADO: PENDIENTE
CLASIFICACION: VERIFICACION_CONTRATO_STORE
IMPLEMENTACION_BASE: 2f8f34c33a25c8bbdd036d3cfccece9cda976c32
ULTIMA_VALIDACION_LOCAL: b0b6e038d7dcc8ae5a1ab3c5cf20d4f6ff0d857c
ULTIMO_RESULTADO: NEGATIVOS_PASS_RUNTIME_HOST_GATE_CORREGIDO_PENDIENTE_DE_REVALIDAR
POWERSHELL_LOCAL: BLOCKED_PWSH_NO_DISPONIBLE
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

## Evidencia acumulada

### Contrato y self-tests

Confirmado en ejecuciones previas:

```text
Store driver explicit contract PASS
43 passed, 0 failed
Doctor mode self-tests passed: 7 case executions
PHP syntax: PASS
Bash syntax: PASS
PowerShell local: BLOCKED — pwsh no disponible
```

### Negativos públicos — PASS

Sobre `c408cee90797f12597425a4433002b87c62be576`:

```text
TEST_STORE_DRIVER_REQUIRED / RC_MISSING=1
TEST_STORE_DRIVER_INVALID / RC_INVALID=1
```

No hubo fallback a MySQL ni normalización de `postgres`.

### Runtime MySQL — iteración 1

Detectó y permitió corregir:

1. `.env.test.example` managed incompleto: faltaba `TEST_MYSQL_ADMIN_USER`;
2. `scripts/seed.sh` sin bit ejecutable;
3. `migration-contract` no era un doctor válido para un env genérico porque exige snapshot;
4. seed/clean Bash y PowerShell pisaban overrides ya exportados al recargar el env.

### Runtime MySQL — iteración 2 sobre `b0b6e038d7dcc8ae5a1ab3c5cf20d4f6ff0d857c`

Confirmó:

- cleanup inicial: PASS, sin containers/volúmenes/orphans restantes;
- modo `100755` de `scripts/seed.sh` y `scripts/db_clean.sh`: PASS;
- contrato focal: PASS;
- doctor compact: PASS;
- doctor `--suite back-php`: PASS;
- MySQL: healthy;
- `--group all --list`: PASSED;
- teardown: PASS.

La ejecución real falló antes de tests porque el gate usaba el propio repositorio TestKit como `TESTKIT_PROJECT_ROOT` y ese repositorio no es un host de aplicación: no contiene `test/seeds/mysql`. `SeedPipeline` exige explícitamente `test/seeds/<driver>` en el proyecto host.

Esto no se resuelve agregando seeds de dominio a la raíz de TestKit. Se corrigió el gate mediante un host fixture dedicado:

```text
tests/fixtures/runtime-mysql-host/
└── test/
    ├── back/runtime_mysql_store.test.php
    └── seeds/mysql/001_runtime_probe.sql
```

El seed crea de forma idempotente `testkit_runtime_probe` y escribe `marker='seeded'`. El test runtime abre PDO contra MySQL y exige recuperar ese valor.

El job `runtime-mysql` de CI ahora monta ese fixture como `TESTKIT_PROJECT_ROOT`, genera su env temporal, usa `doctor --suite back-php`, ejecuta seed/list/run/inspect y limpia el env. El self-test I2 falla si el fixture desaparece, si CI deja de usarlo o si reaparece `migration-contract` en ese gate.

## Gate 1 — baseline

```bash
cd ~/Escritorio/Pruebas/submodules/Base/testkit

git pull --ff-only
git branch --show-current
git rev-parse HEAD
git status --short
```

Esperado: `main`, limpio, HEAD igual o posterior al commit que contiene esta verificación.

## Gate 2 — regresión local después del fixture

```bash
php -l tests/framework/test_store_driver_contract.php
php tests/framework/test_store_driver_contract.php
php tests/framework/run.php

bash -n scripts/seed.sh
bash -n scripts/db_clean.sh
```

Esperado:

```text
Store driver explicit contract PASS
43 passed, 0 failed
```

PowerShell local permanece BLOCKED si `pwsh` no existe.

## Gate 3 — runtime MySQL contra host fixture

Ejecutar desde la raíz de TestKit:

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

Criterio:

- doctor PASS;
- MySQL healthy;
- seed termina con código 0;
- el listado descubre `runtime_mysql_store.test.php`;
- la ejecución real publica PASS para ese test;
- `inspect latest` corresponde a la ejecución real y tiene evidencia válida.

Teardown obligatorio, incluso ante FAIL:

```bash
./bin/testkit down -v --remove-orphans

unset TESTKIT_PROJECT_ROOT TESTKIT_ENV_FILE TESTKIT_STACK
rm -f "$FIXTURE/.env.i2-runtime"
rm -rf "$FIXTURE/.testkit" "$FIXTURE/reports" "$FIXTURE/coverage" "$FIXTURE/test/coverage"

docker ps -a --format '{{.Names}}' | grep '^testkit-' || true
git status --short
```

No declarar PASS si quedan containers/orphans o el working tree queda sucio sin clasificar.

## Gate 4 — negativos públicos — PASS

No hace falta repetir salvo que cambie la resolución de `TEST_STORE_DRIVER`.

## Gate 5 — CI real — PENDIENTE

Revisar el workflow `CI` sobre el SHA candidato.

Jobs bloqueantes:

```text
static
windows-static
framework-self-tests
runtime-mysql
browser-runner-smoke
```

`windows-static` debe aportar la evidencia PowerShell que no puede obtenerse localmente.

## PASS

I2 se cierra solo si:

- contrato focal PASS;
- framework `43/43` PASS;
- doctor modes PASS;
- sintaxis PHP/Bash PASS;
- negativos públicos PASS;
- runtime MySQL contra host fixture PASS;
- CI sin regresiones I2;
- PowerShell demostrado por `windows-static` o entorno equivalente.

## FAIL

Es FAIL si:

- un alias/inferencia selecciona store;
- ausencia usa MySQL;
- valores no exactos se normalizan;
- doctor/schema/runtime difieren;
- reporting inventa `mysql`;
- runtime host fixture no puede seedear/ejecutar/inspeccionar;
- CI presenta una regresión I2.

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
3. actualizar `Base/testkit`;
4. validar `Base`;
5. actualizar `Pruebas/submodules/Base` en fase separada;
6. continuar con I3.
