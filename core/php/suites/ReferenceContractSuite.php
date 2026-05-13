<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use Throwable;
use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;
use Testkit\Core\Common\ProjectEnv;
use Testkit\Core\Config\SuiteContractRegistry;
use Testkit\Core\References\PhpIncludeScanner;
use Testkit\Core\References\ReferenceConfig;
use Testkit\Core\References\ReferenceConfigException;
use Testkit\Core\References\ReferenceConsoleRenderer;
use Testkit\Core\References\ReferenceContractResult;
use Testkit\Core\References\ReferenceRootResolver;
use Testkit\Core\Reporting\ReportSummary;
use Testkit\Core\Reporting\ResultWriter;

final class ReferenceContractSuite
{
    public static function run(): int
    {
        $repoRoot = Paths::repoRoot();
        ProjectEnv::hydrateCurrentProcess($repoRoot, false);

        $config = self::fallbackConfig();
        $startedAt = gmdate('Y-m-d\TH:i:s\Z');
        $startedMs = ReferenceContractResult::nowMs();
        $reportRoot = Paths::reportsRoot() . '/reference_contract';
        Paths::ensureDir($reportRoot);
        Paths::recordSuiteReportRoot($reportRoot, 'reference_contract');
        $discoveryStart = ReferenceContractResult::nowMs();

        try {
            $config = ReferenceConfig::fromEnv();
            $resolvedRoot = ReferenceRootResolver::resolve($repoRoot, $config);
            $discoveryMs = max(0, ReferenceContractResult::nowMs() - $discoveryStart);
            $result = new ReferenceContractResult(
                scope: $config->scope,
                referenceRoot: (string)$resolvedRoot['relative_root'],
                absoluteRoot: (string)$resolvedRoot['absolute_root'],
                startedMs: $startedMs
            );
            $result->phaseTimingsMs['discovery'] = $discoveryMs;

            if (!Env::bool('TEST_LIST', false)) {
                $scanner = new PhpIncludeScanner(
                    config: $config,
                    repoRoot: $repoRoot,
                    rootAbs: (string)$resolvedRoot['absolute_root'],
                    rootRel: (string)$resolvedRoot['relative_root']
                );
                $result = $scanner->scan();
                $result->phaseTimingsMs['discovery'] = $discoveryMs;
            }

            $reportingStart = ReferenceContractResult::nowMs();
            $report = self::buildReport(
                result: $result,
                config: $config,
                reportRoot: $reportRoot,
                startedAt: $startedAt,
                rootMetadata: $resolvedRoot,
                listOnly: Env::bool('TEST_LIST', false)
            );
            $report['phase_timings_ms']['reporting'] = max(0, ReferenceContractResult::nowMs() - $reportingStart);
            self::writeReport($report);
            self::printConsole($report, $config);

            return $report['suite_status'] === 'passed' ? 0 : 1;
        } catch (Throwable $e) {
            $causeCode = ReferenceRootResolver::causeCodeFor($e);
            $failure = ReferenceContractResult::failure(
                'reference_config_error',
                $e->getMessage(),
                '',
                0,
                [
                    'phase' => 'discovery',
                    'failure_domain' => 'references',
                    'cause_code' => $causeCode,
                    'scope' => $config->scope,
                ]
            );

            $result = new ReferenceContractResult(
                scope: $config->scope,
                referenceRoot: '',
                absoluteRoot: '',
                startedMs: $startedMs
            );
            $result->phaseTimingsMs['discovery'] = max(0, ReferenceContractResult::nowMs() - $discoveryStart);
            $result->addFailure($failure);

            $report = self::buildReport(
                result: $result,
                config: $config,
                reportRoot: $reportRoot,
                startedAt: $startedAt,
                rootMetadata: [
                    'scope' => $config->scope,
                    'root_input' => $config->explicitRoot,
                    'absolute_root' => '',
                    'relative_root' => '',
                    'source' => '',
                    'explicit_absolute' => false,
                ],
                listOnly: Env::bool('TEST_LIST', false)
            );
            self::writeReport($report);
            self::printConsole($report, $config);
            fwrite(STDERR, '[reference_contract] ' . $e->getMessage() . PHP_EOL);
            return 1;
        }
    }

