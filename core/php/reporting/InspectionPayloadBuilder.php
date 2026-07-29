<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Testkit\Core\Common\Paths;

require_once __DIR__ . '/InspectionArtifactCollector.php';
require_once __DIR__ . '/InspectionResultNormalizer.php';

final class InspectionPayloadBuilder
{
    /** @return array<string,mixed> */
    public static function latest(string $runId = ''): array
    {
        [$context, $meta, $suiteReports, $decision] = self::loadInspectionContext($runId);

        return [
            'ok' => true,
            'command' => 'latest',
            'inspect_contract' => 'agent_decision_v1',
            'run_id' => (string)$context['run_id'],
            'report_root' => (string)$context['report_root'],
            'report_scope_rel' => (string)$context['report_scope_rel'],
            'meta_summary' => InspectionResultNormalizer::metaSummary($meta, $suiteReports),
            'suite_reports' => array_values(array_map(static fn(array $report): array => InspectionResultNormalizer::suiteSummary($report), $suiteReports)),
            'warnings' => InspectionResultNormalizer::collectWarnings($meta, $suiteReports),
            'first_failure' => $decision['first_actionable_failure'] ?? null,
            'first_actionable_failure' => $decision['first_actionable_failure'] ?? null,
            'agent_decision' => $decision['agent_decision'] ?? null,
            'next_action' => $decision['next_action'] ?? null,
            'decision_basis' => $decision['decision_basis'] ?? null,
            'agent_run_artifact' => InspectionArtifactCollector::agentRunArtifact((string)$context['report_root']),
            'artifacts' => [
                'latest_run_manifest' => Paths::relativeToRepo(Paths::latestRunManifestPath()),
                'runs_index' => Paths::relativeToRepo(Paths::reportsRoot() . '/runs_latest.json'),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function run(string $runId): array
    {
        $runId = trim($runId);
        if ($runId === '') {
            throw new \RuntimeException('inspect run requiere un run id');
        }

        [$context, $meta, $suiteReports, $decision] = self::loadInspectionContext($runId);

        return [
            'ok' => true,
            'command' => 'run',
            'inspect_contract' => 'agent_decision_v1',
            'run_id' => (string)$context['run_id'],
            'report_root' => (string)$context['report_root'],
            'report_scope_rel' => (string)$context['report_scope_rel'],
            'meta_report' => $meta,
            'suite_reports' => array_values(array_map(static fn(array $report): array => InspectionResultNormalizer::suiteSummary($report), $suiteReports)),
            'warnings' => InspectionResultNormalizer::collectWarnings($meta, $suiteReports),
            'first_failure' => $decision['first_actionable_failure'] ?? null,
            'first_actionable_failure' => $decision['first_actionable_failure'] ?? null,
            'agent_decision' => $decision['agent_decision'] ?? null,
            'next_action' => $decision['next_action'] ?? null,
            'decision_basis' => $decision['decision_basis'] ?? null,
            'agent_run_artifact' => InspectionArtifactCollector::agentRunArtifact((string)$context['report_root']),
        ];
    }

    /** @return array<string,mixed> */
    public static function failure(string $runId = '', bool $latest = false): array
    {
        [$context, $meta, $suiteReports, $decision] = self::loadInspectionContext($runId);
        $firstFailure = $decision['first_actionable_failure'] ?? null;

        return [
            'ok' => true,
            'command' => 'failure',
            'inspect_contract' => 'agent_decision_v1',
            'requested_latest' => $latest,
            'run_id' => (string)$context['run_id'],
            'report_root' => (string)$context['report_root'],
            'report_scope_rel' => (string)$context['report_scope_rel'],
            'evidence_valid' => (bool)($decision['evidence_valid'] ?? false),
            'outcome_status' => (string)($decision['outcome_status'] ?? ''),
            'warnings' => InspectionResultNormalizer::collectWarnings($meta, $suiteReports),
            'first_failure' => $firstFailure,
            'first_actionable_failure' => $firstFailure,
            'has_failure' => is_array($firstFailure),
            'agent_decision' => $decision['agent_decision'] ?? null,
            'next_action' => $decision['next_action'] ?? null,
            'decision_basis' => $decision['decision_basis'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    public static function seedState(string $runId = '', string $suiteId = ''): array
    {
        [$context, $meta, $suiteReports, $decision] = self::loadInspectionContext($runId);
        $manifests = InspectionArtifactCollector::baselineManifests();
        $migrationContract = InspectionArtifactCollector::migrationContractReport((string)$context['report_root']);

        if ($suiteId !== '') {
            foreach ($suiteReports as $report) {
                if ((string)($report['suite_id'] ?? '') === $suiteId) {
                    $migrationContract = $report;
                    break;
                }
            }
        }

        $migrationContractPayload = null;
        if (is_array($migrationContract)) {
            $canonical = is_array($migrationContract['canonical_report'] ?? null) ? $migrationContract['canonical_report'] : [];
            $seedState = is_array($canonical['seed_state'] ?? null) ? $canonical['seed_state'] : (is_array($migrationContract['seed_state'] ?? null) ? $migrationContract['seed_state'] : []);
            $selection = AgentDecisionBuilder::deriveSelection(null, [$migrationContract]);
            $migrationContractPayload = [
                'suite_id' => (string)($selection['primary_suite_id'] ?? $migrationContract['suite_id'] ?? ''),
                'baseline_mode' => (string)($seedState['baseline_mode'] ?? ''),
                'snapshot_file' => (string)($seedState['snapshot_file'] ?? ''),
                'manifest_path' => (string)($seedState['manifest_path'] ?? ''),
                'migration_state' => $seedState['migration_state'] ?? null,
                'applied_migrations' => array_values((array)($seedState['applied_migrations'] ?? [])),
                'pending_migrations' => array_values((array)($seedState['pending_migrations'] ?? [])),
            ];
        }

        return [
            'ok' => true,
            'command' => 'seed-state',
            'inspect_contract' => 'agent_decision_v1',
            'run_id' => (string)$context['run_id'],
            'report_root' => (string)$context['report_root'],
            'report_scope_rel' => (string)$context['report_scope_rel'],
            'meta_summary' => InspectionResultNormalizer::metaSummary($meta, $suiteReports),
            'warnings' => InspectionResultNormalizer::collectWarnings($meta, $suiteReports),
            'baseline_manifests' => $manifests,
            'migration_contract' => $migrationContractPayload,
            'agent_decision' => $decision['agent_decision'] ?? null,
            'next_action' => $decision['next_action'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    public static function concurrency(string $runId = ''): array
    {
        [$context, $meta, $suiteReports, $decision] = self::loadInspectionContext($runId);

        $suitePolicies = [];
        foreach ($suiteReports as $report) {
            $suitePolicies[] = [
                'suite_id' => (string)($report['suite_id'] ?? ''),
                'parallel_policy' => is_array($report['parallel_policy'] ?? null) ? $report['parallel_policy'] : null,
                'concurrency_admission' => is_array($report['concurrency_admission'] ?? null) ? $report['concurrency_admission'] : null,
                'warnings' => InspectionResultNormalizer::normalizeWarnings($report['warnings'] ?? ($report['parallel_policy']['warnings'] ?? [])),
                'evidence_valid' => InspectionResultNormalizer::reportEvidenceValid($report),
            ];
        }

        return [
            'ok' => true,
            'command' => 'concurrency',
            'inspect_contract' => 'agent_decision_v1',
            'run_id' => (string)$context['run_id'],
            'report_root' => (string)$context['report_root'],
            'report_scope_rel' => (string)$context['report_scope_rel'],
            'evidence_valid' => (bool)($decision['evidence_valid'] ?? false),
            'outcome_status' => (string)($decision['outcome_status'] ?? ''),
            'warnings' => InspectionResultNormalizer::collectWarnings($meta, $suiteReports),
            'active_locks' => InspectionArtifactCollector::activeLocks(),
            'suite_policies' => $suitePolicies,
            'agent_decision' => $decision['agent_decision'] ?? null,
            'next_action' => $decision['next_action'] ?? null,
        ];
    }

    /**
     * @return array{0:array<string,mixed>,1:?array<string,mixed>,2:array<int,array<string,mixed>>,3:array<string,mixed>}
     */
    private static function loadInspectionContext(string $runId): array
    {
        $context = AgentDecisionBuilder::resolveRunContext($runId);
        $meta = AgentDecisionBuilder::loadMetaReport((string)$context['report_root']);
        $suiteReports = AgentDecisionBuilder::loadSuiteReports((string)$context['report_root']);
        if (!is_array($meta) && $suiteReports === []) {
            throw new \RuntimeException('inspect no encontró reportes en ' . Paths::relativeToRepo((string)$context['report_root']));
        }
        $decision = AgentDecisionBuilder::buildFromContext($context, $meta, $suiteReports);
        return [$context, $meta, $suiteReports, $decision];
    }
}
