# Uso operativo de testkit

## Novedades de este corte

- `php runTest.php --help` muestra ayuda breve del runner.
- `php runTest.php <target> --list` es una ruta explícita soportada para listar selección sin ejecutar tests.
- `php runTest.php reference-contract` ejecuta el contrato técnico de referencias PHP (`require`, `require_once`, `include`, `include_once`).
- `./bin/testkit inspect config-schema --json` expone el esquema soportado de configuración.
- Warnings por env inválido deben quedar visibles en consola y en los reportes persistidos.
- `inspect config-schema --json` incluye la matriz honesta de soporte por motor/servicio.
- Coverage ahora se centraliza por defecto en `.testkit/coverage/<suite_id>` y su filtro de cálculo usa una política única.
- `TEST_MATCH_FILE` y `TEST_MATCH_LIST` permiten seleccionar múltiples archivos en una sola suite.
- `TEST_RERUN_FAILED_ISOLATED=1` permite reejecutar solo fallidos, uno por uno, para distinguir fallo real de interferencia probable.

## Matriz corta de soporte

| Componente | Estado operativo |
|---|---|
| MySQL | ruta principal cerrada |
| PostgreSQL | parcial; sin snapshot/clone cerrado |
| Sin store (`TEST_STORE_DRIVER=none`) | ruta no-store; sin credenciales DB ni stack default |
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
- Para proyectos sin store runtime usá `TEST_STORE_DRIVER=none` y `TEST_STORE_PROVISION=external`; no agregues credenciales MySQL falsas.
- No uses `TEST_STORE_DRIVER=redis` ni `TEST_STORE_DRIVER=influx`: son servicios auxiliares, no stores estructurales.
- No lances varios runners top-level en paralelo sobre el mismo store. Usá una sola suite con `TEST_MATCH_FILE`/`TEST_MATCH_LIST` y, si corresponde, `TEST_JOBS`.

## Comandos de referencia

```bash
./bin/testkit doctor --compact
./bin/testkit inspect config-schema --json
./bin/testkit run --rm testkit php runTest.php --help
./bin/testkit run --rm testkit php runTest.php back-php --list
./bin/testkit run --rm testkit php runTest.php infra-php --list
./bin/testkit run --rm testkit php runTest.php reference-contract
```

## Suite infra PHP

`infra_php` corre tests operacionales del host bajo `test/infra`.
Usala para HTTP real, Docker, cookies/auth boundary, seguridad operacional,
rutas reales y validaciones de infraestructura del integrador.

No la uses para dominio funcional PHP puro: eso corresponde a `back_php`.

```bash
./bin/testkit run --rm testkit php runTest.php infra
./bin/testkit run --rm testkit php runTest.php infra-php
./bin/testkit run --rm -e TEST_CATEGORY=security testkit php runTest.php infra-php
./bin/testkit run --rm -e TEST_MATCH=superadmin testkit php runTest.php infra-php
```

Config recomendada del host:

```env
TK_INFRA_PHP_TEST_ROOTS=test/infra
TK_INFRA_PHP_TEST_PATTERNS=*.test.php
TK_INFRA_PHP_TEST_EXCLUDE_ROOTS=
TK_INFRA_PHP_TEST_EXCLUDE_PATTERNS=*/vendor/*,*/node_modules/*,*/_out/*,*/.testkit/*,*/testkit/*
```

La suite no ejecuta bootstrap estructural de store por defecto. Si un test infra
necesita DB, servidor HTTP o Docker levantado, esa precondición debe declararse
en el test o en la documentación del proyecto.

## Selección múltiple de tests

Precedencia:

1. `TEST_MATCH_FILE`
2. `TEST_MATCH_LIST`
3. `TEST_MATCH`
4. sin filtro explícito

`TEST_MATCH` mantiene el comportamiento legacy por substring. `TEST_MATCH_FILE` y `TEST_MATCH_LIST` hacen match exacto por defecto contra paths repo-relative. Para substring explícito:

```bash
-e TEST_MATCH_LIST_MODE=substring
```

