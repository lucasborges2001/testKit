# Uso de TestKit

## 1) Objetivo

`testkit` es la base de testing del proyecto: runners, convenciones, helpers y reportes.  
No incluye tests de dominio.

## 2) Flujo recomendado (Docker)

1. Definir `TESTKIT_PROJECT_ROOT`.
2. Preparar `test/.env.test`.
3. Verificar entorno con `doctor`.
4. Levantar stack.
5. Ejecutar **un** runner top-level.
6. Leer reportes.

Linux/macOS:

```bash
export TESTKIT_PROJECT_ROOT=/ruta/proyecto
cp docs/examples/.env.test.example "$TESTKIT_PROJECT_ROOT/test/.env.test"
./bin/testkit doctor
./bin/testkit up -d
./bin/testkit run --rm testkit php runTest.php
./bin/testkit run --rm testkit php /workspace/testkit/scripts/report.php
```

PowerShell:

```powershell
$env:TESTKIT_PROJECT_ROOT='D:\Proyecto'
Copy-Item .\docs\examples\.env.test.example "$env:TESTKIT_PROJECT_ROOT\test\.env.test"
.\bin\testkit.ps1 doctor
.\bin\testkit.ps1 up -d
.\bin\testkit.ps1 run --rm testkit php runTest.php
.\bin\testkit.ps1 run --rm testkit php /workspace/testkit/scripts/report.php
```

---

## 2.1) Doctor y contrato mínimo

`doctor` valida:

- que `TESTKIT_PROJECT_ROOT` y `TESTKIT_ROOT` resuelvan a directorios reales
- que el env elegido viva **dentro** del repo montado
- que el contrato mínimo de MySQL esté completo para el modo operativo elegido
- que `docker` esté disponible

### Política de provisión del store

`TEST_STORE_PROVISION` define el contrato esperado:

- `managed`: `testkit` crea/valida la DB y exige credenciales admin
- `external`: la DB ya existe y `testkit` no exige credenciales admin

Variables relevantes para MySQL:

- runtime: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
- admin (solo `managed`): `TEST_MYSQL_ADMIN_USER`, `TEST_MYSQL_ROOT_PASSWORD`

---

## 3) Política real de paralelismo

Acá conviene ser explícitos y no vender humo.

### 3.1) Modelo soportado

El modelo soportado es:

- **un runner top-level**
- **paralelismo dentro de una suite** usando `TEST_JOBS`

Ejemplo correcto:

```bash
TEST_JOBS=4 TEST_DB_STRATEGY=per_worker php runTest.php back-php
```

### 3.2) Modelo NO soportado como throughput normal

No está soportado correr **varios runners top-level** sobre el mismo proyecto/store como estrategia normal de throughput:

```bash
# no recomendado / no soportado para suites con DB integration/e2e
php runTest.php back-php &
php runTest.php front-php &
```

`per_worker` **no** resuelve ese caso.  
Solo aísla workers **dentro de una misma suite**.  
Dos corridas top-level distintas vuelven a reutilizar `w01`, `w02`, etc. y terminan peleando por el mismo store base.

### 3.3) Qué hace testkit ahora

Para suites con tests `integration` o `e2e` que además usan DB:

- si `TEST_JOBS > 1`, `testkit` exige `TEST_DB_STRATEGY=per_worker`
- si intentás `shared` o `clean`, falla **antes** de correr
- además serializa el acceso al store base para evitar dos corridas top-level concurrentes sobre la misma DB

La decisión es intencional:  
mejor fallar explícito que producir ruido de bootstrap, seeds cruzados o falsos positivos.

---

## 4) `TEST_JOBS`

`TEST_JOBS` controla cuántos workers usa una suite.

```bash
TEST_JOBS=1 php runTest.php back-php
TEST_JOBS=4 php runTest.php back-php
```

Reglas:

- `1` = ejecución secuencial
- `>1` = ejecución paralela **intra-suite**
- no paraleliza automáticamente todas las suites “por afuera”
- no convierte tests no deterministas en `parallel-safe`

