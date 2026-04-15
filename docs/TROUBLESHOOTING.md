# Troubleshooting operativo

## 1) Qué responde esta guía

Usar esta guía cuando ya tenés un síntoma y necesitás una ruta de diagnóstico concreta:

1. identificá el síntoma exacto
2. corré el comando asociado
3. corregí primero la causa contractual más cercana
4. no avances a filtros, paralelismo o snapshot mientras el setup base siga roto

No usarla como quick start ni como contrato general.
Para eso, leer:

- [`USO.md`](USO.md)
- [`CONTRATO.md`](CONTRATO.md)

## 2) Comandos de referencia

### Linux/macOS

```bash
./bin/testkit doctor
./bin/testkit doctor --dump
./bin/testkit inspect latest
./bin/testkit inspect failure
./bin/testkit inspect seed-state
./bin/testkit inspect concurrency
./bin/testkit run --rm testkit php runTest.php back-php
./bin/testkit run --rm testkit php /workspace/testkit/scripts/report.php
```

### PowerShell

```powershell
.\bin\testkit.ps1 doctor
.\bin\testkit.ps1 doctor --dump
.\bin\testkit.ps1 inspect latest
.\bin\testkit.ps1 inspect failure
.\bin\testkit.ps1 inspect seed-state
.\bin\testkit.ps1 inspect concurrency
.\bin\testkit.ps1 run --rm testkit php runTest.php back-php
.\bin\testkit.ps1 run --rm testkit php /workspace/testkit/scripts/report.php
```

## 3) Síntomas comunes

## 3.1) `doctor` dice que falta el env de tests

Síntoma típico:

- `Falta env de tests`
- `falta env de tests: test/.env.test (preferido) o .env.test (root)`

Por qué falla:

- el proyecto no tiene env de tests en una ubicación soportada por el wrapper

Qué revisar:

- `<project>/test/.env.test`
- `<project>/.env.test`

Qué comando correr:

```bash
./bin/testkit doctor
```

Comportamiento esperado:

- una vez creado el env, `doctor` deja de fallar por este motivo

Cuándo sospechar bug:

- si el archivo existe en una de esas dos ubicaciones y el wrapper igual no lo encuentra

## 3.2) `doctor` dice que el env quedó fuera del repo montado

Síntoma típico:

- `env detectado fuera del repo montado`
- `Debe vivir dentro de <project> para que DB_ENV_PATH sea válido dentro del contenedor`

Por qué falla:

- el wrapper solo soporta un env que pueda mapearse a `/workspace/project/...` dentro del contenedor

Qué comando correr:

```bash
./bin/testkit doctor --dump
```

Qué hacer:

- mover el env a `<project>/test/.env.test`
- o a `<project>/.env.test`

Comportamiento esperado:

- el env queda resoluble dentro del contenedor sin paths host específicos

No hacer:

- apuntar `TESTKIT_ENV_FILE` a una ruta fuera del repo del proyecto

## 3.3) `TESTKIT_ROOT no parece repo completo`

Síntoma típico:

- falta `runTest.php` en `TESTKIT_ROOT`

Por qué falla:

- `TESTKIT_ROOT` apunta a algo que no es el root real del repo de `testkit`

Qué comando correr:

```bash
./bin/testkit doctor --dump
```

Qué hacer:

- apuntar `TESTKIT_ROOT` al root del repo, no a `bin/`, no a una copia parcial

Comportamiento esperado:

- `doctor` ve `runTest.php` en el root de `testkit`

## 3.4) Docker no está en PATH

Síntoma típico:

- `[FAIL] docker no está disponible en PATH`

Por qué falla:

- Docker no está instalado, no está levantado o no quedó visible para la shell actual

Qué comando correr:

```bash
./bin/testkit doctor
```

Qué hacer:

- abrir Docker Desktop o levantar el daemon
- abrir una shell nueva si PATH cambió

Cuándo es setup esperado:

- cuando Docker todavía no está listo

Cuándo sospechar bug:

- si `docker version` funciona en la misma shell y `doctor` igual no lo ve

## 3.5) faltan credenciales admin MySQL con `TEST_STORE_PROVISION=managed`

Síntoma típico:

- falta `TEST_MYSQL_ADMIN_USER`
- falta `TEST_MYSQL_ROOT_PASSWORD`

