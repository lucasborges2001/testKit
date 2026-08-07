<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use RuntimeException;
use Throwable;
use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;
use Testkit\Core\Config\SuiteContractRegistry;
use Testkit\Core\Reporting\ResultWriter;
use Testkit\Core\Reporting\ReportSummary;
use Testkit\Core\Seeding\BaselineManifest;
use Testkit\Core\Seeding\BackupkitArtifactResolver;
use Testkit\Core\Store\StoreRegistry;

require_once __DIR__ . '/../seeding/BaselineManifest.php';
require_once __DIR__ . '/../seeding/BackupkitArtifactResolver.php';

final class MigrationContractSuite
{
    public static function run(): int
    {
        $repoRoot = Paths::repoRoot();
        $reportRoot = Paths::resolveReportRoot([]);
        Paths::ensureDir($reportRoot);
        Paths::recordSuiteReportRoot($reportRoot, 'migration_contract');

        $driver = StoreRegistry::detectDriver();
        $startedAt = gmdate('Y-m-d\TH:i:s\Z');
        $startMs = self::nowMs();
        $manifestPath = '';
        $manifest = null;
        $resolvedSnapshot = null;

        try {
            self::assertContract($driver);
            $resolvedSnapshot = BackupkitArtifactResolver::resolveFromEnv();
            ContractWorldBootstrap::prepare('migration_contract', $repoRoot);
            $manifestPath = self::resolveManifestPath($repoRoot, $driver);
            $manifest = BaselineManifest::load($manifestPath);
            if (!is_array($manifest)) {
                throw new RuntimeException(
                    'migration-contract bootstrap terminó sin manifest utilizable: ' . $manifestPath
                );
            }

            $report = self::buildReport(
                suiteStatus: 'passed',
                reportRoot: $reportRoot,
                startedAt: $startedAt,
                durationMs: max(0, self::nowMs() - $startMs),
                manifestPath: $manifestPath,
                manifest: $manifest,
                failures: [],
                resolvedSnapshot: $resolvedSnapshot
            );
            self::writeReport($reportRoot, $report);
            return 0;
        } catch (Throwable $e) {
            $failure = ReportSummary::buildThrowableFailure($e, [
                'test_id' => 'migration_contract.bootstrap',
                'test_name' => 'migration_contract.bootstrap',
                'case' => 'migration_contract.bootstrap',
                'suite_id' => 'migration_contract',
                'suite' => 'migration_contract',
                'scope' => 'integration',
                'category' => 'contract',
                'file' => 'migration_contract',
                'kind' => 'setup_failure',
                'artifact_path' => Paths::relativeToRepo($reportRoot),
            ]);

            $report = self::buildReport(
                suiteStatus: 'failed',
                reportRoot: $reportRoot,
                startedAt: $startedAt,
                durationMs: max(0, self::nowMs() - $startMs),
                manifestPath: $manifestPath,
                manifest: is_array($manifest) ? $manifest : null,
                failures: [$failure],
                resolvedSnapshot: is_array($resolvedSnapshot) ? $resolvedSnapshot : null
            );
            self::writeReport($reportRoot, $report);
            fwrite(STDERR, '[migration_contract] ' . $e->getMessage() . PHP_EOL);
            return 1;
        }
    }

    private static function assertContract(string $driver): void
    {
        $mode = strtolower(trim(Env::string('TEST_BASELINE_MODE', 'layered')));
        if ($mode !== 'snapshot') {
            throw new RuntimeException(
                'migration-contract requiere TEST_BASELINE_MODE=snapshot. ' .
                'No intentes validar migraciones reales partiendo de layered si tu objetivo es un upgrade.'
            );
        }

        $snapshot = trim(Env::string('TEST_BASELINE_SNAPSHOT_FILE', ''));
        $metadata = trim(Env::string('TEST_BASELINE_BACKUPKIT_METADATA_JSON', ''));
        $report = trim(Env::string('TEST_BASELINE_BACKUPKIT_REPORT_JSON', ''));
        if ($snapshot === '' && $metadata === '' && $report === '') {
            throw new RuntimeException(
                'migration-contract requiere TEST_BASELINE_SNAPSHOT_FILE o TEST_BASELINE_BACKUPKIT_METADATA_JSON o TEST_BASELINE_BACKUPKIT_REPORT_JSON.'
            );
        }

        $strategy = strtolower(trim(Env::string('TEST_DB_STRATEGY', 'shared')));
        if ($strategy !== 'shared') {
            throw new RuntimeException(
                'migration-contract solo acepta TEST_DB_STRATEGY=shared. ' .
                'Primero validá una baseline única sobre una DB única; después clonás workers si querés throughput. ' .
                "Valor recibido: {$strategy}."
            );
        }

        if ($driver !== 'mysql') {
            throw new RuntimeException(
                'migration-contract en esta pasada solo está cerrado para MySQL snapshot/restore.'
            );
        }
    }

