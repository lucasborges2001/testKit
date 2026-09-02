# AGENTS.md

This repository exposes a real agent mode.

## Working directory

Run from the `testkit/` repository root.

## Required environment

Set the host project root before any command:

```bash
export TESTKIT_PROJECT_ROOT=/absolute/path/to/host-project
```

The host project must provide a test env file at one of these paths when the selected TestKit runtime requires one:

- `<project>/test/.env.test`
- `<project>/.env.test`

## Canonical commands

Validate the wrapper and project contract:

```bash
./bin/testkit doctor --suite back-php
```

Start services:

```bash
./bin/testkit up -d
```

Run one concrete native TestKit suite in real agent mode:

```bash
TESTKIT_MODE=agent ./bin/testkit run --rm testkit php runTest.php --suite back-php
```

Common suite selectors:

```bash
TESTKIT_MODE=agent ./bin/testkit run --rm testkit php runTest.php --suite back-php
TESTKIT_MODE=agent ./bin/testkit run --rm testkit php runTest.php --suite front-php
TESTKIT_MODE=agent ./bin/testkit run --rm testkit php runTest.php --suite front-js
```

Groups and categories are explicit and are not inferred from a positional target:

```bash
TESTKIT_MODE=agent ./bin/testkit run --rm testkit php runTest.php --group all
TESTKIT_MODE=agent ./bin/testkit run --rm testkit php runTest.php --category smoke
```

Positional targets, aliases, `TEST_TARGET`, and `TESTKIT_TARGET_*` are not supported.

## Host-owned suite catalogs in agent mode

Hosts that own declarative catalogs through `testkit-suite-config` can execute one concrete host suite through the real TestKit agent bridge:

```bash
export TESTKIT_PROJECT_ROOT=/absolute/path/to/host-project
./bin/testkit-host-agent config/testkit-suites.php runtime_env --json
```

Persistent host suites still require the same explicit admission token:

```bash
./bin/testkit-host-agent \
  config/testkit-suites.php persistent_e2e \
  --allow-persistent \
  --json
```

`testkit-host-agent` does not invent host test logic. It delegates execution and policy validation to the public `testkit-suite-config` entrypoint, then publishes the result into the canonical TestKit report tree under the host project.

The resulting run is immediately consumable by the normal agent and inspector interfaces:

```bash
./bin/testkit run --rm testkit php scripts/agent-run.php --json
./bin/testkit run --rm testkit php scripts/inspect.php latest --json
```

The host remains owner of fixtures, business assertions, cleanup and risk metadata. TestKit owns execution semantics, agent mode, reporting and decision evidence.

## Windows (PowerShell)

Every native TestKit command above has a PowerShell equivalent via `bin/testkit.ps1`. Full
setup, troubleshooting and the supported/unsupported path matrix live in
[`docs/WINDOWS.md`](docs/WINDOWS.md).

```powershell
$env:TESTKIT_PROJECT_ROOT = 'D:\dev\host-project'

.\bin\testkit.ps1 doctor --readonly --suite back-php --compact
$env:TESTKIT_MODE = 'agent'
.\bin\testkit.ps1 run --rm testkit php runTest.php --suite back-php
```

`doctor --readonly` runs every base check without creating `test/` or writing
a probe file — prefer it as the preflight step before an agent decides what
to run.

## Agent mode contract

`TESTKIT_MODE=agent` is the single canonical activation signal.

When active, the runner enforces this deterministic first-pass profile:

- `TEST_JOBS=1`
- `TEST_DB_STRATEGY=shared`
- `TEST_FAIL_FAST=0`
- `TEST_CHILD_FAIL_FAST=0`
- `TEST_META_FAIL_FAST=0`
- `TESTKIT_PROGRESS_MODE=quiet`
- `NO_COLOR=1`

The contract is not the console text. The contract lives in persisted JSON, canonical reports, agent decision JSON and agent artifacts.

## Canonical machine interfaces

After a run exists, prefer JSON interfaces:

```bash
./bin/testkit run --rm testkit php scripts/inspect.php latest --json
./bin/testkit run --rm testkit php scripts/inspect.php failure --json
./bin/testkit run --rm testkit php scripts/inspect.php concurrency --json
./bin/testkit run --rm testkit php scripts/inspect.php seed-state --json
```

Agent decision interface:

```bash
./bin/testkit run --rm testkit php scripts/agent-run.php --json
./bin/testkit run --rm testkit php scripts/agent-run.php execute --json
```

When `agent-run` emits a next command in agent mode, that command keeps `TESTKIT_MODE=agent` explicitly so continuation does not silently fall back to standard mode.

## Canonical artifacts

Read persisted artifacts from the host project repository:

- `<project>/.testkit/reports/`
- `<project>/.testkit/reports/runs/<run_id>/`
- `<project>/.testkit/reports/latest_run.json`

When agent mode is enabled, the run also records an agent artifact under:

- `<project>/.testkit/reports/runs/<run_id>/agent_runs/agent_run_execute_latest.json`

That artifact is a persisted decision/execution envelope. Host-suite agent runs use the same canonical report tree and artifact format.

## Rules

- Start with one concrete suite, not group `all`.
- Declare exactly one of `--suite`, `--group`, or `--category` for native TestKit runs.
- For host catalogs, select exactly one suite key through `testkit-host-agent`.
- Consume persisted JSON and `--json` interfaces.
- Do not parse console progress as the automation contract.
- Do not assume legacy JSON fallback. `inspect` and `agent-run` require canonical reports.