Cuándo usarlo:

- suites grandes
- tests unitarios puros
- integration tests que ya soportan aislamiento real por worker

Cuándo NO usarlo:

- cuando la suite comparte una sola DB mutable
- cuando hay tests que dependen de orden global
- cuando el proyecto todavía tiene escenarios frágiles o bootstrap no idempotente

---

## 5) `TEST_DB_STRATEGY`

Valores admitidos:

- `shared`
- `clean`
- `per_worker`

`migration-contract` exige `shared`. Si querés validar una base restaurada, primero cerrá el contrato de migración sobre una DB única y recién después pensá en clonar workers.

### `shared`

Todos los tests usan la misma DB.

Útil para:

- modo secuencial
- suites unitarias o smoke sin mutación peligrosa
- diagnósticos locales simples

No usar con:

- `TEST_JOBS > 1` en suites con `integration`/`e2e` + DB

### `clean`

Mantiene una sola DB pero la limpia entre tests sensibles.

Útil para:

- corridas secuenciales donde querés reducir drift entre tests

No usar con:

- `TEST_JOBS > 1` en suites con DB integration/e2e

Razón: sigue habiendo una sola DB mutable.

### `per_worker`

Cada worker deriva su propia DB desde la base del proyecto.

Ejemplo conceptual:

- worker 1 → `mi_db_w01`
- worker 2 → `mi_db_w02`
- worker 3 → `mi_db_w03`

Es la **única** ruta soportada para paralelismo intra-suite con DB real.

Config:

```bash
TEST_JOBS=4
TEST_DB_STRATEGY=per_worker
TEST_DB_WORKER_SUFFIX_FORMAT=_w%02d
```

### `TEST_BASELINE_CLONE_PER_WORKER`

Primer paso nuevo: si activás esta opción, `testkit` prepara una DB baseline una sola vez y luego clona esa baseline a los workers en vez de repetir el pipeline completo por cada worker.

```bash
TEST_DB_STRATEGY=per_worker
TEST_BASELINE_CLONE_PER_WORKER=1
TEST_BASELINE_DB_SUFFIX=_baseline
```

Usalo cuando:

- el bootstrap estructural es costoso
- querés partir siempre del mismo estado validado
- necesitás que los workers hereden el mismo baseline

No lo uses como atajo para tests frágiles.  
Si los tests comparten estado por fuera de la DB, seguirán rompiéndose igual.

---

## 6) Baseline modes

### `TEST_BASELINE_MODE`

Valores:

- `layered` (default)
- `snapshot`

#### `layered`
Comportamiento clásico:

- reset
- `schema/`
- `base/`
- migraciones pedidas
- `validations/`

#### `snapshot`
Nuevo primer paso para migraciones reales:

- reset
- restore de artefacto lógico `.sql` o `.sql.gz`
- migraciones pedidas
- `validations/`

Variables asociadas:

- `TEST_BASELINE_MODE=snapshot`
- `TEST_BASELINE_SNAPSHOT_FILE=/workspace/project/test/seeds/mysql/baseline/latest.sql.gz`

Restricciones actuales de este primer cambio:

- soporte operativo implementado para MySQL
- el snapshot debe ser un dump lógico accesible desde el contenedor
- si el dump incluye reglas muy específicas de restore, el proyecto debe validarlo explícitamente

---

## 7) MySQL / store / seeds

### 7.1) Bootstrapping por worker

`scripts/seed.sh` y `scripts/seed.ps1` ya provisionan/bootstrap por worker cuando:

- `TEST_DB_STRATEGY=per_worker`
- `TEST_JOBS>1`

A partir de este primer cambio, el bootstrap de suite también puede materializar una baseline y clonar workers cuando `TEST_BASELINE_CLONE_PER_WORKER=1`.

### 7.2) Anti-carreras de store

Las acciones de store (`provision`, `reset`, `clean`, `seed`, `bootstrap`) quedan serializadas por `driver + db`.

Eso evita:

- bootstrap cruzado
- dos procesos truncando o recreando la misma DB al mismo tiempo
- ruido operativo difícil de diagnosticar