`TEST_SELECTION_MATCH_MODE` se conserva como alias compatible, pero la configuración nueva debería usar `TEST_MATCH_LIST_MODE`.

Archivo de selección:

```bash
mkdir -p .testkit
cat > .testkit/selection.front_php.txt <<'EOF'
test/front/cliente/unit/cliente_api_wiring.test.php
test/front/cliente/integration/cliente_mi_cargador_tarifa_domestica_seeded_integration.test.php
test/front/cliente/unit/cliente_domestic_asset_endpoint_contract.test.php
test/front/cliente/unit/cliente_mi_cargador_modal_contract.test.php
EOF

./bin/testkit run --rm   -e TEST_MATCH_FILE='.testkit/selection.front_php.txt'   testkit php runTest.php front-php
```

Reglas de `TEST_MATCH_FILE`:

- una ruta repo-relative por línea;
- líneas vacías y comentarios `#` se ignoran;
- `\` se normaliza a `/`;
- `..` y rutas absolutas se rechazan;
- archivo inexistente o ilegible falla explícitamente;
- entradas válidas sin match quedan en `selection_unmatched_entries`.

Lista por coma:

```bash
./bin/testkit run --rm   -e TEST_MATCH_LIST='test/front/cliente/unit/cliente_api_wiring.test.php,test/front/cliente/unit/cliente_mi_cargador_modal_contract.test.php'   testkit php runTest.php front-php
```

PowerShell:

```powershell
$env:TEST_MATCH_FILE = '.testkit/selection.front_php.txt'
.in	estkit.ps1 run --rm testkit php runTest.php front-php
Remove-Item Env:\TEST_MATCH_FILE
```

Metadata relevante del reporte:

- `selection_source`
- `selection_match_mode`
- `selection_entries_count`
- `selection_entries`
- `selection_unmatched_entries`
- `selection_invalid_entries`
- `selection_errors`
- `selection_file`
- `selection_file_exists`
- `selected_test_files`

## Rerun aislado de fallidos

```bash
./bin/testkit run --rm \
  -e TEST_MATCH_FILE='.testkit/selection.front_php.txt' \
  -e TEST_RERUN_FAILED_ISOLATED=1 \
  -e TEST_COVERAGE=0 \
  testkit php runTest.php front-php
```

Si la corrida batch pasa, no hay rerun. Si falla, `testkit` reejecuta solo los archivos fallidos, uno por uno, con `TEST_JOBS=1`, define `TEST_ISOLATED_RERUN_ACTIVE=1` para impedir recursión y agrega `isolated_rerun` al reporte.

- `confirmed_failure`: el fallo también ocurre aislado.
- `interference_suspected`: el batch falló, pero el archivo pasó aislado.
- `inconclusive`: el rerun aislado no produjo evidencia suficiente, por ejemplo `skip` o `no_tests`.

`isolated_rerun` no cambia el exit code:

```json
{
  "isolated_rerun": {
    "affects_exit_code": false
  }
}
```

Aunque todos los fallidos pasen aislados, el exit code del batch fallido sigue siendo `1` por defecto. La evidencia se usa para triage, no para ocultar flakiness o interferencia.

Coverage durante el rerun aislado:

```json
{
  "isolated_rerun": {
    "coverage_policy": "disabled_for_isolated_rerun"
  }
}
```

El rerun aislado fuerza `TEST_COVERAGE=0` para no mezclar artefactos de coverage con la corrida principal.

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

Cuando coverage está activo, cada directorio contiene `coverage_meta.json`. Ese archivo vincula los artefactos con `suite_id`, `run_id` y `report_root`.

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

El bloque `Coverage diagnostics` lista conteos y, hasta `TEST_COVERAGE_SUMMARY_TOP`, archivos concretos de `critical_missing` y `critical_low`. Si corrés la misma suite sin coverage después de una corrida con coverage, `report.php` no reutiliza los archivos anteriores como evidencia actual: muestra `not generated for this run` o marca el directorio como `stale`/`legacy/stale`.

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
