# Verificación — I2 Store explícito

## Estado

```text
ESTADO: PENDIENTE
CLASIFICACION: VERIFICACION_CONTRATO_STORE
IMPLEMENTACION_BASE: 2f8f34c33a25c8bbdd036d3cfccece9cda976c32
ULTIMA_VALIDACION_LOCAL: 0e8479e02dee039adb1f78e2b710ea832fec44a8
ULTIMO_RESULTADO: LOCAL_PASS_RUNTIME_PASS_CI_WINDOWS_PENDIENTES
POWERSHELL_LOCAL: BLOCKED_PWSH_NO_DISPONIBLE
CI_STATUS_CONNECTOR: NO_EVIDENCIA_DISPONIBLE
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

### Contrato, doctor y sintaxis — PASS

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

### Runtime MySQL — PASS

Validado sobre `8370b7f2ef28e7f231f8e8da74af4b6b4a7896fe` usando el host fixture:

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

### Framework final — PASS

Después de corregir el self-test de selectores CI para el host fixture vigente, se revalidó sobre:

```text
0e8479e02dee039adb1f78e2b710ea832fec44a8
```

Resultado:

```text
OK CI typed selectors
Store driver explicit contract PASS
43 passed, 0 failed
Working tree: limpio
```

Los cambios posteriores al runtime MySQL quedaron limitados al self-test de CI y documentación; no se modificó el runtime ya validado.

## CI real — PENDIENTE / NO VERIFICADO

Jobs bloqueantes esperados:

```text
static
windows-static
framework-self-tests
runtime-mysql
browser-runner-smoke
```

La consulta disponible para el SHA `0e8479e02dee039adb1f78e2b710ea832fec44a8` no devolvió workflow runs ni statuses asociados. Esto no se interpreta como PASS ni FAIL.

`windows-static` debe aportar la evidencia PowerShell que no pudo obtenerse localmente.

### Verificación reproducible con GitHub CLI

Desde un checkout autenticado con acceso a Actions:

```bash
gh run list \
  --repo lucasborges2001/testKit \
  --workflow CI \
  --commit 0e8479e02dee039adb1f78e2b710ea832fec44a8 \
  --limit 10
```

Para el run correspondiente:

```bash
gh run view <RUN_ID> \
  --repo lucasborges2001/testKit
```

PASS requiere que los jobs bloqueantes terminen correctamente, en particular:

```text
windows-static
framework-self-tests
runtime-mysql
```

Si no existe run para el SHA candidato, la verificación permanece PENDIENTE; no inventar evidencia.

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

Actualmente todos los criterios locales y runtime están PASS; faltan CI real y PowerShell/Windows.

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