Importante: esto **no** vuelve seguro el modelo de varios runners top-level.  
Solo elimina una clase de carrera en las operaciones de store.

### 7.3) `TESTKIT_SKIP_STORE_BOOTSTRAP`

Variable de optimización para iteración local.

```bash
TESTKIT_SKIP_STORE_BOOTSTRAP=1 php runTest.php back-php
```

Cuando está activa, `ContractWorldBootstrap::prepare()` retorna inmediatamente sin provisionar ni seedear.

**Contrato:**

- Aplica **solo** en contexto suite (back-php, front-php, front-js, back-python, migration-contract).
- **No aplica** cuando se invoca por CLI: `php scripts/store_router.php bootstrap` siempre corre, independientemente del valor de esta variable.
- Usar cuando la DB ya está bootstrapeada y querés saltearte el seed para una corrida rápida.
- No usar en CI: el CI siempre debe arrancar con bootstrap real.

### 7.4) Recomendación operativa

Para suites con MySQL real:

- corré **un** runner top-level
- si querés throughput, subí `TEST_JOBS`
- usá `TEST_DB_STRATEGY=per_worker`
- si el bootstrap es pesado, evaluá `TEST_BASELINE_CLONE_PER_WORKER=1`

Ejemplo:

```bash
TEST_JOBS=4 \
TEST_DB_STRATEGY=per_worker \
TEST_BASELINE_CLONE_PER_WORKER=1 \
php runTest.php back-php
```

---

## 8) Seeds y lifecycle

- `scripts/seed.sh` / `scripts/seed.ps1` provisionan el store target y aplican el pipeline estructural.
- En modo `layered`, `testkit` ejecuta `schema/`, luego `base/`, luego migraciones pedidas y al final `validations/`.
- En modo `snapshot`, `testkit` restaura el artefacto declarado por `TEST_BASELINE_SNAPSHOT_FILE`, luego aplica migraciones y validaciones.
- `TEST_SEED_MIGRATIONS=m1,m2` agrega migraciones estructurales sobre la base materializada.
- `TEST_SEED_FIXTURES` deja de ser parte del lifecycle de infraestructura en modo layered.
- Los escenarios de negocio deben construirse desde `test/_support` con builders del proyecto.

---

## 9) Targets

### Suites

- `all`
- `back`
- `back-php`
- `back-py`
- `front`
- `front-php`
- `front-js`
- `php`
- `js`
- `migration-contract`

### Categorías

- `smoke`
- `perf`
- `stress`
- `contract`
- `critical`
- `slow`

Ejemplos:

```bash
php runTest.php back
php runTest.php back-py
php runTest.php smoke
TEST_SCOPE=integration TEST_MATCH=ocpp php runTest.php back
TEST_CATEGORY=critical php runTest.php all
```

## 8.1) Target `migration-contract`

Este target no intenta correr tests funcionales del proyecto. Su trabajo es más chico y más duro: comprobar que una baseline restaurada puede bootstrapearse, migrarse y dejar un `manifest` consistente.

Contrato:

- requiere `TEST_BASELINE_MODE=snapshot`
- requiere exactamente uno de:
  - `TEST_BASELINE_SNAPSHOT_FILE` (path directo al dump)
  - `TEST_BASELINE_BACKUPKIT_METADATA_JSON` (metadata sidecar de backupkit)
  - `TEST_BASELINE_BACKUPKIT_REPORT_JSON` (reporte JSON de backupkit)
- requiere `TEST_DB_STRATEGY=shared` (el único valor aceptado; `per_worker` y `clean` son rechazados)
- solo soporta MySQL (`DB_DRIVER=mysql` o default); rechaza explícitamente otros drivers
- escribe `migration_contract_latest.json` en el report root de la corrida

Ejemplo:

```bash
TEST_BASELINE_MODE=snapshot \
TEST_BASELINE_SNAPSHOT_FILE=/workspace/project/test/seeds/mysql/baseline/latest.sql.gz \
TEST_DB_STRATEGY=shared \
php runTest.php migration-contract
```

