<?php
declare(strict_types=1);

namespace Testkit\Core\Cleanup;

final class CleanupOptions
{
    private const DEFAULT_KEEP_RUNS = 10;
    private const DEFAULT_KEEP_DAYS = 14;

    /** @var array<int,string> */
    private const GROUPS = [
        'all',
        'reports',
        'profiles',
        'coverage',
        'locks',
        'history',
        'baselines',
    ];

    /**
     * @param array<int,string> $args
     * @return array<string,mixed>
     */
    public static function parse(array $args): array
    {
        $group = 'all';
        $seenGroup = false;
        $options = [
            'group' => 'all',
            'apply' => false,
            'dry_run' => true,
            'json' => false,
            'force' => false,
            'keep_runs' => self::DEFAULT_KEEP_RUNS,
            'keep_days' => self::DEFAULT_KEEP_DAYS,
            'max_runs' => null,
            'max_artifacts' => null,
            'prune_to_latest' => false,
            'include_history' => false,
            'include_baselines' => false,
            'all_locks' => false,
            'help' => false,
        ];

        foreach ($args as $arg) {
            if ($arg === '' || $arg === '--dry-run') {
                continue;
            }
            if ($arg === '--help' || $arg === '-h') {
                throw new \InvalidArgumentException(self::usage());
            }
            if ($arg === '--apply') {
                $options['apply'] = true;
                $options['dry_run'] = false;
                continue;
            }
            if ($arg === '--json') {
                $options['json'] = true;
                continue;
            }
            if ($arg === '--force') {
                $options['force'] = true;
                continue;
            }
            if ($arg === '--include-history') {
                $options['include_history'] = true;
                continue;
            }
            if ($arg === '--include-baselines') {
                $options['include_baselines'] = true;
                continue;
            }
            if ($arg === '--all-locks') {
                $options['all_locks'] = true;
                continue;
            }
            if ($arg === '--prune-to-latest') {
                $options['prune_to_latest'] = true;
                continue;
            }
            if (preg_match('/^--keep-runs=(\d+)$/', $arg, $m) === 1) {
                $options['keep_runs'] = max(0, (int)$m[1]);
                continue;
            }
            if (preg_match('/^--keep-days=(\d+)$/', $arg, $m) === 1) {
                $options['keep_days'] = max(0, (int)$m[1]);
                continue;
            }
            if (preg_match('/^--max-runs=(\d+)$/', $arg, $m) === 1) {
                $options['max_runs'] = max(0, (int)$m[1]);
                continue;
            }
            if (preg_match('/^--max-artifacts=(\d+)$/', $arg, $m) === 1) {
                $options['max_artifacts'] = max(0, (int)$m[1]);
                continue;
            }

            if (!$seenGroup && in_array($arg, self::GROUPS, true)) {
                $group = $arg;
                $seenGroup = true;
                continue;
            }

            throw new \InvalidArgumentException('argumento no reconocido: ' . $arg);
        }

        $options['group'] = $group;

        if ($options['prune_to_latest']) {
            if ($options['max_artifacts'] !== null && (int)$options['max_artifacts'] !== 1) {
                throw new \InvalidArgumentException('--prune-to-latest no se puede combinar con --max-artifacts distinto de 1');
            }
            $options['max_artifacts'] = 1;
        }

        if ($options['max_artifacts'] !== null) {
            $maxArtifacts = max(0, (int)$options['max_artifacts']);
            $options['max_artifacts'] = $maxArtifacts;
            $options['keep_runs'] = $maxArtifacts;
            $options['keep_days'] = 0;
            $options['max_runs'] = $maxArtifacts;
            if ($group === 'all') {
                $options['include_history'] = true;
            }
        }

        if ($options['all_locks'] && !$options['force']) {
            throw new \InvalidArgumentException('--all-locks requiere --force');
        }
        if (($group === 'baselines' || $options['include_baselines']) && !$options['force']) {
            throw new \InvalidArgumentException('la limpieza de baselines requiere --force');
        }

        return $options;
    }

    public static function usage(): string
    {
        return <<<'TXT'
Usage:
  testkit cleanup [all|reports|profiles|coverage|locks|history|baselines] [options]

Safe defaults:
  testkit cleanup reports --dry-run
  testkit cleanup reports --apply
  testkit cleanup all --dry-run

Options:
  --dry-run              Plan only. This is the default.
  --apply                Delete the selected candidates.
  --json                 Print JSON output.
  --keep-runs=N          Keep the newest N run/shard/history artifacts. Default: 10.
  --keep-days=N          Keep artifacts newer than N days. Default: 14.
  --max-runs=N           Hard cap for report run dirs and profile shard dirs.
  --max-artifacts=N      Hard cap for run dirs, shards and timestamped JSON groups; disables keep-days.
  --prune-to-latest      Shortcut for --max-artifacts=1.
  --include-history      Include .testkit/history when group is all.
  --include-baselines    Include baseline manifests when group is all. Requires --force.
  --all-locks            Delete active and stale lock dirs. Requires --force.
  --force                Required for baselines and --all-locks.

Notes:
  - cleanup never drops databases or docker volumes.
  - cleanup never deletes test seeds or test source files.
  - baseline cleanup only removes .manifest.json files, not database state.
  - --keep-runs and --keep-days are additive; use --max-runs or --max-artifacts for hard caps.
TXT;
    }
}
