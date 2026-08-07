# Verificación — I2 Store explícito

## Estado

```text
ESTADO: PENDIENTE
CLASIFICACION: VERIFICACION_CONTRATO_STORE
IMPLEMENTACION_BASE: 9a25a715676b19d8ae78ab2554d8754eb3d01109
ULTIMA_VALIDACION_LOCAL: c408cee90797f12597425a4433002b87c62be576
ULTIMO_RESULTADO: NEGATIVOS_PASS_RUNTIME_FAIL_CORREGIDO_PENDIENTE_DE_REVALIDAR
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

- contrato focal: PASS;
- framework: `40 passed, 3 failed`;
- `doctor_modes`: 2 casos FAIL;
- `pwsh`: no disponible.

Se corrigieron fixtures que no declaraban/cargaban el nuevo contrato, deuda I1 de selectores posicionales y ruido de modos de archivo.

### Segunda ejecución — `56ba333f778071485d3ac17b8ed4754d0468bcb1`

- contrato focal I2: PASS;
- regresiones focales: PASS;
- framework completo: `43 passed, 0 failed`;
- `doctor_modes`: `5/7` PASS, `2/7` FAIL;
- `pwsh`: no disponible.

Los dos fallos restantes provenían de un harness que construía `TESTKIT_ROOT` incompleto. Se corrigió para usar el repositorio real de testkit.

### Tercera ejecución — `11885891174e9c390e9e9cdcc2ab112765b96e10`

- `doctor_modes`: PASS — `7 case executions`;
- sintaxis PHP: PASS;
- sintaxis Bash: PASS;
- PowerShell: BLOCKED porque `pwsh` no está disponible.

### Cuarta ejecución — `c408cee90797f12597425a4433002b87c62be576`

Negativos públicos:

```text
[TEST_STORE_DRIVER_REQUIRED] ...
RC_MISSING=1
[TEST_STORE_DRIVER_INVALID] TEST_STORE_DRIVER='postgres' ...
RC_INVALID=1
```

Resultado Gate 6: PASS. No hubo fallback a MySQL ni normalización de `postgres`.

Runtime MySQL:

- `docker compose up` levantó MySQL sano;
- `--group all --list` terminó PASSED y publicó selección válida;
- ejecución real `--group all` falló en bootstrap antes de tests;
- causa 1: `.env.test.example` declaraba `TEST_STORE_PROVISION=managed` de forma implícita y `TEST_MYSQL_ROOT_PASSWORD`, pero no `TEST_MYSQL_ADMIN_USER`;
- causa 2: `scripts/seed.sh` estaba en modo `100644`, por lo que el comando documentado `./scripts/seed.sh` devolvió `Permiso denegado`;
- `doctor --full --suite migration-contract` reportó `MIGRATION_CONTRACT_NEEDS_SNAPSHOT`; esto es esperado por el contrato de esa suite y demuestra que no es un gate válido para el env genérico I2;
- apareció un orphan `testkit-redis_test-1`; debe limpiarse y no se atribuye a I2 sin evidencia adicional.

Correcciones publicadas después de esta ejecución:

1. `.env.test.example` declara explícitamente `TEST_STORE_PROVISION=managed` y `TEST_MYSQL_ADMIN_USER=root`;
2. `docs/examples/.env.test.example` declara `TEST_STORE_DRIVER=mysql` y mantiene el path managed completo;
3. el test focal protege ambos ejemplos para evitar regresión;
4. `scripts/seed.sh` y `scripts/db_clean.sh` recuperan modo ejecutable `100755`;
5. el gate runtime usa `doctor --full --suite back-php`, no `migration-contract`;
6. `seed.sh`, `db_clean.sh`, `seed.ps1` y `db_clean.ps1` preservan variables ya exportadas, igual que el wrapper principal, para que un override explícito como `TESTKIT_STACK=mysql` no sea reemplazado por el archivo de entorno.

La verificación permanece PENDIENTE hasta repetir runtime sobre `9a25a715` o posterior y revisar CI real.

## Gate 1 — baseline

```bash
cd ~/Escritorio/Pruebas/submodules/Base/testkit

git pull --ff-only
git branch --show-current
git rev-parse HEAD
git status --short
```

Esperado: rama `main`, working tree limpio y HEAD que contenga `9a25a715` o posterior.

## Gate 2 — contrato focalizado — PASS previo, reejecutar tras cambios de ejemplos

```bash
php -l tests/framework/test_store_driver_contract.php
php tests/framework/test_store_driver_contract.php
```

Esperado:

```text
Store driver explicit contract PASS
```

## Gate 3 — self-tests — PASS

Evidencia previa:

```text
43 passed, 0 failed
Doctor mode self-tests passed: 7 case executions
```

## Gate 4 — sintaxis — PASS / PowerShell BLOCKED

```text
PHP: PASS
Bash: PASS
PowerShell local: BLOCKED — pwsh no disponible en PATH
```

Los cuatro entrypoints de seed/clean modificados después de la última sintaxis deben volver a pasar Bash/PowerShell en el entorno disponible antes del cierre.

## Gate 5 — runtime MySQL — FAIL CORREGIDO / REVALIDAR

Usar un env temporal dentro del repo para que el wrapper pueda montarlo sin tocar `.env.test` real.

```bash
cp .env.test.example .env.i2-runtime
export TESTKIT_PROJECT_ROOT="$PWD"
export TESTKIT_ENV_FILE="$PWD/.env.i2-runtime"
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
unset TESTKIT_PROJECT_ROOT TESTKIT_ENV_FILE TESTKIT_STACK
rm -f .env.i2-runtime
git status --short
```

No declarar PASS si falla doctor, bootstrap, seed, ejecución, reporting o teardown sin clasificar.

## Gate 6 — negativos públicos — PASS

Evidencia local obtenida en `c408cee`:

```text
TEST_STORE_DRIVER_REQUIRED / RC_MISSING=1
TEST_STORE_DRIVER_INVALID / RC_INVALID=1
```

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
- negativos PASS;
- CI sin fallos introducidos por I2.

## FAIL

Es FAIL si:

- cualquier alias o inferencia selecciona un store;
- `TEST_STORE_DRIVER` ausente usa MySQL;
- aliases/valores no exactos se normalizan;
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
