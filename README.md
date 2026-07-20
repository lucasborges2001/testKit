# testkit

`testkit` is a shared testing platform for projects that adopt its contract.

It centralizes runners, discovery, store lifecycle and report formats. It does not ship domain tests and it does not decide business rules for the host project.

## Start here

Read by question, not by file order:

| Question | Document |
|---|---|
| What does a project need to adopt `testkit`? What does `testkit` own? What is out of scope? | [`docs/CONTRATO.md`](docs/CONTRATO.md) |
| What engines/services are actually supported right now? | [`SUPPORT_MATRIX.md`](SUPPORT_MATRIX.md) |
| How do I run it safely for the first time? Which commands are normal? | [`docs/USO.md`](docs/USO.md) |
| How do I prune generated reports, coverage and profiling artifacts? | [`docs/CLEANUP.md`](docs/CLEANUP.md) |
| Setup failed. Which command should I run next? | [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md) |
| How do I run this from Windows/PowerShell? | [`docs/WINDOWS.md`](docs/WINDOWS.md) |
| How is execution wired internally? How do bootstrap, baseline and locks fit together? | [`docs/ARQUITECTURA.md`](docs/ARQUITECTURA.md) |
| Which report fields are stable? What is heuristic? How should coverage and observability be read? | [`docs/REPORTING_COVERAGE.md`](docs/REPORTING_COVERAGE.md) |

Suggested reading order for a new adopter:

1. `CONTRATO.md`
2. `SUPPORT_MATRIX.md`
3. `USO.md`
4. `CLEANUP.md`
5. `TROUBLESHOOTING.md`
6. `ARQUITECTURA.md`
7. `REPORTING_COVERAGE.md`

## Quick start

Use one concrete suite first. Do not start with `all`.

### Linux/macOS

```bash
export TESTKIT_PROJECT_ROOT=/path/to/project

./bin/testkit doctor --compact
./bin/testkit doctor --full migration-contract
./bin/testkit up -d
./bin/testkit run --rm testkit php runTest.php back-php
./bin/testkit inspect latest
```

### PowerShell

```powershell
$env:TESTKIT_PROJECT_ROOT = 'D:\Proyecto'

.\bin\testkit.ps1 doctor --compact
.\bin\testkit.ps1 doctor --full migration-contract
.\bin\testkit.ps1 up -d
.\bin\testkit.ps1 run --rm testkit php runTest.php back-php
.\bin\testkit.ps1 inspect latest
```

## Coverage quick start

Coverage is an operational artifact and is written under `.testkit` by default.

```bash
./bin/testkit run --rm \
  -e TEST_COVERAGE=1 \
  -e TEST_COVERAGE_FORMAT=both \
  -e TEST_COVERAGE_SOURCE_DIRS='back,public_html' \
  testkit php runTest.php back-php
```

Default coverage paths:

```text
.testkit/coverage/back_php
.testkit/coverage/front_php
.testkit/coverage/back_python
```

Each coverage directory also carries `coverage_meta.json` when coverage is generated. The metadata records `suite_id`, `run_id`, `report_root`, format and filter settings so later reports can distinguish current coverage from stale files left by an older run.

Use `TEST_COVERAGE_ROOT` to override the coverage root:

```bash
-e TEST_COVERAGE_ROOT=/tmp/cov
```

That produces:

```text
/tmp/cov/back_php
```

`TEST_COVERAGE_DIR` is still accepted as a legacy alias for the root. Its historical behavior is preserved: the suite id is appended to it. New configuration should prefer `TEST_COVERAGE_ROOT`.

Common coverage env:

| Env | Purpose |
|---|---|
| `TEST_COVERAGE=1` | Enables coverage where supported. |
| `TEST_COVERAGE_FORMAT=lcov\|json\|both` | Controls generated coverage formats. |
| `TEST_COVERAGE_ROOT` | Canonical coverage root. Final dir is `<root>/<suite_id>`. |
| `TEST_COVERAGE_DIR` | Legacy root alias; suite id is still appended. |
| `TEST_COVERAGE_SOURCE_DIRS` | Includes only these repo-relative source dirs in calculations. |
| `TEST_COVERAGE_EXCLUDE_DIRS` | Central exclude policy; default `test,testkit,docker,vendor,logs,storage`. |
| `TEST_COVERAGE_CRITICAL_FILES` | Comma-separated `fnmatch` patterns for critical files. |
| `TEST_COVERAGE_CRITICAL_THRESHOLD` | Threshold for `critical_low`; default `85`. |
| `TEST_COVERAGE_SUMMARY_TOP` | Max files shown by `scripts/report.php`; default `10`. |

