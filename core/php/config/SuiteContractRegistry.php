<?php
declare(strict_types=1);

namespace Testkit\Core\Config;

final class SuiteContractRegistry
{
    /**
     * @return array{contract_version:int,capabilities:array<string,mixed>,hazards:array<string,mixed>}
     */
    public static function contractForSuite(string $suiteId, string $language): array
    {
        return [
            'contract_version' => 1,
            'capabilities' => self::capabilities($suiteId, $language),
            'hazards' => self::hazards($suiteId),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function capabilities(string $suiteId, string $language): array
    {
        $language = strtolower(trim($language));
        $suiteId = strtolower(trim($suiteId));

        $nativeCoverageArtifacts = false;
        $structuredCoverageDiagnostics = false;
        $coverageFormats = [];

        if ($language === 'php') {
            $nativeCoverageArtifacts = true;
            $structuredCoverageDiagnostics = true;
            $coverageFormats = ['json', 'lcov'];
        } elseif ($language === 'python') {
            $nativeCoverageArtifacts = true;
            $coverageFormats = ['trace'];
        }

        $base = [
            'shared_discovery_contract' => true,
            'perf_thresholds' => true,
            'fragility_history' => true,
            'module_scoped_reports' => true,
            'native_coverage_artifacts' => $nativeCoverageArtifacts,
            'structured_coverage_diagnostics' => $structuredCoverageDiagnostics,
            'coverage_formats' => $coverageFormats,
            'suite_engine' => $suiteId,
            'declared_runner_contract' => true,
            'structured_warnings' => true,
            'canonical_seed_state' => true,
            'agent_run_compatible' => true,
            'parallel_guard' => $suiteId !== 'front_js',
        ];

        if ($suiteId === 'migration_contract') {
            $base['bootstrap_only'] = true;
            $base['executes_domain_tests'] = false;
            $base['supports_snapshot_baseline'] = true;
            $base['supports_layered_baseline'] = false;
            $base['parallel_guard'] = true;
            return $base;
        }

        $base['bootstrap_only'] = false;
        $base['executes_domain_tests'] = true;
        $base['supports_snapshot_baseline'] = true;
        $base['supports_layered_baseline'] = true;

        return $base;
    }

    /**
     * @return array<string,mixed>
     */
    public static function hazards(string $suiteId): array
    {
        $suiteId = strtolower(trim($suiteId));

        $base = [
            'store_bootstrap' => 'project_shared_store',
            'db_sensitivity' => 'discovered',
            'top_level_parallel_policy' => 'exclusive_when_db_sensitive',
            'intra_suite_parallel_policy' => 'per_worker_when_db_sensitive',
            'seed_state_contract' => 'required',
            'bootstrap_mutates_store' => true,
            'supports_per_worker_parallel' => true,
            'top_level_parallel_safe' => false,
        ];

        if ($suiteId === 'migration_contract') {
            return [
                'store_bootstrap' => 'project_shared_store',
                'db_sensitivity' => 'always',
                'top_level_parallel_policy' => 'exclusive',
                'intra_suite_parallel_policy' => 'sequential_only',
                'seed_state_contract' => 'required',
                'bootstrap_mutates_store' => true,
                'supports_per_worker_parallel' => false,
                'top_level_parallel_safe' => false,
                'requires_snapshot_baseline' => true,
                'bootstrap_only' => true,
            ];
        }

        return $base;
    }
}