    /** @return array<string,mixed> */
    private static function buildReport(
        string $suiteStatus,
        string $reportRoot,
        string $startedAt,
        int $durationMs,
        string $manifestPath,
        ?array $manifest,
        array $failures,
        ?array $resolvedSnapshot = null
    ): array {
        $contract = SuiteContractRegistry::contractForSuite('migration_contract', 'php');
        $passed = $suiteStatus === 'passed' ? 1 : 0;
        $failed = $suiteStatus === 'failed' ? 1 : 0;

        return [
            'report_contract_version' => 2,
            'runner_contract_version' => (int)($contract['contract_version'] ?? 1),
            'suite_id' => 'migration_contract',
            'suite_status' => $suiteStatus,
            'no_tests_reason' => '',
            'runner_capabilities' => is_array($contract['capabilities'] ?? null) ? $contract['capabilities'] : [],
            'runner_hazards' => is_array($contract['hazards'] ?? null) ? $contract['hazards'] : [],
            'runner_contract' => [
                'version' => (int)($contract['contract_version'] ?? 1),
                'capabilities' => is_array($contract['capabilities'] ?? null) ? $contract['capabilities'] : [],
                'hazards' => is_array($contract['hazards'] ?? null) ? $contract['hazards'] : [],
            ],
            'run_kind' => 'suite',
            'run_id' => Env::string('TEST_RUN_ID', ''),
            'meta_run_id' => Env::string('TEST_META_RUN_ID', Env::string('TEST_RUN_ID', '')),
            'started_at' => $startedAt,
            'duration_ms' => $durationMs,
            'report_root' => $reportRoot,
            'report_scope_rel' => Paths::relativeToRepo($reportRoot),
            'selected_module_scope' => '',
            'selected_test_count' => 1,
            'selected_test_files' => ['migration_contract'],
            'tests_total' => 1,
            'pass' => $passed,
            'fail' => $failed,
            'skip' => 0,
            'summary' => [
                'total' => 1,
                'passed' => $passed,
                'failed' => $failed,
                'skipped' => 0,
                'duration_ms' => $durationMs,
            ],
            'manifest_path' => $manifestPath,
            'manifest' => $manifest,
            'migration_state' => is_array($manifest['migration_state'] ?? null) ? $manifest['migration_state'] : ($manifest['plan']['migration_state'] ?? null),
            'applied_migrations' => array_values((array)($manifest['migration_state']['applied'] ?? $manifest['plan']['migration_state']['applied'] ?? [])),
            'pending_migrations' => array_values((array)($manifest['migration_state']['pending'] ?? $manifest['plan']['migration_state']['pending'] ?? [])),
            'baseline_mode' => Env::string('TEST_BASELINE_MODE', 'layered'),
            'snapshot_file' => (string)($resolvedSnapshot['path'] ?? Env::string('TEST_BASELINE_SNAPSHOT_FILE', '')),
            'resolved_snapshot' => $resolvedSnapshot,
            'backupkit_report_json' => Env::string('TEST_BASELINE_BACKUPKIT_REPORT_JSON', ''),
            'backupkit_metadata_json' => Env::string('TEST_BASELINE_BACKUPKIT_METADATA_JSON', ''),
            'migration_state_mode' => Env::string('TEST_MIGRATION_STATE_MODE', ''),
            'migration_target' => Env::string('TEST_MIGRATION_TARGET', 'latest'),
            'migration_auto_pending' => Env::bool('TEST_MIGRATION_AUTO_PENDING', true),
            'db_strategy' => Env::string('TEST_DB_STRATEGY', 'shared'),
            'filters' => [
                'suite' => 'migration_contract',
                'scope' => 'integration',
                'category' => 'contract',
                'match' => '',
            ],
            'report_keep' => max(1, Env::int('TEST_REPORT_KEEP', 5)),
            'runs_index_keep' => max(1, Env::int('TEST_RUNS_INDEX_KEEP', Env::int('TEST_REPORT_KEEP', 5))),
            'failures' => $failures,
            'first_failure' => $failures !== [] ? ReportSummary::summarizeFailure($failures[0]) : null,
            'evidence_valid' => $suiteStatus === 'passed',
            'evidence_invalid_reason' => $suiteStatus === 'passed' ? null : 'bootstrap_failed',
            'has_failures' => $failures !== [],
        ];
    }

    private static function resolveManifestPath(string $repoRoot, string $driver): string
    {
        $adapter = StoreRegistry::fromDriver($driver);
        $database = trim((string)$adapter->resolveDatabaseName());
        if ($database === '') {
            throw new RuntimeException('No se pudo resolver nombre de DB para manifest de migration-contract.');
        }

        return BaselineManifest::pathFor($repoRoot, $driver, $database);
    }

    /** @param array<string,mixed> $report */
    private static function writeReport(string $reportRoot, array $report): void
    {
        $report['report_root'] = $reportRoot;
        $report['report_scope_rel'] = Paths::relativeToRepo($reportRoot);
        ResultWriter::writeSuite($report);
    }

    private static function nowMs(): int
    {
        return (int)round(microtime(true) * 1000);
    }
}
