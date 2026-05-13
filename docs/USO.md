# Uso operativo de testkit

## Novedades de este corte

- `php runTest.php --help` muestra ayuda breve del runner.
- `php runTest.php <target> --list` es una ruta explícita soportada para listar selección sin ejecutar tests.
- `php runTest.php reference-contract` ejecuta el contrato técnico de referencias PHP (`require`, `require_once`, `include`, `include_once`).
- `./bin/testkit inspect config-schema --json` expone el esquema soportado de configuración.
- Warnings por env inválido deben quedar visibles en consola y en los reportes persistidos.
- `inspect config-schema --json` incluye la matriz honesta de soporte por motor/servicio.

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

## Reglas operativas nuevas

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

### Segunda pasada de endurecimiento

La suite queda acotada deliberadamente a includes PHP estáticos:

- soportado: `require`, `require_once`, `include`, `include_once`
- no soportado en esta suite: JS imports, CSS, HTML assets, Markdown links, rutas HTTP
- no escanea todo el repo salvo pedido explícito con `TESTKIT_REFERENCE_ROOT=.`

El reporte estable se publica bajo:

```text
.testkit/reports/reference_contract/reference_contract_latest.json
```

Además del latest canónico, el writer global puede generar archivos timestamped y entradas en `runs_index.json` siguiendo el patrón normal de `testKit`.

Dinámicos:

```bash
TESTKIT_REFERENCE_DYNAMIC_SEVERITY=warn   # default: warning, no falla si no hay rotos
TESTKIT_REFERENCE_DYNAMIC_SEVERITY=ignore # no emite warning/failure
TESTKIT_REFERENCE_DYNAMIC_SEVERITY=error  # falla la suite
```

Límites operativos:

```bash
TESTKIT_REFERENCE_TIMEOUT_SEC=20
TESTKIT_REFERENCE_MAX_FILES=3000
TESTKIT_REFERENCE_MAX_BYTES_PER_FILE=1048576
TESTKIT_REFERENCE_MAX_VIOLATIONS=200
TESTKIT_REFERENCE_IGNORE_DIRS=vendor,node_modules,.git,.testkit,testkit/_out,_out
```

Fallos operativos principales:

| cause_code | Cuándo aparece |
|---|---|
| `reference_root_missing` | root no definido, inexistente o archivo en vez de directorio |
| `reference_root_invalid` | root relativo intenta escapar del repo |
| `reference_scan_timeout` | timeout global alcanzado |
| `reference_max_files_exceeded` | límite de archivos PHP escaneables alcanzado |
| `reference_max_violations_exceeded` | límite de violaciones alcanzado; `truncated=true` |
| `missing_php_include` | include literal apunta a archivo inexistente |
| `dynamic_php_include` | include dinámico con severity `error` |
