# testkit

`testkit` is a shared testing platform for projects that adopt its contract.

It centralizes runners, discovery, store lifecycle and report formats. It does not ship domain tests and it does not decide business rules for the host project.

## Start here

Read by question, not by file order:

| Question | Document |
|---|---|
| What does a project need to adopt `testkit`? What does `testkit` own? What is out of scope? | [`docs/CONTRATO.md`](docs/CONTRATO.md) |
| How do I run it safely for the first time? Which commands are normal? | [`docs/USO.md`](docs/USO.md) |
| Setup failed. Which command should I run next? | [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md) |
| How is execution wired internally? How do bootstrap, baseline and locks fit together? | [`docs/ARQUITECTURA.md`](docs/ARQUITECTURA.md) |
| Which report fields are stable? What is heuristic? How should coverage and observability be read? | [`docs/REPORTING_COVERAGE.md`](docs/REPORTING_COVERAGE.md) |

Suggested reading order for a new adopter:

1. `CONTRATO.md`
2. `USO.md`
3. `TROUBLESHOOTING.md`
4. `ARQUITECTURA.md`
5. `REPORTING_COVERAGE.md`

## Quick start

Use one concrete suite first. Do not start with `all`.

### Linux/macOS

```bash
export TESTKIT_PROJECT_ROOT=/path/to/project

./bin/testkit doctor
./bin/testkit up -d
./bin/testkit run --rm testkit php runTest.php back-php
./bin/testkit inspect latest
```

### PowerShell

```powershell
$env:TESTKIT_PROJECT_ROOT = 'D:\Proyecto'

.\bin\testkit.ps1 doctor
.\bin\testkit.ps1 up -d
.\bin\testkit.ps1 run --rm testkit php runTest.php back-php
.\bin\testkit.ps1 inspect latest
```

## Execution observability

Long suites now expose operator-facing progress and summarized execution metrics as part of the framework contract.

What exists:

- periodic `[Progress]` heartbeats during execution
- `[WARN] long_running_test` for tests that cross the configured threshold
- `[Phase Timings]` at the end of the suite
- summarized observability fields persisted in suite JSON and local history

What does **not** exist here:

- per-heartbeat persistence
- capability doctor decisions
- automatic diagnosis of business tests

The detailed contract lives in:

- [`docs/USO.md`](docs/USO.md) for operator reading
- [`docs/REPORTING_COVERAGE.md`](docs/REPORTING_COVERAGE.md) for stable JSON/report semantics

## Current support limits

This repository does not try to hide its current limits. The detailed contract lives in [`docs/CONTRATO.md`](docs/CONTRATO.md). In particular:

- the main closed path is MySQL
- `TEST_DB_STRATEGY=clean` is rejected
- `per_worker` isolates workers inside one suite; it does not make concurrent top-level runs safe
- `migration-contract` is a narrow technical suite, not a general functional suite
- fragility hints, failure families and similar triage signals are heuristics, not source of truth

## Artifact ownership

Framework-generated operational artifacts are written inside the host project repository, mainly under `.testkit/`.

That output belongs to the host project because it describes the project under test, not `testkit` itself.
