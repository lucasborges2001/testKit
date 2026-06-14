# testKit cleanup

`cleanup` removes generated artifacts from host projects that use testKit. It is conservative by default: it plans first and deletes only with `--apply`.

The command is for generated operational artifacts. It is not a database reset command and it is not a project-source cleanup command.

## Common commands

```bash
./testkit/bin/testkit cleanup reports --dry-run
./testkit/bin/testkit cleanup reports --apply
./testkit/bin/testkit cleanup reports --max-runs=10 --dry-run
./testkit/bin/testkit cleanup reports --max-runs=10 --apply
./testkit/bin/testkit cleanup coverage --dry-run
./testkit/bin/testkit cleanup coverage --apply
./testkit/bin/testkit cleanup all --dry-run
./testkit/bin/testkit cleanup all --prune-to-latest --dry-run
./testkit/bin/testkit cleanup all --prune-to-latest --apply
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

Use `--max-artifacts=N` when you want a hard cap across run directories, profile shards and timestamped JSON groups. This disables the `--keep-days` age protection and sets the retained count to `N` for supported artifact families.

Use `--prune-to-latest` as the operational shortcut for `--max-artifacts=1`.

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

This keeps at most 10 report run directories and profile shard directories.

```bash
./testkit/bin/testkit cleanup all --prune-to-latest --apply
```

This leaves the operational minimum for generated artifacts:

- report runs: newest run directory only
- profile shards: newest shard per profile only
- timestamped report JSON: newest JSON per prefix only
- cleanup audits: `cleanup_latest.json` plus at most the current `cleanup_<timestamp>.json`
- history: newest timestamped JSON per prefix when timestamped history artifacts exist
- coverage: removed because it is regenerable
- locks: stale locks only, unless `--all-locks --force` is used
- baselines: not touched unless `--include-baselines --force` is used

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
.testkit/reports/cleanup/cleanup_<timestamp>.json
```

Preserves:

```text
*_latest.json
latest_run.json
```

When `--prune-to-latest` is used, old cleanup audit JSON files are removed before the current audit is written, so the final tree keeps `cleanup_latest.json` and at most one timestamped cleanup audit.

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
.testkit/coverage
.testkit/coverage/*
$TEST_COVERAGE_ROOT when it resolves to a safe coverage location
$TEST_COVERAGE_DIR when it resolves to a safe coverage location
```

Default `cleanup coverage` deletes direct children under `.testkit/coverage`, such as `.testkit/coverage/back_php`, and preserves the `.testkit/coverage` container directory when it still has sibling coverage entries.

`cleanup all --prune-to-latest` deletes the complete `.testkit/coverage` root because coverage is regenerable.

Safe coverage locations are restricted to:

```text
test/coverage
.testkit/coverage
```

Relative `TEST_COVERAGE_DIR` values are resolved from the host project root. The Docker wrapper forwards `TEST_COVERAGE_DIR` and `TEST_COVERAGE_ROOT` into the cleanup container.

Examples:

```bash
./testkit/bin/testkit cleanup coverage --dry-run
TEST_COVERAGE_DIR=.testkit/coverage/back_php ./testkit/bin/testkit cleanup coverage --dry-run
TEST_COVERAGE_DIR=.testkit/coverage/back_php ./testkit/bin/testkit cleanup coverage --apply
```

Unsafe values are rejected, including the repo root, testkit root, `.testkit` root, test source directories, seed directories, database paths, Docker volume paths and env files.

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

`--max-artifacts=N` and `--prune-to-latest` include history automatically for `cleanup all` and retain timestamped history JSON by prefix.

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

- `cleanup coverage --dry-run` detects `.testkit/coverage/back_php`.
- `cleanup coverage --apply` deletes `.testkit/coverage/back_php`.
- `TEST_COVERAGE_DIR=.testkit/coverage/back_php` reaches the cleanup runtime.
- unsafe `TEST_COVERAGE_DIR` values are rejected.
- `cleanup all --max-runs=1` keeps only the newest report run directory.
- `--prune-to-latest` leaves the operational minimum for supported artifact families.
- cleanup does not delete database files, Docker volume files, seeds, source tests, env files or the `.testkit` root.
- `cleanup_latest.json` is written.
- `scripts/cleanup.php` returns valid JSON through the real CLI entrypoint.
