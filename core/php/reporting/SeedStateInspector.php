<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Testkit\Core\Common\Paths;

final class SeedStateInspector
{
    /**
     * Intercepts only `inspect seed-state` so the rest of inspect can keep using
     * the existing Inspector implementation unchanged.
     *
     * @param array<int,string> $argv
     */
    public static function maybeHandleCli(array $argv): ?int
    {
        $args = array_values(array_slice($argv, 1));
        $command = strtolower(trim((string)($args[0] ?? '')));
        if ($command !== 'seed-state') {
            return null;
        }

        [$options] = self::parseArgs($argv);

        try {
            $payload = self::buildSeedStateInspection(
                (string)($options['run'] ?? ''),
                self::normalizeSuiteId((string)($options['suite'] ?? ''))
            );
        } catch (\Throwable $e) {
            if ((bool)($options['json'] ?? false)) {
                self::printJson([
                    'ok' => false,
                    'error' => $e->getMessage(),
                ]);
            } else {
                fwrite(STDERR, 'inspect error: ' . $e->getMessage() . PHP_EOL);
            }
            return 2;
        }

        if ((bool)($options['json'] ?? false)) {
            self::printJson($payload);
            return 0;
        }

        self::printText($payload);
        return 0;
    }

    /**
     * @param array<int,string> $argv
     * @return array{0:array<string,mixed>,1:array<int,string>}
     */
    private static function parseArgs(array $argv): array
    {
        $args = array_values(array_slice($argv, 1));
        $options = [
            'json' => false,
            'suite' => '',
            'run' => '',
        ];
        $positionals = [];

        foreach ($args as $arg) {
            if ($arg === '--json') {
                $options['json'] = true;
                continue;
            }
            if (str_starts_with($arg, '--suite=')) {
                $options['suite'] = substr($arg, strlen('--suite='));
                continue;
            }
            if (str_starts_with($arg, '--run=')) {
                $options['run'] = substr($arg, strlen('--run='));
                continue;
            }
            $positionals[] = $arg;
        }

        return [$options, $positionals];
    }

