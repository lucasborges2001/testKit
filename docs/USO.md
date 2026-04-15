# Uso de testkit

## 1) Alcance de esta guía

Esta guía cubre la operación normal de `testkit`.

No define ownership ni límites contractuales profundos. Para eso, leer primero [`CONTRATO.md`](CONTRATO.md).

## 2) Flujo mínimo recomendado

1. Definir `TESTKIT_PROJECT_ROOT`.
2. Crear `test/.env.test` en el proyecto.
3. Correr `doctor`.
4. Levantar el stack requerido.
5. Ejecutar un target.
6. Leer reportes y artefactos.

Linux/macOS:

```bash
export TESTKIT_PROJECT_ROOT=/ruta/proyecto
./bin/testkit doctor
./bin/testkit up -d
./bin/testkit run --rm testkit php runTest.php
```

PowerShell:

```powershell
$env:TESTKIT_PROJECT_ROOT='D:\Proyecto'
.\bin\testkit.ps1 doctor
.\bin\testkit.ps1 up -d
.\bin\testkit.ps1 run --rm testkit php runTest.php
```

## 3) Doctor

`doctor` verifica el contrato operativo mínimo antes de correr:

- `TESTKIT_PROJECT_ROOT` y `TESTKIT_ROOT`
- existencia del env de tests dentro del repo montado
- presencia de `docker`
- contrato mínimo del store para MySQL según `TEST_STORE_PROVISION`

Regla importante:

- `TEST_STORE_PROVISION=managed` exige credenciales runtime y admin
- `TEST_STORE_PROVISION=external` exige solo credenciales runtime

## 4) Targets comunes

Corrida completa:

```bash
php runTest.php
```

Suites:

```bash
php runTest.php back
php runTest.php back-php
php runTest.php back-py
php runTest.php front
php runTest.php front-php
php runTest.php front-js
php runTest.php migration-contract
```

Filtros:

```bash
TEST_SCOPE=integration TEST_MATCH=auth php runTest.php back
TEST_CATEGORY=critical php runTest.php all
```

## 5) Variables operativas que importan de verdad

Selección:

- `TEST_SCOPE`
- `TEST_CATEGORY`
- `TEST_MATCH`

Ejecución:

- `TEST_JOBS`
- `TEST_FAIL_FAST`
- `TEST_META_FAIL_FAST`
- `TEST_CHILD_FAIL_FAST`

Store/bootstrap:

- `TEST_STORE_PROVISION`
- `TEST_DB_STRATEGY`
- `TEST_DB_WORKER_SUFFIX_FORMAT`
- `TEST_BASELINE_MODE`

Reporting:

- `TEST_REPORT_KEEP`
- `TEST_RUNS_INDEX_KEEP`

No hace falta setear todo. El punto es entender cuáles cambian realmente el modo de ejecución.

## 6) Store y baseline

### 6.1) layered

Es el modo por defecto.

Pipeline estructural:

1. reset
2. `schema/`
3. `base/`
4. migraciones explícitas si fueron pedidas
5. `validations/`

### 6.2) snapshot

Usa un dump lógico como punto de partida y luego aplica migraciones/validaciones.

Requiere una fuente de snapshot resoluble por env.

Ejemplo:

```bash
TEST_BASELINE_MODE=snapshot \
TEST_BASELINE_SNAPSHOT_FILE=/workspace/project/test/seeds/mysql/baseline/latest.sql.gz \
php runTest.php back-php
```

### 6.3) migration-contract

Uso correcto:

```bash
TEST_BASELINE_MODE=snapshot \
TEST_BASELINE_SNAPSHOT_FILE=/workspace/project/test/seeds/mysql/baseline/latest.sql.gz \
TEST_DB_STRATEGY=shared \
php runTest.php migration-contract
```

No usarlo para:

- reemplazar tests funcionales
- throughput normal
- esconder un pipeline de seeds frágil

## 7) Reportes y artefactos

`testkit` escribe artefactos operativos dentro del repo del proyecto.

Ubicaciones principales:

- `.testkit/reports/`
- `.testkit/history/`
- `.testkit/baselines/`
- `test/coverage/` cuando coverage está activo

Reporte humano:

```bash
php scripts/report.php
```

Reporte de profiling DB, si está habilitado:

```bash
php scripts/query_report.php
```

## 8) Reglas operativas que no conviene violar

### 8.1) Un runner top-level por vez

Modelo recomendado:

- un runner top-level
- paralelismo intra-suite con `TEST_JOBS`

No usar como throughput normal:

```bash
php runTest.php back-php &
php runTest.php front-php &
```

### 8.2) Estrategia de DB

- `shared`: soportado
- `per_worker`: ruta cerrada para paralelismo intra-suite con DB real
- `clean`: no implementado

Ejemplo soportado:

```bash
TEST_JOBS=4 TEST_DB_STRATEGY=per_worker php runTest.php back-php
```

## 9) Límites operativos vigentes

- `per_worker` no aísla corridas top-level distintas.
- snapshot restore y clone-per-worker están cerrados principalmente para MySQL.
- coverage y triage no reemplazan el diagnóstico del proyecto.
- `testkit` no arma escenarios de negocio por vos.

## 10) Qué leer después

- [`CONTRATO.md`](CONTRATO.md) para ownership y límites
- [`ARQUITECTURA.md`](ARQUITECTURA.md) para fronteras internas
- [`REPORTING_COVERAGE.md`](REPORTING_COVERAGE.md) para el detalle de reporting/coverage
