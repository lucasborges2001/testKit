# testKit reset

`reset` returns the TestKit runtime of a host project to a clean operational state. It is intentionally separate from `cleanup`: `cleanup` applies retention policies to generated artifacts, while `reset` first tears down the TestKit Compose project and then purges disposable runtime evidence.

## Safe reset

Linux/macOS:

```bash
export TESTKIT_PROJECT_ROOT=/path/to/project
./bin/testkit reset
```

PowerShell:

```powershell
$env:TESTKIT_PROJECT_ROOT = 'D:\Proyecto'
.\bin\testkit.ps1 reset
```

The safe mode:

- runs `docker compose down --remove-orphans` for the resolved TestKit stack;
- removes `.testkit/reports` completely, including stale `latest_run.json` and `*_latest.json` pointers;
- removes MySQL and Influx profiling shards;
- removes TestKit coverage artifacts under the allowed coverage roots;
- removes stale TestKit lock directories after the stack is down, while preserving locks that still appear active;
- preserves Docker volumes and database state;
- preserves `.testkit/history`;
- preserves baseline manifests;
- preserves `.env` files, test sources and seeds.

## Hard reset

```bash
./bin/testkit reset --hard
```

```powershell
.\bin\testkit.ps1 reset --hard
```

Hard mode performs the safe reset and additionally:

- uses `docker compose down -v --remove-orphans` to remove disposable Compose volumes for the resolved stack;
- removes `.testkit/history`;
- removes all TestKit lock directories.

Baselines remain preserved even in hard mode. `reset --hard` is a TestKit runtime reset, not a Git reset: it never runs `git clean`, never rewrites the checkout and never deletes source tests, seeds or env files.

## JSON output

```bash
./bin/testkit reset --json
./bin/testkit reset --hard --json
```

The artifact purge reports its mode, deleted paths, bytes removed, preserved classes and errors.

## Safety boundary

The internal artifact entrypoint is `scripts/reset.php`, but it refuses direct destructive execution unless `TESTKIT_RESET_CONTAINERS_STOPPED=1` is present. The public wrappers set that marker only after the first Compose teardown succeeds.

The wrapper performs a final `docker compose down --remove-orphans` because the temporary `docker compose run --rm --no-deps` used for the artifact purge can recreate Compose network metadata.

`reset` does not delete arbitrary `.testkit/selections` files. Selection files may be project-owned inputs rather than disposable TestKit outputs and therefore require explicit project-level cleanup when desired.

## Choosing between cleanup and reset

Use `cleanup` when you want retention, for example keeping the latest 10 runs:

```bash
./bin/testkit cleanup reports --max-runs=10 --apply
```

Use `reset` when old runtime evidence must not participate in the next diagnostic run:

```bash
./bin/testkit reset
```

Use `reset --hard` only when the next run must also start with fresh disposable database volumes/history:

```bash
./bin/testkit reset --hard
```

Do not use hard reset merely to make a failing test green. A reset removes state; it does not establish that the previous failure was caused by stale state.

## Contract test

```bash
bash tests/framework/test_reset_cli.sh
```

The contract covers safe/hard artifact preservation, stale-versus-active lock handling, the stopped-container guard, Docker wrapper sequencing, volume preservation/removal semantics, unknown-option fail-fast behavior and PowerShell wrapper parity markers.