Cuándo usarlo:

- cuando querés validar upgrades contra un dump lógico conocido
- antes de habilitar clone-per-worker desde baseline
- como gate técnico previo a suites funcionales más caras

Cuándo no usarlo:

- para throughput normal
- para reemplazar tests del dominio
- para esconder problemas de seeds frágiles detrás de un snapshot

---

## 10) Scope, tags y convenciones

`TEST_SCOPE` filtra por `unit|integration|e2e|all`.

`TEST_CATEGORY` filtra por tags.

Tags se detectan por:

- ruta/nombre (`smoke`, `perf`, `stress`, `critical`, `contract`, `slow`, `fragile`)
- metadata en cabecera del test (`TAGS:` o `@tags`)

Ejemplo cabecera:

```text
# TAGS: smoke,critical,contract
```

### Marcar tests no `parallel-safe`

Si un test o grupo de tests todavía no tolera paralelismo real, no lo tapes con magia.

Conviene:

- moverlo a una ruta que deje claro su scope real
- etiquetarlo explícitamente
- documentar por qué necesita secuencia
- dejar `TEST_JOBS=1` para esa suite o ese flujo

`testkit` no intenta “arreglar” automáticamente tests frágiles.

---

## 11) Coverage (diagnóstico)

Activación:

```bash
TEST_COVERAGE=1 TEST_COVERAGE_FORMAT=both php runTest.php back-php
```

Salida:

- `test/coverage/php_back/coverage.json`
- `test/coverage/php_back/lcov.info`
- `test/coverage/php_back/coverage_diagnostics.json`
- `test/coverage/php_back/coverage_report.md`

Variables útiles:

- `TEST_COVERAGE_SOURCE_DIRS=back,public_html`
- `TEST_COVERAGE_LOW_THRESHOLD=70`
- `TEST_COVERAGE_CRITICAL_FILES=back/auth/*.php,back/contrato/*.php`
- `TEST_COVERAGE_CRITICAL_THRESHOLD=85`

---

## 12) Reportes

### 12.1) Root de reportes

Los reportes operativos viven bajo `.testkit/reports`.

Para corridas top-level normales, `testkit` ahora aísla cada corrida en:

```text
.testkit/reports/runs/<run_id>/
```

Eso evita colisiones entre:

- `*_latest.json`
- `runs_latest.json`
- reportes meta y de suite

Además publica un manifiesto compartido con la **última corrida completada** para que `scripts/report.php` pueda resolver qué root leer.

### 12.2) Qué leer

Reporte consolidado:

```bash
php scripts/report.php
```

`scripts/report.php` busca en este orden:

1. `TEST_RUN_ID` explícito
2. `latest_run.json`
3. fallback legacy al root compartido

### 12.3) Qué incluye

- resumen por suite (`suite_status`, `no_tests_reason`)
- resumen por módulo
- fallas agrupadas
- tests lentos
- indicios de fragilidad
- capacidades reales por suite
- diff contra corrida previa
- familias dominantes de fallo para triage rápido

---

## 13) Variables clave

- `TEST_SCOPE`
- `TEST_CATEGORY`
- `TEST_MATCH`
- `TEST_FAIL_FAST`
- `TEST_META_FAIL_FAST`
- `TEST_CHILD_FAIL_FAST`
- `TEST_JOBS`
- `TEST_DB_STRATEGY`
- `TEST_DB_WORKER_SUFFIX_FORMAT`
- `TEST_STORE_PROVISION`
- `TEST_BASELINE_MODE`
- `TEST_BASELINE_SNAPSHOT_FILE`
- `TEST_BASELINE_CLONE_PER_WORKER`
- `TEST_BASELINE_DB_SUFFIX`
- `TEST_SEED_MIGRATIONS`
- `TEST_PYTHON_BINARY`
- `TEST_COVERAGE`
- `TEST_COVERAGE_FORMAT`
- `TEST_SLOW_THRESHOLD_MS`
- `TEST_PERF_MAX_MS`
- `TEST_REPORT_KEEP`
- `TEST_RUNS_INDEX_KEEP`

