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

        if ($suiteId === 'reference_contract') {
            $base['bootstrap_only'] = false;
            $base['executes_domain_tests'] = false;
            $base['static_reference_scan'] = true;
            $base['php_include_resolution'] = true;
            $base['supports_snapshot_baseline'] = false;
            $base['supports_layered_baseline'] = false;
            $base['native_coverage_artifacts'] = false;
            $base['structured_coverage_diagnostics'] = false;
            $base['coverage_formats'] = [];
            $base['parallel_guard'] = true;
            return $base;
        }

        if ($suiteId === 'infra_php') {
            $base['bootstrap_only'] = false;
            $base['executes_domain_tests'] = true;
            $base['operational_host_suite'] = true;
            $base['supports_http_checks'] = true;
            $base['supports_docker_checks'] = true;
            $base['supports_security_boundary_checks'] = true;
            $base['supports_snapshot_baseline'] = false;
            $base['supports_layered_baseline'] = false;
            $base['canonical_seed_state'] = false;
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

        if ($suiteId === 'reference_contract') {
            return [
                'store_bootstrap' => 'none',
                'db_sensitivity' => 'never',
                'top_level_parallel_policy' => 'allowed',
                'intra_suite_parallel_policy' => 'allowed',
                'seed_state_contract' => 'not_applicable',
                'bootstrap_mutates_store' => false,
                'supports_per_worker_parallel' => false,
                'top_level_parallel_safe' => true,
                'static_scan_only' => true,
            ];
        }

        if ($suiteId === 'infra_php') {
            return [
                'store_bootstrap' => 'none',
                'db_sensitivity' => 'discovered',
                'top_level_parallel_policy' => 'allowed_unless_test_declares_db_sensitive',
                'intra_suite_parallel_policy' => 'allowed_unless_test_declares_serial_or_db_sensitive',
                'seed_state_contract' => 'not_applicable',
                'bootstrap_mutates_store' => false,
                'supports_per_worker_parallel' => true,
                'top_level_parallel_safe' => true,
                'operational_host_suite' => true,
                'may_require_external_http_server' => true,
                'may_require_docker_runtime' => true,
            ];
        }

        return $base;
    }
}
