# Uso de TestKit

## 1) Objetivo

`testkit` es la base de testing del proyecto: runners, convenciones, helpers y reportes.
No incluye tests de dominio.

## 2) Flujo recomendado (Docker)

1. Definir `TESTKIT_PROJECT_ROOT`.
2. Preparar `test/.env.test`.
3. Verificar entorno con `doctor`.
4. Levantar stack.
5. Ejecutar suites.
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

## 2.1) Doctor y contrato mínimo

`doctor` ahora valida más que la existencia del archivo:

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

## 3) Seeds y lifecycle

- `scripts/seed.sh` / `scripts/seed.ps1` provisionan el store target y aplican el pipeline estructural.
- En modo layered, `testkit` ejecuta `schema/`, luego `base/`, luego migraciones pedidas y al final `validations/`.
- `TEST_SEED_MIGRATIONS=m1,m2` agrega migraciones estructurales sobre la base mínima.
- `TEST_SEED_FIXTURES` deja de ser parte del lifecycle de infraestructura en modo layered.
- Los escenarios de negocio deben construirse desde `test/_support` con builders del proyecto.

## 4) Targets

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

## 5) Scope, tags y convenciones

`TEST_SCOPE` filtra por `unit|integration|e2e|all`.

`TEST_CATEGORY` filtra por tags.

Tags se detectan por:

- ruta/nombre (`smoke`, `perf`, `stress`, `critical`, `contract`, `slow`, `fragile`)
- metadata en cabecera del test (`TAGS:` o `@tags`)

Ejemplo cabecera:

```text
# TAGS: smoke,critical,contract
```

## 6) Coverage (diagnóstico)

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

## 7) Reportes

Archivos generados:

- `test/reports/*_latest.json` o `test/<side>/<module>/report/*_latest.json`
- `test/history/*.json`
- `runs_latest.json` por scope de reporte

Reporte consolidado:

```bash
php scripts/report.php
```

Incluye:

- resumen por suite (`suite_status`, `no_tests_reason`)
- resumen por módulo
- fallas agrupadas
- tests lentos
- indicios de fragilidad
- capacidades reales por suite
- diff contra corrida previa
- **dominant blockers** / familias de fallo para triage rápido

### 7.1) Familias de fallo de triage

Cuando una suite falla, `testkit` intenta agrupar por familias operativas:

- `Contrato/env`
- `Drift de schema`
- `Bootstrap/símbolos`
- `Contrato de assertions`
- `Runtime de aplicación`

Esto no reemplaza el stack trace completo, pero sirve para separar rápido:

- problemas de setup
- problemas de schema/migraciones
- problemas de bootstrap/carga
- desalineación entre tests y contrato público
- fallos funcionales reales de la app

## 8) Variables clave

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
- `TEST_SEED_MIGRATIONS`
- `TEST_PYTHON_BINARY`
- `TEST_COVERAGE`
- `TEST_COVERAGE_FORMAT`
- `TEST_SLOW_THRESHOLD_MS`
- `TEST_PERF_MAX_MS`
- `TEST_REPORT_KEEP`
- `TEST_RUNS_INDEX_KEEP`

## 9) Límites

- El runner Python usa `trace` de stdlib para cobertura; no reemplaza herramientas dedicadas.
- Los hints de fragilidad son heurísticas basadas en historial local.
- Las familias de fallo de triage son heurísticas: sirven para priorizar, no para cerrar diagnóstico.
- `testkit` no decide reglas de negocio: solo infraestructura de testing.