Por qué falla:

- en modo `managed`, MySQL necesita credenciales runtime y admin

Qué comando correr:

```bash
./bin/testkit doctor --dump
```

Qué hacer:

- definir credenciales admin
- o cambiar a `TEST_STORE_PROVISION=external` solo si la DB ya existe y el proyecto no necesita create/drop/clone de DB auxiliares

No hacer:

- usar `external` para esconder un flow que en realidad necesita baseline clone o create/drop

## 3.6) la corrida falla con `shared_store_locked` o `store_resource_locked`

Síntoma típico:

- error de contention
- otra ejecución ya está usando el mismo `driver/db`

Por qué falla:

- ya hay otro runner top-level sobre el mismo store base

Qué comando correr:

```bash
./bin/testkit inspect concurrency
```

Qué hacer:

- dejar una sola corrida top-level activa
- usar un solo `runTest.php`
- si necesitás throughput intra-suite, usar `TEST_JOBS>1` con `TEST_DB_STRATEGY=per_worker` donde corresponda

Qué comportamiento sí es esperado:

- el lock es defensa normal del contrato top-level

Qué indica contrato roto:

- contention sin otra corrida activa ni locks residuales visibles

## 3.7) `unsafe parallel db configuration`

Síntoma típico:

- la suite usa `TEST_JOBS>1`
- hay tests DB-sensibles
- `TEST_DB_STRATEGY` no es `per_worker`

Por qué falla:

- el paralelismo intra-suite con DB real solo cierra por contrato con `per_worker`

Qué comando correr:

```bash
./bin/testkit inspect concurrency
```

Qué hacer:

- volver a `TEST_JOBS=1`
- o cambiar a `TEST_DB_STRATEGY=per_worker`

No interpretar como:

- permiso para lanzar dos meta-runners al mismo tiempo

## 3.8) `TEST_DB_STRATEGY=clean` rechazado

Por qué falla:

- `clean` no está implementado

Qué hacer:

- usar `shared`
- o `per_worker` si el problema real es aislamiento intra-suite

Qué comportamiento sí es esperado:

- el rechazo explícito

Qué indicaría bug:

- que el sistema intentara correr `clean` como si fuera un modo cerrado

## 3.9) la corrida termina en `no_tests`

Síntoma típico:

- `suite_status=no_tests`
- `no_tests_reason=no tests matched the current filters (...)`

Por qué falla:

- los filtros dejaron la selección vacía

Qué comando correr después de la corrida:

```bash
./bin/testkit inspect latest
```

Qué revisar:

- `target`
- `TEST_SCOPE`
- `TEST_CATEGORY`
- `TEST_MATCH`
- root real de la suite

Qué comportamiento sí es esperado:

- filtros demasiado estrechos
- suite correcta pero sin archivos que coincidan

No confundir con:

- `all_skipped`
- `bootstrap_error`

## 3.10) la corrida termina en `all_skipped`

Por qué pasa:

- la selección entró, pero todos los tests se auto-saltaron

Qué revisar:

- prerequisitos que los tests esperan
- condiciones del proyecto que disparan skip

Qué comando correr:

```bash
./bin/testkit inspect latest
```

Qué comportamiento sí es esperado:

- tests con guards explícitos de entorno

Qué indicaría algo más serio:

- que el proyecto espere ejecutar esos tests normalmente y todos se salten por un prerequisito roto

## 3.11) falla el bootstrap/store

Síntoma típico:

- `bootstrap_error`
- `No se pudo resetear la DB`
- `No se pudo conectar`
- `El bootstrap estructural devolvió exit=...`

Por qué falla:

- el problema ya no es wrapper; es conexión, provision, reset, seed pipeline o restore

Qué comando correr:

```bash
./bin/testkit inspect failure
./bin/testkit inspect seed-state
```

Qué revisar:

- host/port/user/pass
- `TEST_STORE_PROVISION`
- estructura de `test/seeds/<driver>/`
- baseline mode real
- permisos reales sobre la DB

Qué comportamiento sí es esperado:

- fallo temprano si el store no existe o las credenciales no cierran
- fallo temprano si el seed estructural está roto

## 3.12) snapshot + auto pending sin fuente confiable de estado

