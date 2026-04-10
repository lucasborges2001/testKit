# testkit

`testkit` is an opinionated testing platform designed to provide a unified environment and execution engine for this repository.

It does not contain domain tests. It provides reusable runners, conventions, diagnostics and reports so each module (for example `ocpp_server`) can test without rebuilding infra.

While it aims for reusability, it depends on specific layout conventions and standard suites (PHP/Python/JS) to function effectively.

## What it provides

- Unified execution for:
  - `unit`
  - `integration`
  - `smoke`
  - `perf`
  - `stress`
- Suite runners:
  - `back_php`
  - `back_python`
  - `front_php`
  - `front_js`
  - `migration_contract`
- Useful reporting:
  - suite summary (`suite_status`, `no_tests_reason`)
  - module summary
  - grouped failures
  - slow tests
  - fragility hints (history based)
  - runner capabilities by suite
- Store lifecycle:
  - env resolution
  - DB/store naming per worker
  - baseline materialization (`layered` o `snapshot`)
  - structural seed orchestration (`schema -> base -> migrations -> validations`)
  - optional clone-from-baseline per worker
  - operational entrypoints (`db_reset`, `seed`, `test`)
- Coverage as diagnostics (not KPI only):
  - lcov/json output
  - low coverage zones by file/module
  - critical files without coverage (configurable)

## Quick start

Linux/macOS (Docker):

```bash
export TESTKIT_PROJECT_ROOT=/path/to/project
./bin/testkit doctor
./bin/testkit up -d
./bin/testkit run --rm testkit php runTest.php
```

PowerShell (Docker):

```powershell
$env:TESTKIT_PROJECT_ROOT = 'D:\Proyecto'
.\bin\testkit.ps1 doctor
.\bin\testkit.ps1 up -d
.\bin\testkit.ps1 run --rm testkit php runTest.php
```

## Common targets

```bash
# full
php runTest.php

# suites
php runTest.php back
php runTest.php back-php
php runTest.php back-py
php runTest.php front
php runTest.php front-php
php runTest.php front-js

# categories
php runTest.php smoke
php runTest.php perf
php runTest.php stress

# baseline / migraciones
TEST_BASELINE_MODE=snapshot php runTest.php migration-contract

# filters
TEST_SCOPE=integration TEST_MATCH=ocpp php runTest.php back
TEST_CATEGORY=critical php runTest.php all
```

## Outputs

- `test/reports/*_latest.json` or `test/<side>/<module>/report/*_latest.json`
- `test/history/*.json`
- `test/coverage/*`
- `test/querylog.jsonl` (si el profiling de DB está activo)

Human report:

```bash
php scripts/report.php
```

DB profile report (if enabled):

```bash
php scripts/query_report.php
```

## Reporting contract

Each suite report now exposes, at minimum:

- `report_contract_version`
- `suite_status` (`passed|failed|all_skipped|no_tests|listed`)
- `no_tests_reason` when selection is empty
- `runner_capabilities` so consumers can distinguish what each suite actually supports

`front_js` consumes the same discovered selection produced by the shared PHP discovery layer, so scope/category/match resolution and module-scoped report roots stay aligned across suites.

## Documentation

- [`docs/USO.md`](docs/USO.md)
- [`docs/ARQUITECTURA.md`](docs/ARQUITECTURA.md)
- [`docs/REPORTING_COVERAGE.md`](docs/REPORTING_COVERAGE.md)

## Contract with project

To adopt `testkit`, a project should align with the following expectations:

### Contractual (Strict Requirements)
- **Root `test/` folder**: All test-related logic must reside here.
- **Environment file**: A `.env.test` file is required (either in `<project>/test/` or at the root).
- **Test Discovery suffixes**: Files must follow standard naming (e.g., `_unit.php`, `.test.py`) or be located in directories named after their scope (e.g., `/integration/`).

