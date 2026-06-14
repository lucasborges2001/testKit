# testKit cleanup

`cleanup` removes generated artifacts from host projects that use testKit. It is conservative by default: it plans first and deletes only with `--apply`.

The command is for generated operational artifacts. It is not a database reset command and it is not a project-source cleanup command.

## Common commands

```bash
./testkit/bin/testkit cleanup reports --dry-run
./testkit/bin/testkit cleanup reports --apply
./testkit/bin/testkit cleanup reports --max-runs=10 --dry-run
./testkit/bin/testkit cleanup reports --max-runs=10 --apply
./testkit/bin/testkit cleanup all --dry-run
```

PowerShell:

```powershell
.\testkit\bin\testkit.ps1 cleanup reports --dry-run
.\testkit\bin\testkit.ps1 cleanup reports --max-runs=10 --apply
```

## Recommended operator flow

1. Inspect candidates first:

   ```bash
   ./testkit/bin/testkit cleanup reports --max-runs=10 --dry-run
   ```

2. Apply only after the plan looks correct:

   ```bash
   ./testkit/bin/testkit cleanup reports --max-runs=10 --apply
   ```

3. Verify the retained run count:

   ```bash
   find .testkit/reports/runs -mindepth 1 -maxdepth 1 -type d | wc -l
   ```

`tree .testkit/reports/runs` counts the root directory too. For the exact number of run directories, prefer the `find` command above.

## Retention semantics

`--keep-runs` and `--keep-days` are additive protections. A run is kept if either condition protects it.

Use `--max-runs=N` when you want a hard cap on report run directories and profile shard directories.

Example:

```bash
./testkit/bin/testkit cleanup reports --max-runs=10 --apply
```

This keeps only the 10 newest report run directories, even when more runs are still within `--keep-days`.

### Practical difference

```bash
./testkit/bin/testkit cleanup reports --keep-runs=10 --keep-days=7 --apply
```

This can keep more than 10 runs if many runs happened during the last 7 days.

```bash
./testkit/bin/testkit cleanup reports --max-runs=10 --apply
```

This keeps at most 10 runs.

## Groups

```text
all
reports
profiles
coverage
locks
history
baselines
```

### reports

Targets:

```text
.testkit/reports/runs/<run_id>/
.testkit/reports/*_<timestamp>.json
```

Preserves:

```text
*_latest.json
latest_run.json
```

### profiles

Targets:

```text
.testkit/mysql_profile/shards/<run_id>/
.testkit/influx_profile/shards/<run_id>/
```

### coverage

Targets safe coverage artifacts only:

```text
test/coverage
$TEST_COVERAGE_DIR when it is inside an allowed coverage location
```

### locks

Targets stale lock directories under:

```text
.testkit/locks
```

Active locks are preserved unless `--all-locks --force` is used.

### history

Not included in `all` by default. Use:

```bash
./testkit/bin/testkit cleanup all --include-history --dry-run
```

### baselines

Requires `--force`. Deletes only baseline manifest files, not databases or stores.

```bash
./testkit/bin/testkit cleanup baselines --force --dry-run
```

## Safety rules

`cleanup` never deletes:

```text
databases
Docker volumes
test seeds
test source files
env files
repo root
testkit root
.testkit root itself
```

Deletion requires:

```text
--apply
```

Destructive special cases require:

```text
--force
```

## Audit artifacts

Every run writes:

```text
.testkit/reports/cleanup/cleanup_latest.json
.testkit/reports/cleanup/cleanup_<timestamp>.json
```

A cleanup audit includes the mode, group, options, scanned counts, delete candidates, deleted counts, byte estimates and per-candidate reasons.

## Internal split

`core/php/cleanup/CleanupCommand.php` is intentionally small. The cleanup implementation is split into:

```text
CleanupOptions.php        CLI option parsing and validation
CleanupPlanner.php        Candidate discovery and plan construction
CleanupExecutor.php       Deletion execution after --apply
CleanupFilesystem.php     File traversal, deletion, size and formatting helpers
CleanupSafety.php         Path safety policy
CleanupLockInspector.php  Stale lock detection
CleanupAuditWriter.php    Cleanup audit artifact writer
CleanupReporter.php       Text and JSON output
```

The external entrypoint remains unchanged:

```text
scripts/cleanup.php -> Testkit\Core\Cleanup\CleanupCommand::runCli($argv)
```

## Contract tests

Core contract:

```bash
php tests/framework/test_cleanup_contract.php
```

Script entrypoint smoke:

```bash
bash tests/framework/test_cleanup_cli.sh
```

The tests validate:

- `--dry-run` does not delete.
- `--apply` deletes only candidates beyond `--max-runs`.
- only the newest run dirs remain.
- `*_latest.json` is preserved.
- `cleanup_latest.json` is written.
- `scripts/cleanup.php` returns valid JSON through the real CLI entrypoint.