    /**
     * @return array<string,mixed>
     */
    private static function buildSeedStateInspection(string $runId = '', string $suiteId = ''): array
    {
        $context = self::resolveRunContext($runId);
        $meta = self::loadMetaReport($context['report_root']);
        $suiteReports = self::loadCanonicalSuiteReports($context['report_root']);
        self::assertCanonicalContext($meta, $suiteReports, $context['report_root']);

        $suiteSeedStates = self::suiteSeedStates($suiteReports);
        $selectedSeedState = self::selectSeedState($suiteSeedStates, $suiteId);
        $migrationContract = self::migrationContractPayload($suiteReports);
        if ($selectedSeedState === null && $suiteId === 'migration_contract' && is_array($migrationContract)) {
            $selectedSeedState = $migrationContract;
        }

        return [
            'ok' => true,
            'command' => 'seed-state',
            'inspect_contract' => 'canonical_only',
            'run_id' => $context['run_id'],
            'report_root' => $context['report_root'],
            'report_scope_rel' => $context['report_scope_rel'],
            'meta_summary' => self::metaSummary($meta, $suiteReports),
            'suite_seed_states' => $suiteSeedStates,
            'selected_seed_state' => $selectedSeedState,
            'baseline_manifests' => self::loadBaselineManifests(),
            'migration_contract' => $migrationContract,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $suiteReports
     * @return array<int,array<string,mixed>>
     */
    private static function suiteSeedStates(array $suiteReports): array
    {
        $rows = [];

        foreach ($suiteReports as $report) {
            $canonical = self::requireCanonicalReport($report, 'suite report');
            $seedState = $canonical['seed_state'] ?? null;
            if (!is_array($seedState) || $seedState === []) {
                continue;
            }

            $selection = is_array($canonical['selection'] ?? null) ? $canonical['selection'] : [];
            $summary = is_array($canonical['summary'] ?? null) ? $canonical['summary'] : [];
            $evidence = is_array($canonical['evidence'] ?? null) ? $canonical['evidence'] : [];
            $artifacts = is_array($canonical['artifacts'] ?? null) ? $canonical['artifacts'] : [];

            $rows[] = [
                'suite_id' => (string)($selection['suite_id'] ?? $report['suite_id'] ?? ''),
                'final_status' => (string)($canonical['final_status'] ?? ''),
                'evidence_valid' => (bool)($evidence['valid'] ?? true),
                'selected_test_count' => (int)($selection['selected_test_count'] ?? $summary['total'] ?? 0),
                'report_scope_rel' => (string)($artifacts['report_scope_rel'] ?? $report['report_scope_rel'] ?? ''),
                'baseline' => (string)($seedState['baseline'] ?? ''),
                'baseline_mode' => (string)($seedState['baseline_mode'] ?? ''),
                'profile' => (string)($seedState['profile'] ?? ''),
                'source_kind' => (string)($seedState['source_kind'] ?? ''),
                'store_strategy' => (string)($seedState['store_strategy'] ?? ''),
                'manifest_path' => (string)($seedState['manifest_path'] ?? ''),
                'snapshot_file' => (string)($seedState['snapshot_file'] ?? ''),
                'requested_migrations' => self::stringList($seedState['requested_migrations'] ?? []),
                'applied_migrations' => self::stringList($seedState['applied_migrations'] ?? []),
                'pending_migrations' => self::stringList($seedState['pending_migrations'] ?? []),
                'historical_absorbed' => self::stringList($seedState['historical_absorbed'] ?? []),
                'migration_state' => is_array($seedState['migration_state'] ?? null) ? $seedState['migration_state'] : null,
                'resolved_snapshot' => is_array($seedState['resolved_snapshot'] ?? null) ? $seedState['resolved_snapshot'] : null,
            ];
        }

        usort(
            $rows,
            static fn(array $a, array $b): int => strcmp((string)($a['suite_id'] ?? ''), (string)($b['suite_id'] ?? ''))
        );

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $suiteSeedStates
     * @return array<string,mixed>|null
     */
    private static function selectSeedState(array $suiteSeedStates, string $suiteId): ?array
    {
        if ($suiteId !== '') {
            foreach ($suiteSeedStates as $row) {
                if ((string)($row['suite_id'] ?? '') === $suiteId) {
                    return $row;
                }
            }
            return null;
        }

        if (count($suiteSeedStates) === 1) {
            return $suiteSeedStates[0];
        }

        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $suiteReports
     * @return array<string,mixed>|null
     */
    private static function migrationContractPayload(array $suiteReports): ?array
    {
        foreach ($suiteReports as $report) {
            $suiteId = (string)($report['suite_id'] ?? '');
            if ($suiteId !== 'migration_contract') {
                continue;
            }

            $canonical = self::requireCanonicalReport($report, 'migration contract report');
            $seedState = $canonical['seed_state'] ?? null;
            if (!is_array($seedState) || $seedState === []) {
                return null;
            }

            $selection = is_array($canonical['selection'] ?? null) ? $canonical['selection'] : [];

            return [
                'suite_id' => (string)($selection['suite_id'] ?? $suiteId),
                'baseline' => (string)($seedState['baseline'] ?? ''),
                'baseline_mode' => (string)($seedState['baseline_mode'] ?? ''),
                'profile' => (string)($seedState['profile'] ?? ''),
                'source_kind' => (string)($seedState['source_kind'] ?? ''),
                'store_strategy' => (string)($seedState['store_strategy'] ?? ''),
                'snapshot_file' => (string)($seedState['snapshot_file'] ?? ''),
                'manifest_path' => (string)($seedState['manifest_path'] ?? ''),
                'migration_state' => is_array($seedState['migration_state'] ?? null) ? $seedState['migration_state'] : null,
                'requested_migrations' => self::stringList($seedState['requested_migrations'] ?? []),
                'applied_migrations' => self::stringList($seedState['applied_migrations'] ?? []),
                'pending_migrations' => self::stringList($seedState['pending_migrations'] ?? []),
                'historical_absorbed' => self::stringList($seedState['historical_absorbed'] ?? []),
                'resolved_snapshot' => is_array($seedState['resolved_snapshot'] ?? null) ? $seedState['resolved_snapshot'] : null,
            ];
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private static function resolveRunContext(string $requestedRunId = ''): array
    {
        $requestedRunId = trim($requestedRunId);
        if ($requestedRunId !== '') {
            $root = Paths::reportRunRoot($requestedRunId);
            if (!is_dir($root)) {
                throw new \RuntimeException('run id no encontrado: ' . $requestedRunId);
            }

            return [
                'run_id' => $requestedRunId,
                'report_root' => Paths::normalize($root),
                'report_scope_rel' => Paths::relativeToRepo($root),
            ];
        }

        $latestManifest = self::loadJsonFile(Paths::latestRunManifestPath());
        if (is_array($latestManifest)) {
            $root = trim((string)($latestManifest['report_root'] ?? ''));
            if ($root !== '' && is_dir($root)) {
                return [
                    'run_id' => (string)($latestManifest['run_id'] ?? ''),
                    'report_root' => Paths::normalize($root),
                    'report_scope_rel' => (string)($latestManifest['report_scope_rel'] ?? Paths::relativeToRepo($root)),
                ];
            }
        }

        return [
            'run_id' => '',
            'report_root' => Paths::reportsRoot(),
            'report_scope_rel' => Paths::relativeToRepo(Paths::reportsRoot()),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function loadMetaReport(string $reportRoot): ?array
    {
        $candidate = rtrim($reportRoot, '/\\') . '/meta_latest.json';
        $json = self::loadJsonFile($candidate);
        if (!is_array($json)) {
            return null;
        }

        $json['_source_file'] = $candidate;
        return self::assertCanonicalEnvelope($json, $candidate);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function loadCanonicalSuiteReports(string $reportRoot): array
    {
        if (!is_dir($reportRoot)) {
            return [];
        }

        $reports = [];
        foreach (glob(rtrim($reportRoot, '/\\') . '/*_latest.json') ?: [] as $file) {
            $name = basename($file);
            if ($name === 'meta_latest.json') {
                continue;
            }
            if (str_contains($name, '__')) {
                continue;
            }

            $json = self::loadJsonFile($file);
            if (!is_array($json) || !isset($json['suite_id'])) {
                continue;
            }

            $json['_source_file'] = $file;
            $reports[] = self::assertCanonicalEnvelope($json, $file);
        }

        usort(
            $reports,
            static fn(array $a, array $b): int => strcmp((string)($a['suite_id'] ?? ''), (string)($b['suite_id'] ?? ''))
        );

        return $reports;
    }

    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     */
    private static function assertCanonicalContext(?array $meta, array $suiteReports, string $reportRoot): void
    {
        if (is_array($meta) || $suiteReports !== []) {
            return;
        }

        throw new \RuntimeException(
            'inspect seed-state no encontró reportes canónicos en ' . Paths::relativeToRepo($reportRoot)
            . '. Corré un run reciente antes de inspeccionar seed_state.'
        );
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private static function requireCanonicalReport(array $report, string $context): array
    {
        $canonical = $report['canonical_report'] ?? null;
        if (!is_array($canonical)) {
            throw new \RuntimeException($context . ' no contiene canonical_report');
        }
        if (!array_key_exists('report_version', $canonical)) {
            throw new \RuntimeException($context . ' tiene canonical_report sin report_version');
        }
        return $canonical;
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private static function assertCanonicalEnvelope(array $report, string $path): array
    {
        $canonical = $report['canonical_report'] ?? null;
        if (!is_array($canonical)) {
            throw new \RuntimeException(
                'inspect seed-state encontró un reporte sin canonical_report en ' . Paths::relativeToRepo($path)
                . '. Mezclar runs nuevos con JSON legacy ya no está soportado.'
            );
        }
        if (!array_key_exists('report_version', $canonical)) {
            throw new \RuntimeException(
                'inspect seed-state encontró canonical_report incompleto en ' . Paths::relativeToRepo($path)
                . ' (falta report_version).'
            );
        }
        return $report;
    }

    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     * @return array<string,mixed>
     */
    private static function metaSummary(?array $meta, array $suiteReports): array
    {
        if (is_array($meta)) {
            $canonical = self::requireCanonicalReport($meta, 'meta report');
            $selection = is_array($canonical['selection'] ?? null) ? $canonical['selection'] : [];
            return [
                'target' => (string)($selection['target'] ?? ''),
                'selected_test_count' => (int)($selection['selected_test_count'] ?? 0),
                'summary' => is_array($canonical['summary'] ?? null) ? $canonical['summary'] : [],
                'suite_status_counts' => is_array($meta['suite_status_counts'] ?? null) ? $meta['suite_status_counts'] : [],
            ];
        }

        $summary = [
            'total' => 0,
            'passed' => 0,
            'failed' => 0,
            'skipped' => 0,
            'duration_ms' => 0,
        ];
        $selected = 0;

        foreach ($suiteReports as $report) {
            $canonical = self::requireCanonicalReport($report, 'suite report');
            $selection = is_array($canonical['selection'] ?? null) ? $canonical['selection'] : [];
            $row = is_array($canonical['summary'] ?? null) ? $canonical['summary'] : [];
            $summary['total'] += (int)($row['total'] ?? 0);
            $summary['passed'] += (int)($row['passed'] ?? 0);
            $summary['failed'] += (int)($row['failed'] ?? 0);
            $summary['skipped'] += (int)($row['skipped'] ?? 0);
            $summary['duration_ms'] += (int)($row['duration_ms'] ?? 0);
            $selected += (int)($selection['selected_test_count'] ?? 0);
        }

        return [
            'target' => '',
            'selected_test_count' => $selected,
            'summary' => $summary,
            'suite_status_counts' => [],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function loadBaselineManifests(): array
    {
        $rows = [];
        foreach (glob(Paths::artifactsRoot() . '/baselines/*/*.manifest.json') ?: [] as $file) {
            $json = self::loadJsonFile($file);
            if (!is_array($json)) {
                continue;
            }

            $rows[] = [
                'path' => Paths::relativeToRepo($file),
                'status' => (string)($json['status'] ?? ''),
                'driver' => (string)($json['driver'] ?? ''),
                'db_name' => (string)($json['db_name'] ?? ''),
                'baseline_mode' => (string)($json['baseline_mode'] ?? ''),
                'generated_at' => (string)($json['generated_at'] ?? ''),
                'baseline_fingerprint' => (string)($json['baseline_fingerprint'] ?? ''),
                'migration_state' => $json['migration_state'] ?? null,
            ];
        }

        usort(
            $rows,
            static fn(array $a, array $b): int => strcmp((string)($a['path'] ?? ''), (string)($b['path'] ?? ''))
        );

        return $rows;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function printText(array $payload): void
    {
        echo 'inspect seed-state' . PHP_EOL;
        echo str_repeat('=', 72) . PHP_EOL;
        echo 'run_id: ' . (string)($payload['run_id'] ?? '') . PHP_EOL;
        echo 'report_root: ' . (string)($payload['report_scope_rel'] ?? $payload['report_root'] ?? '') . PHP_EOL;

        $metaSummary = is_array($payload['meta_summary'] ?? null) ? $payload['meta_summary'] : [];
        $summary = is_array($metaSummary['summary'] ?? null) ? $metaSummary['summary'] : [];
        echo 'summary: total=' . (int)($summary['total'] ?? 0)
            . ' pass=' . (int)($summary['passed'] ?? 0)
            . ' fail=' . (int)($summary['failed'] ?? 0)
            . ' skip=' . (int)($summary['skipped'] ?? 0)
            . ' time_ms=' . (int)($summary['duration_ms'] ?? 0)
            . PHP_EOL;

        $selected = $payload['selected_seed_state'] ?? null;
        if (is_array($selected)) {
            echo PHP_EOL . 'selected_seed_state:' . PHP_EOL;
            self::printSeedStateBlock($selected, '  ');
        }

        $suiteSeedStates = is_array($payload['suite_seed_states'] ?? null) ? $payload['suite_seed_states'] : [];
        if ($suiteSeedStates === []) {
            echo PHP_EOL . 'suite_seed_states: none' . PHP_EOL;
        } else {
            echo PHP_EOL . 'suite_seed_states:' . PHP_EOL;
            foreach ($suiteSeedStates as $row) {
                if (!is_array($row)) {
                    continue;
                }
                echo '  - suite_id=' . (string)($row['suite_id'] ?? '')
                    . ' final_status=' . (string)($row['final_status'] ?? '')
                    . ' profile=' . (string)($row['profile'] ?? '')
                    . ' baseline_mode=' . (string)($row['baseline_mode'] ?? '')
                    . ' strategy=' . (string)($row['store_strategy'] ?? '')
                    . PHP_EOL;
                if ((string)($row['manifest_path'] ?? '') !== '') {
                    echo '    manifest_path: ' . (string)$row['manifest_path'] . PHP_EOL;
                }
                if ((string)($row['snapshot_file'] ?? '') !== '') {
                    echo '    snapshot_file: ' . (string)$row['snapshot_file'] . PHP_EOL;
                }
                echo '    requested: ' . implode(', ', self::stringList($row['requested_migrations'] ?? [])) . PHP_EOL;
                echo '    applied: ' . implode(', ', self::stringList($row['applied_migrations'] ?? [])) . PHP_EOL;
                echo '    pending: ' . implode(', ', self::stringList($row['pending_migrations'] ?? [])) . PHP_EOL;
            }
        }

        $manifests = is_array($payload['baseline_manifests'] ?? null) ? $payload['baseline_manifests'] : [];
        if ($manifests === []) {
            echo PHP_EOL . 'baseline_manifests: none' . PHP_EOL;
            return;
        }

        echo PHP_EOL . 'baseline_manifests:' . PHP_EOL;
        foreach ($manifests as $manifest) {
            if (!is_array($manifest)) {
                continue;
            }
            echo '  - driver=' . (string)($manifest['driver'] ?? '')
                . ' db=' . (string)($manifest['db_name'] ?? '')
                . ' mode=' . (string)($manifest['baseline_mode'] ?? '')
                . ' status=' . (string)($manifest['status'] ?? '')
                . ' path=' . (string)($manifest['path'] ?? '')
                . PHP_EOL;
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function printSeedStateBlock(array $row, string $indent): void
    {
        echo $indent . 'suite_id: ' . (string)($row['suite_id'] ?? '') . PHP_EOL;
        echo $indent . 'baseline: ' . (string)($row['baseline'] ?? '') . PHP_EOL;
        echo $indent . 'baseline_mode: ' . (string)($row['baseline_mode'] ?? '') . PHP_EOL;
        echo $indent . 'profile: ' . (string)($row['profile'] ?? '') . PHP_EOL;
        echo $indent . 'source_kind: ' . (string)($row['source_kind'] ?? '') . PHP_EOL;
        echo $indent . 'store_strategy: ' . (string)($row['store_strategy'] ?? '') . PHP_EOL;
        if ((string)($row['manifest_path'] ?? '') !== '') {
            echo $indent . 'manifest_path: ' . (string)$row['manifest_path'] . PHP_EOL;
        }
        if ((string)($row['snapshot_file'] ?? '') !== '') {
            echo $indent . 'snapshot_file: ' . (string)$row['snapshot_file'] . PHP_EOL;
        }
        echo $indent . 'requested_migrations: ' . implode(', ', self::stringList($row['requested_migrations'] ?? [])) . PHP_EOL;
        echo $indent . 'applied_migrations: ' . implode(', ', self::stringList($row['applied_migrations'] ?? [])) . PHP_EOL;
        echo $indent . 'pending_migrations: ' . implode(', ', self::stringList($row['pending_migrations'] ?? [])) . PHP_EOL;
        echo $indent . 'historical_absorbed: ' . implode(', ', self::stringList($row['historical_absorbed'] ?? [])) . PHP_EOL;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function printJson(array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException('no se pudo serializar la salida JSON de inspect seed-state');
        }

        echo $json . PHP_EOL;
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $rows[$item] = true;
            }
        }

        $out = array_keys($rows);
        natcasesort($out);
        return array_values($out);
    }

    private static function normalizeSuiteId(string $suiteId): string
    {
        $suiteId = strtolower(trim($suiteId));
        return str_replace('-', '_', $suiteId);
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function loadJsonFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $json = json_decode($raw, true);
        return is_array($json) ? $json : null;
    }
}
