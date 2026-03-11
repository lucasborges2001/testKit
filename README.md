# testkit

`testkit` is the shared testing platform for this repository.

It does not contain domain tests. It provides reusable runners, conventions, diagnostics and reports so each module (for example `ocpp_server`) can test without rebuilding infra.

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
- Useful reporting:
  - suite summary
  - module summary
  - grouped failures
  - slow tests
  - fragility hints (history based)
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

# filters
TEST_SCOPE=integration TEST_MATCH=ocpp php runTest.php back
TEST_CATEGORY=critical php runTest.php all
```

## Outputs

- `testkit/_out/reports/*_latest.json`
- `testkit/_out/history/*.json`
- `testkit/_out/coverage/*`

Human report:

```bash
php scripts/report.php
```

DB profile report (if enabled):

```bash
php scripts/query_report.php
```

## Documentation

- [`docs/USO.md`](docs/USO.md)
- [`docs/ARQUITECTURA.md`](docs/ARQUITECTURA.md)
- [`docs/REPORTING_COVERAGE.md`](docs/REPORTING_COVERAGE.md)

## Contract with project

Expected project layout:

- `<project>/test/back/**`
- `<project>/test/front/**` (optional)
- `<project>/test/.env.test` (preferred) or `<project>/.env.test`

The platform is intentionally generic and module-agnostic.
