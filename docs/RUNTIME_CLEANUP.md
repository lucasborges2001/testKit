# TestKit runtime cleanup

`cleanup runtime` removes stale Docker runtimes owned by TestKit. It is host-side because it must inspect and remove Compose containers, networks and database volumes.

## Default policy

```text
TTL=4h
MODE=dry-run
ACTIVE_ONEOFF=KEEP
APPLY_REQUIRES_FORCE=1
```

A runtime becomes a delete candidate only when:

1. its Compose project contains a database service labeled `io.testkit.runtime=true` and `io.testkit.resource=database`;
2. the youngest labeled database container is at least the configured TTL old;
3. the project has no running Compose one-off container.

This protects a test command that is still executing even if the underlying database was created more than four hours ago.

## Commands

Inspect stale runtimes:

```bash
./bin/testkit cleanup runtime --dry-run
./bin/testkit cleanup runtime --older-than=4h --dry-run
```

Delete eligible runtimes:

```bash
./bin/testkit cleanup runtime --older-than=4h --apply --force
```

Machine-readable output:

```bash
./bin/testkit cleanup runtime --older-than=4h --dry-run --json
```

PowerShell:

```powershell
.\bin\testkit.ps1 cleanup runtime --older-than=4h --dry-run
.\bin\testkit.ps1 cleanup runtime --older-than=4h --apply --force
```

## What is deleted

For an eligible TestKit-owned Compose project:

- project containers;
- project networks;
- Compose project volumes. Project eligibility is already proven by the labeled TestKit database container.

Images are not removed. Host source files, env files, seeds, reports outside the targeted runtime and baselines are not removed by this command.

## Audit

Every invocation writes:

```text
<project>/.testkit/reports/cleanup/runtime_cleanup_latest.json
<project>/.testkit/reports/cleanup/runtime_cleanup_<timestamp>.json
```

Each project records age, active one-off count, decision, reason and whether deletion was applied.

Decision reasons:

```text
ACTIVE_RUN
TTL_NOT_EXPIRED
RUNTIME_TTL_EXPIRED
```

## Opportunistic automatic cleanup

Automatic cleanup is opt-in:

```env
TESTKIT_RUNTIME_AUTO_CLEANUP=1
TESTKIT_RUNTIME_MAX_AGE=4h
```

When enabled, `testkit up ...` and `testkit run ...` first apply runtime cleanup with the configured TTL. Other commands do not trigger destructive cleanup implicitly.

The explicit `cleanup runtime` command remains dry-run by default regardless of this setting.

## Ownership and compatibility

Only runtimes created after the Compose labels were introduced are eligible. Older unlabeled containers/volumes are intentionally ignored rather than guessed from names.

Supported database overlays are currently MySQL, PostgreSQL and InfluxDB. Redis alone does not make a Compose project eligible for runtime cleanup.