Síntoma típico:

- error diciendo que no hay fuente confiable de estado de migraciones

Por qué falla:

- sobre una DB restaurada, `testkit` no asume que “todo está pendiente”

Qué hacer:

usar una de estas rutas:

- `TEST_MIGRATION_APPLIED=...`
- `TEST_MIGRATION_STATE_TABLE=...`
- `state.json` por migración
- `TEST_SEED_MIGRATIONS=...`

Qué comando correr:

```bash
./bin/testkit inspect seed-state
```

Qué comportamiento sí es esperado:

- rechazo explícito si el snapshot no trae una fuente confiable de estado

## 3.13) `migration-contract` falla por modo o motor

Síntoma típico:

- requiere `TEST_BASELINE_MODE=snapshot`
- requiere `TEST_DB_STRATEGY=shared`
- solo acepta MySQL

Por qué falla:

- `migration-contract` no es una suite general; es un gate técnico acotado

Qué hacer:

- usar snapshot
- usar shared
- usar MySQL
- asegurar que el snapshot sea resoluble

Qué comportamiento sí es esperado:

- rechazo temprano si no se respeta esa combinación

## 3.14) `clone-per-worker` falla o se rechaza

Síntoma típico:

- rechazo temprano por motor o provision mode
- o fallos de clone/reset/create DB

Por qué falla:

- la ruta cerrada exige MySQL + `TEST_STORE_PROVISION=managed`

Qué hacer:

- volver a `shared`
- o usar `per_worker` sin clone si realmente sabés cómo resolver DBs worker
- o cerrar el contrato en MySQL managed

Qué comportamiento sí es esperado:

- rechazo en PostgreSQL
- rechazo en `external`

## 3.15) coverage no aparece como esperabas

### PHP

Qué revisar:

- `TEST_COVERAGE=1`
- `TEST_COVERAGE_FORMAT`
- que la suite sea PHP
- que realmente se hayan generado artifacts por test

Qué salida esperar:

- `coverage_diagnostics.json`
- `coverage_report.md`
- `coverage.json` y/o `lcov.info` según formato

### Python

Qué no esperar:

- el mismo pipeline diagnóstico cerrado de PHP

Qué sí hace hoy:

- usa `trace` de stdlib
- produce una señal liviana, no analítica avanzada

## 4) Rutas seguras de corrección

## 4.1) Cuando no sabés por dónde empezar

1. `doctor`
2. `doctor --dump`
3. `up -d`
4. una sola suite:
   - `TEST_JOBS=1`
   - `TEST_DB_STRATEGY=shared`
5. `inspect latest`
6. `inspect failure`

## 4.2) Cuando dudás si el problema es filtro o bootstrap

- si el runner llega a `no_tests`, pensá primero en filtros
- si cae en `bootstrap_error`, pensá primero en store/seed/baseline
- si cae en contention, pensá primero en concurrencia top-level

## 4.3) Cuando querés aislar un problema sin agregar ruido

Preferí:

- una suite concreta (`back-php`, `front-js`)
- secuencial
- `shared`
- sin snapshot, salvo que el problema real sea restore/migración

No arranques por:

- `all`
- `per_worker`
- `clone-per-worker`
- `migration-contract`
- coverage

## 5) Qué es esperado y qué huele a bug

### Esperado

- `doctor` falla por env faltante o fuera del repo
- `clean` rechazado
- contention cuando corrés dos top-level a la vez
- `migration-contract` rechazado fuera de snapshot/shared/MySQL
- `no_tests` por filtros demasiado estrechos
- `all_skipped` cuando los tests se auto-saltan

### Huele a bug o contrato roto

- `doctor` y la corrida se contradicen sobre el mismo env/root
- el wrapper sugiere rutas de ejemplo que no existen
- una corrida secuencial simple entra en errores de paralelismo sin otra ejecución activa
- `inspect latest` no puede leer reportes canónicos que el run recién escribió
- el framework intenta tratar como soportado algo documentado como no soportado

## 6) Regla final

No acumules variables hasta “hacerlo andar”.

Si el setup base falla, volver a:

- una suite
- secuencial
- `shared`
- MySQL si querés la ruta cerrada
- sin snapshot salvo que el problema real sea snapshot
