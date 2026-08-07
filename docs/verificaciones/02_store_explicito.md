# Verificación — I2 Store explícito

## Estado

```text
ESTADO: PENDIENTE
CLASIFICACION: VERIFICACION_CONTRATO_STORE
IMPLEMENTACION_BASE: 2f8f34c33a25c8bbdd036d3cfccece9cda976c32
ULTIMA_VALIDACION_LOCAL: 8370b7f2ef28e7f231f8e8da74af4b6b4a7896fe
ULTIMO_RESULTADO: RUNTIME_MYSQL_PASS_SELFTEST_CI_EXPECTATION_CORREGIDA_PENDIENTE_REVALIDAR_CI
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

### Contrato, doctor y sintaxis

Confirmado localmente:

```text
Store driver explicit contract PASS
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

### Runtime MySQL — PASS sobre `8370b7f2ef28e7f231f8e8da74af4b6b4a7896fe`

Host fixture usado:

```text
tests/fixtures/runtime-mysql-host
```

Evidencia:

```text
Doctor compact: PASS
Doctor --suite back-php: PASS
MySQL: healthy
Seeds aplicadas: 1
Listado: test/back/runtime_mysql_store.test.php descubierto
Run: PASS=1 FAIL=0
inspect latest: evidence_valid=true
Teardown: PASS
Working tree: limpio
```

El test runtime abrió PDO contra MySQL y verificó el valor `marker='seeded'` creado por el seed del fixture. Esto demuestra bootstrap, seed, conexión y persistencia real.

### Self-tests después del fixture — 42/43, causa corregida

En la misma validación sobre `8370b7f`:

```text
42 passed, 1 failed
```

Único fallo:

```text
CI typed selector contract
```

No era un fallo del runtime ni del contrato de store. `tests/framework/test_ci_typed_selectors.php` seguía exigiendo literalmente el workflow anterior:

- `doctor --full --suite migration-contract`;
- comandos prefijados inline con `TESTKIT_STACK=mysql`;
- host implícito igual a la raíz de TestKit.

El workflow ya usa el contrato correcto:

```text
TESTKIT_PROJECT_ROOT=tests/fixtures/runtime-mysql-host
TESTKIT_ENV_FILE=tests/fixtures/runtime-mysql-host/.env.test
doctor --full --suite back-php
runTest.php --group all
```

Se corrigieron:

```text
tests/framework/test_ci_typed_selectors.php
docs/CI.md
```

El gate ahora valida selectores tipados y el host fixture sin acoplarse al gate snapshot anterior. `docs/CI.md` quedó alineado con la reproducción runtime vigente.

## Gate local final — REVALIDAR

Después de actualizar `main`:

```bash
php -l tests/framework/test_ci_typed_selectors.php
php tests/framework/test_ci_typed_selectors.php
php tests/framework/test_store_driver_contract.php
php tests/framework/run.php
```

Esperado:

```text
OK CI typed selectors
Store driver explicit contract PASS
43 passed, 0 failed
```

No hace falta repetir runtime MySQL si estos cambios permanecen limitados al self-test y documentación.

## CI real — PENDIENTE

Jobs bloqueantes esperados:

```text
static
windows-static
framework-self-tests
runtime-mysql
browser-runner-smoke
```

`windows-static` debe aportar la evidencia PowerShell que no pudo obtenerse localmente.

## PASS

I2 se cierra solo si:

- contrato focal PASS;
- framework `43/43` PASS;
- doctor modes PASS;
- sintaxis PHP/Bash PASS;
- negativos públicos PASS;
- runtime MySQL fixture PASS;
- CI sin regresiones I2;
- PowerShell demostrado por `windows-static` o entorno equivalente.

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