---

## 14) Ejemplos recomendados

### Secuencial seguro

```bash
TEST_JOBS=1 TEST_DB_STRATEGY=shared php runTest.php back-php
```

### Paralelo intra-suite con DB real

```bash
TEST_JOBS=4 TEST_DB_STRATEGY=per_worker php runTest.php back-php
```

### Paralelo intra-suite con clone desde baseline

```bash
TEST_JOBS=4 \
TEST_DB_STRATEGY=per_worker \
TEST_BASELINE_CLONE_PER_WORKER=1 \
php runTest.php back-php
```

### Migración sobre snapshot restaurado

```bash
TEST_BASELINE_MODE=snapshot \
TEST_BASELINE_SNAPSHOT_FILE=/workspace/project/test/seeds/mysql/baseline/latest.sql.gz \
TEST_SEED_MIGRATIONS=021_add_checkout,022_fix_fk \
php runTest.php back-php
```

### Lo que NO deberías hacer para throughput

```bash
php runTest.php back-php &
php runTest.php back-php &
```

---

## 15) Límites vigentes

- `per_worker` aísla workers dentro de una suite, **no** corridas top-level distintas.
- Si un test depende de estado externo no modelado por `testkit`, sigue sin ser `parallel-safe`.
- El soporte snapshot de esta primera pasada está pensado para MySQL.
- El runner Python usa `trace` de stdlib para cobertura; no reemplaza herramientas dedicadas.
- Los hints de fragilidad son heurísticas basadas en historial local.
- Las familias de fallo de triage son heurísticas: sirven para priorizar, no para cerrar diagnóstico.
- `testkit` no decide reglas de negocio: solo infraestructura de testing.

## 7.1) Baselines reutilizables

Variables nuevas:

- `TEST_BASELINE_MODE=layered|snapshot`
- `TEST_BASELINE_SNAPSHOT_FILE=/ruta/backup.sql.gz`
- `TEST_BASELINE_REUSE=1`
- `TEST_BASELINE_CLONE_PER_WORKER=1`
- `TEST_BASELINE_INVALIDATE=1`
- `TEST_BASELINE_DB_NAME` o `TEST_BASELINE_DB_SUFFIX`
- `TEST_BASELINE_MANIFEST_PATH` (opcional)

Comportamiento:

- si `TEST_BASELINE_REUSE=1` y el manifest coincide con el fingerprint actual, `testkit` reutiliza la baseline existente
- si `TEST_BASELINE_INVALIDATE=1`, descarta la baseline y la reconstruye
- en `per_worker` con clone habilitado, se prepara una baseline y se clona a `w01`, `w02`, etc.

Ejemplo:

```bash
TEST_DB_STRATEGY=per_worker \
TEST_BASELINE_CLONE_PER_WORKER=1 \
TEST_BASELINE_REUSE=1 \
TEST_BASELINE_MODE=layered \
php runTest.php back-php
```

Snapshot + reuse:

```bash
TEST_DB_STRATEGY=per_worker \
TEST_BASELINE_CLONE_PER_WORKER=1 \
TEST_BASELINE_MODE=snapshot \
TEST_BASELINE_SNAPSHOT_FILE=/workspace/project/test/seeds/mysql/baseline/prod_latest.sql.gz \
TEST_BASELINE_REUSE=1 \
php runTest.php back-php
```

Invalidación operativa:

```bash
./scripts/invalidate_baseline.sh mysql mi_db_baseline
```


## 14.1) Límite deliberado del target de migraciones

`migration-contract` no acepta `per_worker` porque ese modo agrega otra variable al diagnóstico. Primero validás que la baseline y las migraciones cierren sobre una sola DB. Después, si querés throughput, clonás esa baseline a workers.


## 7.1) Snapshot baseline desde backupkit

Además de `TEST_BASELINE_SNAPSHOT_FILE`, `testkit` ahora puede resolver el snapshot baseline a partir de artefactos generados por `backupkit`.

Variables admitidas:

- `TEST_BASELINE_BACKUPKIT_METADATA_JSON`
- `TEST_BASELINE_BACKUPKIT_REPORT_JSON`
- `TEST_BASELINE_REQUIRE_BACKUPKIT_SUCCESS=1`

Precedencia de resolución:

1. `TEST_BASELINE_SNAPSHOT_FILE`
2. `TEST_BASELINE_BACKUPKIT_METADATA_JSON`
3. `TEST_BASELINE_BACKUPKIT_REPORT_JSON`

### Metadata sidecar

Si apuntás a `*.sql.gz.metadata.json`, `testkit` toma:

- `path`
- `sha256`
- `size_bytes`
- `engine`
- `project`
- `resource`

### Reporte JSON

Si apuntás a `backup-report.json`, `verify-artifact-report.json` o `restore-test-report.json`, `testkit` intenta resolver el artefacto desde:

- `artifacts[].path`
- fallback `artifact.path`
- fallback `restore_test.artifact.path`

Con `TEST_BASELINE_REQUIRE_BACKUPKIT_SUCCESS=1`, el reporte debe traer `final_status/status` y no puede estar en `ERROR|FAIL|FAILED`.

### Wrapper operativo

```bash
./scripts/migration_contract_from_backup.sh report /ruta/restore-test-report.json
./scripts/migration_contract_from_backup.sh metadata /ruta/mi_dump.sql.gz.metadata.json
```



## 15) Estado de migración y pendientes incrementales

`migration-contract` ya no depende únicamente de `TEST_SEED_MIGRATIONS`.

Puede resolver qué migraciones faltan usando tres estrategias:

- `TEST_MIGRATION_STATE_MODE=explicit`
  - declarar `TEST_MIGRATION_APPLIED=001_init,002_acl`
- `TEST_MIGRATION_STATE_MODE=manifest_table`
  - leer una tabla real de control:
    - `TEST_MIGRATION_STATE_TABLE`
    - `TEST_MIGRATION_STATE_COLUMN`
- `TEST_MIGRATION_STATE_MODE=state_files`
  - cada migración puede declarar `test/seeds/<driver>/migrations/<id>/state.json`

Ejemplo de `state.json`:

```json
{
  "markers": [
    { "type": "table", "name": "CheckoutCargaOrden" },
    { "type": "column", "table": "CheckoutCargaOrden", "column": "id" },
    { "type": "column", "table": "CheckoutCargaOrden", "column": "sesion_carga_id" }
  ]
}
```

`migration.json` es obligatorio por carpeta y hoy admite exactamente:

- `id`
- `kind=active_migration|optional_migration|historical_absorbed_change`

`state.json` también es metadata canónica: el resolver incremental y las probes operativas deben derivar de esa definición mínima, no duplicarla en otra fuente paralela.

El resolver incremental no usa el manifest como fuente estructural y solo considera
las migraciones marcadas como ejecutables en `test/seeds/<driver>/migrations/<id>/migration.json`.
Los cambios etiquetados como `historical_absorbed_change` quedan disponibles solo
como metadata histórica y no contaminan `available/pending`.

Variables clave:

- `TEST_MIGRATION_STATE_MODE`
- `TEST_MIGRATION_APPLIED`
- `TEST_MIGRATION_STATE_TABLE`
- `TEST_MIGRATION_STATE_COLUMN`
- `TEST_MIGRATION_TARGET=latest|<id>`
- `TEST_MIGRATION_AUTO_PENDING=1`

Política recomendada para upgrades reales:

```bash
TEST_BASELINE_MODE=snapshot
TEST_MIGRATION_STATE_MODE=manifest_table
TEST_MIGRATION_STATE_TABLE=migrations
TEST_MIGRATION_STATE_COLUMN=version
TEST_MIGRATION_TARGET=latest
TEST_MIGRATION_AUTO_PENDING=1
php runTest.php migration-contract
```

Si además definís `TEST_SEED_MIGRATIONS`, ese valor explícito gana y desactiva el cálculo automático de pendientes para esa corrida.
