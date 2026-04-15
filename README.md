# testkit

`testkit` is an opinionated shared testing platform for projects that adopt its contract.

It provides reusable runners, discovery, store bootstrap and reports. It does not ship domain tests and it does not decide business rules for the host project.

## What “shared testing platform” means here

In this repository, “shared testing platform” means:

- the same execution layer can be reused by more than one project
- suite selection, discovery, bootstrap and report formats are centralized in `testkit`
- the host project still owns its tests, seed SQL, fixtures, helpers and domain assertions

It is shared infrastructure, not a shared test suite.

## Quick start

Linux/macOS:

```bash
export TESTKIT_PROJECT_ROOT=/path/to/project
./bin/testkit doctor
./bin/testkit up -d
./bin/testkit run --rm testkit php runTest.php
```

PowerShell:

```powershell
$env:TESTKIT_PROJECT_ROOT = 'D:\Proyecto'
.\bin\testkit.ps1 doctor
.\bin\testkit.ps1 up -d
.\bin\testkit.ps1 run --rm testkit php runTest.php
```

## Main documentation

- [`docs/CONTRATO.md`](docs/CONTRATO.md)
- [`docs/USO.md`](docs/USO.md)
- [`docs/ARQUITECTURA.md`](docs/ARQUITECTURA.md)
- [`docs/REPORTING_COVERAGE.md`](docs/REPORTING_COVERAGE.md)

## Current support limits

- The main closed path is MySQL.
- `TEST_DB_STRATEGY=clean` is rejected. It is not an operational mode today.
- `per_worker` isolates workers inside one suite. It does not make concurrent top-level runs safe.
- `migration-contract` is a narrow technical suite. It requires:
  - `TEST_BASELINE_MODE=snapshot`
  - a snapshot source resolvable from env
  - `TEST_DB_STRATEGY=shared`
  - MySQL
- Fragility hints and failure families are heuristics. They help triage; they are not a source of truth.

## Artifact ownership

Framework-generated operational artifacts are written inside the host project repository, mainly under `.testkit/`.

That output belongs to the host project because it describes the project under test, not `testkit` itself.
