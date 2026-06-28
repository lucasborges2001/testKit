<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

require_once __DIR__ . '/SeedStateInspectionPayloadNormalizer.php';
require_once __DIR__ . '/SeedStateInspectionReportLoader.php';

final class SeedStateInspectionPayloadBuilder
{
    /** @return array<string,mixed> */
    public static function build(string $runId = '', string $suiteId = ''): array
    {
        $context = SeedStateInspectionReportLoader::resolveRunContext($runId);
        $reportRoot = (string)$context['report_root'];
        $meta = SeedStateInspectionReportLoader::loadMetaReport($reportRoot);
        $suiteReports = SeedStateInspectionReportLoader::loadCanonicalSuiteReports($reportRoot);

        SeedStateInspectionReportLoader::assertCanonicalContext($meta, $suiteReports, $reportRoot);

        $suiteSeedStates = SeedStateInspectionPayloadNormalizer::suiteSeedStates($suiteReports);
        $selectedSeedState = SeedStateInspectionPayloadNormalizer::selectSeedState($suiteSeedStates, $suiteId);
        $migrationContract = SeedStateInspectionPayloadNormalizer::migrationContractPayload($suiteReports);

        if ($selectedSeedState === null && $suiteId === 'migration_contract' && is_array($migrationContract)) {
            $selectedSeedState = $migrationContract;
        }

        return [
            'ok' => true,
            'command' => 'seed-state',
            'inspect_contract' => 'canonical_only',
            'run_id' => (string)$context['run_id'],
            'report_root' => $reportRoot,
            'report_scope_rel' => (string)$context['report_scope_rel'],
            'meta_summary' => SeedStateInspectionPayloadNormalizer::metaSummary($meta, $suiteReports),
            'suite_seed_states' => $suiteSeedStates,
            'selected_seed_state' => $selectedSeedState,
            'baseline_manifests' => SeedStateInspectionReportLoader::loadBaselineManifests(),
            'migration_contract' => $migrationContract,
        ];
    }
}
