# Uso de TestKit

## 1) Objetivo

`testkit` es la base de testing del proyecto: runners, convenciones, helpers y reportes.
No incluye tests de dominio.

## 2) Flujo recomendado (Docker)

1. Definir `TESTKIT_PROJECT_ROOT`.
2. Verificar entorno.
3. Levantar stack.
4. Ejecutar suites.
5. Leer reportes.

Linux/macOS:

```bash
export TESTKIT_PROJECT_ROOT=/ruta/proyecto
./bin/testkit doctor
./bin/testkit up -d
./bin/testkit run --rm testkit php runTest.php
./bin/testkit run --rm testkit php /workspace/testkit/scripts/report.php
```

PowerShell:

```powershell
$env:TESTKIT_PROJECT_ROOT='D:\Proyecto'
.\bin\testkit.ps1 doctor
.\bin\testkit.ps1 up -d
.\bin\testkit.ps1 run --rm testkit php runTest.php
.\bin\testkit.ps1 run --rm testkit php /workspace/testkit/scripts/report.php
```

### Seeds y lifecycle

- `scripts/seed.sh` / `scripts/seed.ps1` provisionan el store target y aplican el pipeline estructural.
- En modo layered, `testkit` ejecuta `schema/`, luego `base/`, luego migraciones pedidas y al final `validations/`.
- `TEST_SEED_MIGRATIONS=m1,m2` agrega migraciones estructurales sobre la base mínima.
- `TEST_SEED_FIXTURES` deja de ser parte del lifecycle de infraestructura en modo layered.
- Los escenarios de negocio deben construirse desde `test/_support` con builders del proyecto.

## 3) Targets

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

### Categorias

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

## 4) Scope, tags y convenciones

`TEST_SCOPE` filtra por `unit|integration|e2e|all`.

`TEST_CATEGORY` filtra por tags.

Tags se detectan por:

- ruta/nombre (`smoke`, `perf`, `stress`, `critical`, `contract`, `slow`, `fragile`)
- metadata en cabecera del test (`TAGS:` o `@tags`)

Ejemplo cabecera:

```text
# TAGS: smoke,critical,contract
```

## 5) Coverage (diagnostico)

Activacion:

```bash
TEST_COVERAGE=1 TEST_COVERAGE_FORMAT=both php runTest.php back-php
```

Salida:

- `test/coverage/php_back/coverage.json`
- `test/coverage/php_back/lcov.info`
- `test/coverage/php_back/coverage_diagnostics.json`
- `test/coverage/php_back/coverage_report.md`

Variables utiles:

- `TEST_COVERAGE_SOURCE_DIRS=back,public_html`
- `TEST_COVERAGE_LOW_THRESHOLD=70`
- `TEST_COVERAGE_CRITICAL_FILES=back/auth/*.php,back/contrato/*.php`
- `TEST_COVERAGE_CRITICAL_THRESHOLD=85`

## 6) Reportes

Archivos generados:

- `test/reports/*_latest.json o test/<side>/<module>/report/*_latest.json`
- `test/history/*.json`

Reporte consolidado:

```bash
php scripts/report.php
```

Incluye:

- resumen por suite (`suite_status`, `no_tests_reason`)
- resumen por modulo
- fallas agrupadas
- tests lentos
- indicios de fragilidad
- capacidades reales por suite
- gaps de coverage


## 6.1) Estados y capacidades por suite

Cada `*_latest.json` incluye:

- `suite_status`: `passed|failed|all_skipped|no_tests|listed`
- `no_tests_reason`: motivo cuando la selección queda vacía
- `runner_capabilities`: matriz mínima de soporte por suite

Esto evita asumir que todas las suites tienen exactamente la misma superficie de features.

## 7) Variables clave

- `TEST_SCOPE`
- `TEST_CATEGORY`
- `TEST_MATCH`
- `TEST_FAIL_FAST`
- `TEST_META_FAIL_FAST`
- `TEST_CHILD_FAIL_FAST`
- `TEST_JOBS`
- `TEST_DB_STRATEGY`
- `TEST_DB_WORKER_SUFFIX_FORMAT`
- `TEST_SEED_MIGRATIONS`
- `TEST_PYTHON_BINARY`
- `TEST_COVERAGE`
- `TEST_COVERAGE_FORMAT`
- `TEST_SLOW_THRESHOLD_MS`
- `TEST_PERF_MAX_MS`

## 8) Limites

- El runner Python usa `trace` de stdlib para cobertura; no reemplaza herramientas dedicadas.
- Los hints de fragilidad son heuristicas basadas en historial local.
- `testkit` no decide reglas de negocio: solo infraestructura de testing.