### Conventions (Configurable Defaults)
- **Source folders**: Defaults to `back/` and `public_html/` (configurable via `TK_BACK_DIR` and `TK_PUBLIC_DIR`).
- **Suite roots**:
  - `TK_BACK_PHP_DIR`: Root for PHP back tests (default: `test/back`).
  - `TK_BACK_PYTHON_DIR`: Root for Python tests (default: `test/back`).
  - `TK_FRONT_PHP_DIR`: Root for PHP front tests (default: `test/front`).
  - `TK_FRONT_JS_DIR`: Root for JS front tests (default: `test/front`).
- **Discovery & Tagging**:
  - `TK_MODULE_LEVEL`: Number of path segments to use for module name (default: `2`).
  - `TK_TAG_MAP`: Extend path-based tagging (format: `tag:token1,token2;tag2:token3`).
- **Aggregate Targets Overrides**: Allows customizing which suites run for a given target.
  - `TESTKIT_TARGET_ALL`, `TESTKIT_TARGET_BACK`, `TESTKIT_TARGET_FRONT`, etc.
  - Format: comma-separated suite names (`back_php,back_python,front_php,front_js`).
- **Seed layout**: Database initialization follows a layered structure in `test/seeds/<driver>/`.
- **Snapshot baseline**: Si el proyecto quiere validar migraciones contra una base restaurada, debe proveer un artefacto lógico `.sql` o `.sql.gz` accesible desde el contenedor y declarar `TEST_BASELINE_MODE=snapshot`.

Ownership:
`testkit` is an execution platform and environment manager. It does not own the results; all test outputs, history, and coverage data are stored within the host project's `test/` directory to ensure they belong to the project being tested.

For layered seeds, `testkit` owns lifecycle and orchestration. Project support should build scenarios with builders/helpers after the structural base exists; it should not redefine reset policy or the seed pipeline.


## Baseline modes

`testkit` now supports two baseline inputs for store bootstrap:

- `TEST_BASELINE_MODE=layered` keeps the classic `schema -> base -> migrations -> validations` flow.
- `TEST_BASELINE_MODE=snapshot` restores a `.sql`/`.sql.gz` artifact and then applies requested migrations/validations.

For reusable baselines you can enable:

- `TEST_BASELINE_REUSE=1`
- `TEST_BASELINE_CLONE_PER_WORKER=1`
- `TEST_BASELINE_INVALIDATE=1` when you need to force a rebuild.


## Migration contract target

`migration-contract` agrega una suite dedicada para validar el baseline restaurado y las migraciones estructurales sin depender de tests de dominio.

Uso recomendado:

```bash
TEST_BASELINE_MODE=snapshot \
TEST_BASELINE_SNAPSHOT_FILE=/workspace/project/test/seeds/mysql/baseline/latest.sql.gz \
TEST_DB_STRATEGY=shared \
php runTest.php migration-contract
```

Restricciones intencionales:

- requiere `TEST_BASELINE_MODE=snapshot`
- falla si intentás correrlo con `TEST_DB_STRATEGY=per_worker`
- valida bootstrap + manifest; no reemplaza suites funcionales del proyecto


## BackupKit-aware snapshot baseline

Además del path directo al dump, `testkit` puede resolver el baseline snapshot desde metadata o reportes JSON generados por `backupkit`.

Variables nuevas:

- `TEST_BASELINE_BACKUPKIT_METADATA_JSON`
- `TEST_BASELINE_BACKUPKIT_REPORT_JSON`
- `TEST_BASELINE_REQUIRE_BACKUPKIT_SUCCESS`


## Migration state

`testkit` ahora puede resolver migraciones pendientes de forma incremental para `migration-contract`:

- estado explícito (`TEST_MIGRATION_APPLIED`)
- tabla de control (`TEST_MIGRATION_STATE_TABLE`)
- markers por migración (`state.json`)

El objetivo no es adivinar mágicamente el upgrade; es volver explícito cómo se detecta el punto de partida antes de aplicar pendientes.
