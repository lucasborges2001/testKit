# Uso operativo de testkit

## Novedades de este corte

- `php runTest.php --help` muestra ayuda breve del runner.
- `php runTest.php <target> --list` es una ruta explícita soportada para listar selección sin ejecutar tests.
- `php runTest.php reference-contract` ejecuta el contrato técnico de referencias PHP (`require`, `require_once`, `include`, `include_once`).
- `./bin/testkit inspect config-schema --json` expone el esquema soportado de configuración.
- Warnings por env inválido deben quedar visibles en consola y en los reportes persistidos.
- `inspect config-schema --json` incluye la matriz honesta de soporte por motor/servicio.
- Coverage ahora se centraliza por defecto en `.testkit/coverage/<suite_id>` y su filtro de cálculo usa una política única.

## Matriz corta de soporte

| Componente | Estado operativo |
|---|---|
| MySQL | ruta principal cerrada |
| PostgreSQL | parcial; sin snapshot/clone cerrado |
| Redis | auxiliar; no store estructural core |
| Influx | auxiliar/perfilado; no store driver principal |
| `TEST_DB_STRATEGY=clean` | rechazado explícitamente |
| `TEST_DB_STRATEGY=per_worker` | aislamiento intra-suite, no multi-runner top-level |
| `reference-contract` | scanner estático PHP; no toca DB/store |

Para detalle contractual, leer `SUPPORT_MATRIX.md` y `docs/CONTRATO.md`.

## Reglas operativas

- Targets agregados (`all`, `back`, `front`, `php`, `js`) son válidos, pero no son la primera corrida diagnóstica más nítida.
- Category targets (`smoke`, `perf`, `stress`, `contract`, `critical`, `slow`) no deben mezclarse con `TEST_CATEGORY` explícito distinto.
- `reference-contract` es una suite técnica de referencias PHP; no reemplaza tests funcionales.
- `reference-contract` no debe escanear todo el repo salvo que se pida explícitamente con `TESTKIT_REFERENCE_ROOT=.`.
- `TEST_JOBS>1` con `TEST_DB_STRATEGY=shared` es una señal visible de riesgo; preferí `TEST_JOBS=1` o `per_worker`.
- `TEST_DB_STRATEGY=per_worker` con `TEST_JOBS=1` no rompe contrato, pero suele ser sobreconfiguración.
- `TEST_DB_STRATEGY=clean` no está implementado; no lo uses como fallback.
- No uses `TEST_STORE_DRIVER=redis` ni `TEST_STORE_DRIVER=influx`: son servicios auxiliares, no stores estructurales.

## Comandos de referencia

```bash
./bin/testkit doctor --compact
./bin/testkit inspect config-schema --json
./bin/testkit run --rm testkit php runTest.php --help
./bin/testkit run --rm testkit php runTest.php back-php --list
./bin/testkit run --rm testkit php runTest.php reference-contract
```

## Coverage operativo

Coverage se activa por suite:

```bash
./bin/testkit run --rm \
  -e TEST_COVERAGE=1 \
  -e TEST_COVERAGE_FORMAT=both \
  -e TEST_COVERAGE_SOURCE_DIRS='back,public_html' \
  testkit php runTest.php back-php
```

Ruta default:

```text
.testkit/coverage/back_php
.testkit/coverage/front_php
.testkit/coverage/back_python
```

Overrides:

```bash
-e TEST_COVERAGE_ROOT=/tmp/cov
```

produce:

```text
/tmp/cov/back_php
```

`TEST_COVERAGE_DIR` sigue funcionando por compatibilidad, pero se considera legacy. Mantiene la semántica histórica de root: si se define `TEST_COVERAGE_DIR=/tmp/cov`, el directorio final será `/tmp/cov/<suite_id>`.

Variables relevantes:

```bash
TEST_COVERAGE=1
TEST_COVERAGE_FORMAT=both
TEST_COVERAGE_ROOT=.testkit/coverage
TEST_COVERAGE_DIR=              # legacy root alias
TEST_COVERAGE_SOURCE_DIRS=back,public_html
TEST_COVERAGE_EXCLUDE_DIRS=test,testkit,docker,vendor,logs,storage
TEST_COVERAGE_CRITICAL_FILES='back/service/*.php,public_html/api/*.php'
TEST_COVERAGE_CRITICAL_THRESHOLD=85
TEST_COVERAGE_SUMMARY_TOP=10
```

`TEST_COVERAGE_SOURCE_DIRS` filtra el cálculo real, no solo la resolución de críticos. Afecta `overall`, `files`, `modules`, `low_files`, `critical_missing` y `critical_low`.

`TEST_COVERAGE_EXCLUDE_DIRS` es la política centralizada para excluir directorios. Se aplica en la captura PHP por proceso y en los diagnósticos agregados.

Después de correr coverage, el resumen humano se obtiene con:

```bash
./bin/testkit run --rm testkit php /workspace/testkit/scripts/report.php
```

El bloque `Coverage diagnostics` lista conteos y, hasta `TEST_COVERAGE_SUMMARY_TOP`, archivos concretos de `critical_missing` y `critical_low`.

## Reference contract PHP

`reference-contract` valida includes PHP resolubles sin hacer discovery de tests de dominio.

Targets equivalentes:

```bash
php runTest.php reference-contract
php runTest.php references
php runTest.php php-references
```

Variables principales:

```bash
TESTKIT_REFERENCE_SCOPE=back
TESTKIT_REFERENCE_ROOT=
TESTKIT_REFERENCE_TIMEOUT_SEC=20
TESTKIT_REFERENCE_MAX_FILES=3000
TESTKIT_REFERENCE_MAX_BYTES_PER_FILE=1048576
TESTKIT_REFERENCE_MAX_VIOLATIONS=200
TESTKIT_REFERENCE_DYNAMIC_SEVERITY=warn
TESTKIT_REFERENCE_IGNORE_DIRS=vendor,node_modules,.git,.testkit,testkit/_out,_out
```

Resolución de root:

1. `TESTKIT_REFERENCE_ROOT`, si existe.
2. `TESTKIT_REFERENCE_SCOPE=back` usa `TK_BACK_DIR`.
3. `TESTKIT_REFERENCE_SCOPE=front` usa `TK_FRONT_DIR`; si falta, usa `TK_PUBLIC_DIR`.
4. Sin scope explícito, el default es `back`.

Solo escanea archivos `.php`. En esta primera versión detecta:

- `require 'archivo.php';`
- `require_once 'archivo.php';`
- `include 'archivo.php';`
- `include_once 'archivo.php';`
- concatenaciones simples con `__DIR__`, por ejemplo `require_once __DIR__ . '/../utils/logs.php';`

Los includes dinámicos no fallan por defecto. Con `TESTKIT_REFERENCE_DYNAMIC_SEVERITY=warn` quedan como warning; con `ignore` no aparecen; con `error` fallan la suite.

El reporte JSON se escribe bajo:

```text
.testkit/reports/reference_contract/
```
