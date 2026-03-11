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

- `testkit/_out/coverage/php_back/coverage.json`
- `testkit/_out/coverage/php_back/lcov.info`
- `testkit/_out/coverage/php_back/coverage_diagnostics.json`
- `testkit/_out/coverage/php_back/coverage_report.md`

Variables utiles:

- `TEST_COVERAGE_SOURCE_DIRS=back,public_html`
- `TEST_COVERAGE_LOW_THRESHOLD=70`
- `TEST_COVERAGE_CRITICAL_FILES=back/auth/*.php,back/contrato/*.php`
- `TEST_COVERAGE_CRITICAL_THRESHOLD=85`

## 6) Reportes

Archivos generados:

- `testkit/_out/reports/*_latest.json`
- `testkit/_out/history/*.json`

Reporte consolidado:

```bash
php scripts/report.php
```

Incluye:

- resumen por suite
- resumen por modulo
- fallas agrupadas
- tests lentos
- indicios de fragilidad
- gaps de coverage

## 7) Variables clave

- `TEST_SCOPE`
- `TEST_CATEGORY`
- `TEST_MATCH`
- `TEST_FAIL_FAST`
- `TEST_META_FAIL_FAST`
- `TEST_CHILD_FAIL_FAST`
- `TEST_JOBS`
- `TEST_PYTHON_BINARY`
- `TEST_COVERAGE`
- `TEST_COVERAGE_FORMAT`
- `TEST_SLOW_THRESHOLD_MS`
- `TEST_PERF_MAX_MS`

## 8) Limites

- El runner Python usa `trace` de stdlib para cobertura; no reemplaza herramientas dedicadas.
- Los hints de fragilidad son heuristicas basadas en historial local.
- `testkit` no decide reglas de negocio: solo infraestructura de testing.