The executive report reads diagnostics only when they are attached to the latest suite run through `coverage_meta.json` or through the suite report itself. If a later run did not enable coverage, old files under `.testkit/coverage/<suite_id>` are reported as stale instead of being shown as current evidence. Legacy `test/coverage/*` paths remain supported, but are marked as legacy/current only with compatible metadata; otherwise they are shown as legacy/stale.

## Cleanup generated artifacts

Framework-generated operational artifacts are written inside the host project, mainly under `.testkit/`. Long-running development cycles can produce many report run directories, profiling shards and coverage files.

Use `cleanup` to inspect and prune those generated artifacts without touching databases, Docker volumes, seeds, source tests or env files.

Dry-run is the default safety mode:

```bash
./bin/testkit cleanup reports --max-runs=10 --dry-run
```

Deletion requires `--apply`:

```bash
./bin/testkit cleanup reports --max-runs=10 --apply
```

PowerShell:

```powershell
.\bin\testkit.ps1 cleanup reports --max-runs=10 --dry-run
.\bin\testkit.ps1 cleanup reports --max-runs=10 --apply
```

Retention rules:

- `--keep-runs=N` preserves at least the newest N runs.
- `--keep-days=N` preserves runs newer than N days.
- `--max-runs=N` is a hard cap and removes older run directories beyond N even when they are still inside `--keep-days`.
- `*_latest.json` and `latest_run.json` are preserved.

Detailed contract: [`docs/CLEANUP.md`](docs/CLEANUP.md).

## Doctor modes

`doctor` has two operator-facing modes over the same underlying checks.

- `--full`: full narrative output. Prints base checks, capability checks and explicit status per rule.
- `--compact`: compressed operator summary. Keeps status totals and only surfaces relevant warnings, unknowns and failures.

Default behavior:

- if no mode is provided, `doctor` uses `full`
- `TESTKIT_DOCTOR_MODE=compact|full` can provide a default
- explicit CLI flags override the env default

## Support matrix summary

Do not infer support from service names in compose or from partial adapters. The current closed path is deliberately narrow.

| Component | Status | Public contract |
|---|---|---|
| MySQL | closed / primary | provision, reset, layered baseline, snapshot restore, per-worker clone |
| PostgreSQL | partial | runtime/provision/reset only where explicitly implemented; no closed snapshot/clone contract |
| No store (`TEST_STORE_DRIVER=none`) | supported no-store | no DB credentials, no structural bootstrap, empty stack by default |
| Redis | auxiliary | service only; no structural store lifecycle in core PHP |
| Influx | auxiliary / profiling | profiling/reporting service; not a primary store driver |
| `TEST_DB_STRATEGY=clean` | rejected | recognized as not implemented; use `shared` or `per_worker` |
| `TEST_DB_STRATEGY=per_worker` | intra-suite only | isolates workers inside one suite; does not make concurrent top-level runners safe |

Full detail: [`SUPPORT_MATRIX.md`](SUPPORT_MATRIX.md) and [`docs/CONTRATO.md`](docs/CONTRATO.md).

## Proyectos sin store

Para proyectos que no tienen store runtime ni DB de negocio, declaralo en el env de tests:

```env
TEST_STORE_DRIVER=none
TEST_STORE_PROVISION=external
```

Con ese contrato testkit no exige credenciales MySQL, no arranca `mysql`/`redis` por default cuando `TESTKIT_STACK` no fue declarado, y `runTest.php` puede listar o ejecutar suites sin bootstrap estructural de store. Si el proyecto declara `TESTKIT_STACK` explícitamente, esa decisión se respeta.

## Artifact ownership

Framework-generated operational artifacts are written inside the host project repository, mainly under `.testkit/`.

That output belongs to the host project because it describes the project under test, not `testkit` itself.
