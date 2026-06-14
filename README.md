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

`doctor` now has two operator-facing modes over the same underlying checks.

- `--full`: full narrative output. Prints base checks, capability checks and explicit status per rule.
- `--compact`: compressed operator summary. Keeps status totals and only surfaces relevant warnings, unknowns and failures.

Default behavior:

- if no mode is provided, `doctor` uses `full`
- `TESTKIT_DOCTOR_MODE=compact|full` can provide a default
- explicit CLI flags override the env default

Examples:

```bash
./bin/testkit doctor --compact
./bin/testkit doctor --full
./bin/testkit doctor --compact migration-contract
./bin/testkit doctor --dump --full migration-contract
```

```powershell
.\bin\testkit.ps1 doctor --compact
.\bin\testkit.ps1 doctor --full
.\bin\testkit.ps1 doctor --compact migration-contract
.\bin\testkit.ps1 doctor --dump --full migration-contract
```

Reading guidance:

- `full` is the better default for framework debugging and wrapper work
- `compact` is better for repeated operator runs or CI logs where you want lower noise
- `Capability doctor: PASS` still does **not** prove runtime safety; it only reports visible config alignment
- `Doctor: FAIL` still follows the base doctor status, not the capability advisory status

## Support matrix summary

Do not infer support from service names in compose or from partial adapters. The current closed path is deliberately narrow.

| Component | Status | Public contract |
|---|---|---|
| MySQL | closed / primary | provision, reset, layered baseline, snapshot restore, per-worker clone |
| PostgreSQL | partial | runtime/provision/reset only where explicitly implemented; no closed snapshot/clone contract |
| Redis | auxiliary | service only; no structural store lifecycle in core PHP |
| Influx | auxiliary / profiling | profiling/reporting service; not a primary store driver |
| `TEST_DB_STRATEGY=clean` | rejected | recognized as not implemented; use `shared` or `per_worker` |
| `TEST_DB_STRATEGY=per_worker` | intra-suite only | isolates workers inside one suite; does not make concurrent top-level runners safe |

Full detail: [`SUPPORT_MATRIX.md`](SUPPORT_MATRIX.md) and [`docs/CONTRATO.md`](docs/CONTRATO.md).

## PowerShell note

This doctor update does **not** change the runtime command contract outside doctor itself.

For wrapper-safe container env injection, keep using explicit `-e` flags on `run`, for example:

```powershell
.\bin\testkit.ps1 run --rm -e TEST_MATCH=alerta testkit php runTest.php back-php
```

Do not assume that doctor-mode work also changes unrelated runtime parsing semantics.

## Execution observability

Long suites now expose operator-facing progress and summarized execution metrics as part of the framework contract.

What exists:

- periodic `[Progress]` heartbeats during execution
- `[WARN] long_running_test` for tests that cross the configured threshold
- `[Phase Timings]` at the end of the suite
- operator-first failed suite summaries that surface status, focus and next action near the top of the report
- summarized observability fields persisted in suite JSON and local history
- a config-visible capability section in `doctor` for generic store constraints and the closed `migration-contract` path
- dual `doctor` render modes: `full` and `compact`
- structured capability fields in `doctor --dump` (`TESTKIT_CAPABILITY_CHECK_<n>_*`)

What does **not** exist here:

- per-heartbeat persistence
- proof that a runtime path is safe just because `doctor` emitted `PASS`
- automatic diagnosis of business tests
- closed PostgreSQL snapshot/clone parity with MySQL
- Redis or Influx lifecycle as structural store drivers

The detailed contract lives in:

- [`docs/USO.md`](docs/USO.md) for operator reading
- [`docs/REPORTING_COVERAGE.md`](docs/REPORTING_COVERAGE.md) for stable JSON/report semantics

## Current support limits

This repository does not try to hide its current limits. The detailed contract lives in [`docs/CONTRATO.md`](docs/CONTRATO.md). In particular:

- the main closed path is MySQL
- PostgreSQL is partial and must not be described as MySQL-equivalent
- Redis is auxiliary service infrastructure, not a core structured store lifecycle
- Influx is auxiliary/profiling infrastructure, not a primary store driver
- `TEST_DB_STRATEGY=clean` is rejected
- `per_worker` isolates workers inside one suite; it does not make concurrent top-level runs safe
- `migration-contract` is a narrow technical suite, not a general functional suite
- capability checks in `doctor` are config-visible only and do not replace a real run
- `compact` changes rendering density, not the underlying doctor semantics
- fragility hints, failure families and similar triage signals are heuristics, not source of truth

## Artifact ownership

Framework-generated operational artifacts are written inside the host project repository, mainly under `.testkit/`.

That output belongs to the host project because it describes the project under test, not `testkit` itself.
