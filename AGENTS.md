# AGENTS.md

This repository exposes a real agent mode.

## Working directory

Run from the `testkit/` repository root.

## Required environment

Set the host project root before any command:

```bash
export TESTKIT_PROJECT_ROOT=/absolute/path/to/host-project
```

The host project must provide a test env file at one of these paths:

- `<project>/test/.env.test`
- `<project>/.env.test`

## Canonical commands

Validate the wrapper and project contract:

```bash
./bin/testkit doctor
```

Start services:

```bash
./bin/testkit up -d
```

Run one concrete suite in real agent mode:

```bash
TESTKIT_MODE=agent ./bin/testkit run --rm testkit php runTest.php back-php
```

Common suite targets:

```bash
TESTKIT_MODE=agent ./bin/testkit run --rm testkit php runTest.php back-php
TESTKIT_MODE=agent ./bin/testkit run --rm testkit php runTest.php front-php
TESTKIT_MODE=agent ./bin/testkit run --rm testkit php runTest.php front-js
```

## Agent mode contract

`TESTKIT_MODE=agent` enforces a deterministic first-pass execution profile:

- `TEST_JOBS=1`
- `TEST_DB_STRATEGY=shared`
- `TEST_FAIL_FAST=0`
- `TEST_CHILD_FAIL_FAST=0`
- `TEST_META_FAIL_FAST=0`
- `TESTKIT_PROGRESS_MODE=quiet`
- `NO_COLOR=1`

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

## Canonical artifacts

Read persisted artifacts from the host project repository:

- `<project>/.testkit/reports/`
- `<project>/.testkit/reports/runs/<run_id>/`
- `<project>/.testkit/reports/latest_run.json`

When agent mode is enabled, the run also records an agent artifact under:

- `<project>/.testkit/reports/runs/<run_id>/agent_runs/agent_run_execute_latest.json`

## Rules

- Start with one concrete suite, not `all`.
- Consume persisted JSON and `--json` interfaces.
- Do not parse console progress as the automation contract.
- Do not assume legacy JSON fallback. `inspect` and `agent-run` require canonical reports.