    private static function fallbackConfig(): ReferenceConfig
    {
        return new ReferenceConfig(
            scope: 'back',
            explicitRoot: '',
            timeoutSec: 20,
            maxFiles: 3000,
            maxBytesPerFile: 1048576,
            maxViolations: 200,
            dynamicSeverity: 'warn',
            ignoreDirs: ['vendor', 'node_modules', '.git', '.testkit', 'testkit/_out', '_out']
        );
    }

    /**
     * @param array<string,mixed> $rootMetadata
     * @return array<string,mixed>
     */
    private static function buildReport(
        ReferenceContractResult $result,
        ReferenceConfig $config,
        string $reportRoot,
        string $startedAt,
        array $rootMetadata,
        bool $listOnly
    ): array {
        $contract = SuiteContractRegistry::contractForSuite('reference_contract', 'php');
        $report = $result->toReport([
            'report_contract_version' => 2,
            'runner_contract_version' => (int)($contract['contract_version'] ?? 1),
            'runner_capabilities' => is_array($contract['capabilities'] ?? null) ? $contract['capabilities'] : [],
            'runner_hazards' => is_array($contract['hazards'] ?? null) ? $contract['hazards'] : [],
            'runner_contract' => [
                'version' => (int)($contract['contract_version'] ?? 1),
                'capabilities' => is_array($contract['capabilities'] ?? null) ? $contract['capabilities'] : [],
                'hazards' => is_array($contract['hazards'] ?? null) ? $contract['hazards'] : [],
            ],
            'language' => 'php',
            'category' => 'contract',
            'run_kind' => 'suite',
            'run_id' => Env::string('TEST_RUN_ID', ''),
            'meta_run_id' => Env::string('TEST_META_RUN_ID', Env::string('TEST_RUN_ID', '')),
            'started_at' => $startedAt,
            'finished_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'duration_ms' => $result->durationMs(),
            'report_root' => $reportRoot,
            'report_scope_rel' => Paths::relativeToRepo($reportRoot),
            'selected_module_scope' => '',
            'selected_test_count' => $result->referencesFound,
            'selected_test_files' => $result->referenceRoot !== '' ? [$result->referenceRoot] : [],
            'list_only' => $listOnly,
            'no_tests_reason' => null,
            'filters' => [
                'suite' => 'reference_contract',
                'scope' => $config->scope,
                'category' => 'contract',
                'match' => Env::string('TEST_MATCH', ''),
            ],
            'reference_config' => $config->toArray(),
            'reference_root_source' => (string)($rootMetadata['source'] ?? ''),
            'reference_root_input' => (string)($rootMetadata['root_input'] ?? ''),
            'reference_root_explicit_absolute' => (bool)($rootMetadata['explicit_absolute'] ?? false),
            'failure_contract' => [
                'canonical' => 'failures',
                'legacy_fallback' => 'failed_tests',
            ],
            'report_keep' => max(1, Env::int('TEST_REPORT_KEEP', 5)),
            'runs_index_keep' => max(1, Env::int('TEST_RUNS_INDEX_KEEP', Env::int('TEST_REPORT_KEEP', 5))),
        ]);

        return ReportSummary::enrichReport($report);
    }

    /**
     * @param array<string,mixed> $report
     */
    private static function writeReport(array $report): void
    {
        ResultWriter::writeSuite($report);
    }

    /**
     * @param array<string,mixed> $report
     */
    private static function printConsole(array $report, ReferenceConfig $config): void
    {
        echo ReferenceConsoleRenderer::render($report, $config, 8);
    }
}
