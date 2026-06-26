<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

require_once __DIR__ . '/agent/AgentActionPlanner.php';
require_once __DIR__ . '/agent/AgentDecisionBasisBuilder.php';
require_once __DIR__ . '/agent/AgentReportLoader.php';
require_once __DIR__ . '/agent/AgentReportSignalDeriver.php';
require_once __DIR__ . '/agent/AgentSelectionDeriver.php';
require_once __DIR__ . '/agent/AgentStatusNormalizer.php';

use Testkit\Core\Common\Paths;
use Testkit\Core\Reporting\Agent\AgentActionPlanner;
use Testkit\Core\Reporting\Agent\AgentDecisionBasisBuilder;
use Testkit\Core\Reporting\Agent\AgentReportLoader;
use Testkit\Core\Reporting\Agent\AgentReportSignalDeriver;
use Testkit\Core\Reporting\Agent\AgentSelectionDeriver;

final class AgentDecisionBuilder
{
    /**
     * @return array<string,mixed>
     */
    public static function buildLatestDecision(string $requestedRunId = '', string $goal = ''): array
    {
        $context = self::resolveRunContext($requestedRunId);
        $meta = self::loadMetaReport((string)$context['report_root']);
        $suiteReports = self::loadSuiteReports((string)$context['report_root']);

        if (!is_array($meta) && $suiteReports === []) {
            throw new \RuntimeException(
                'agent decision no encontró reportes en ' . Paths::relativeToRepo((string)$context['report_root'])
            );
        }

        return self::buildFromContext($context, $meta, $suiteReports, $goal);
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     * @return array<string,mixed>
     */
    public static function buildFromContext(array $context, ?array $meta, array $suiteReports, string $goal = ''): array
    {
        $selection = self::deriveSelection($meta, $suiteReports);
        $finalStatus = AgentReportSignalDeriver::deriveFinalStatus($meta, $suiteReports);
        $outcomeStatus = AgentReportSignalDeriver::deriveOutcomeStatus($meta, $suiteReports, $finalStatus);
        $evidence = AgentReportSignalDeriver::deriveEvidence($meta, $suiteReports);
        $firstFailure = AgentReportSignalDeriver::deriveFirstActionableFailure($meta, $suiteReports);
        $agentMode = AgentReportSignalDeriver::deriveAgentMode($meta, $suiteReports);
        $canonicalOnly = AgentReportSignalDeriver::usesCanonicalReportOnly($meta, $suiteReports);
        $basis = AgentDecisionBasisBuilder::initialDecisionBasis(
            $meta,
            $suiteReports,
            $canonicalOnly,
            $outcomeStatus,
            $evidence,
            $firstFailure
        );

        $nextAction = AgentActionPlanner::deriveNextAction(
            (string)($context['run_id'] ?? ''),
            $outcomeStatus,
            (bool)$evidence['valid'],
            (string)($evidence['invalid_reason'] ?? ''),
            $firstFailure,
            $selection,
            $suiteReports,
            $agentMode,
            $basis
        );

        $agentDecision = [
            'contract_version' => 1,
            'contract_name' => 'agent_decision',
            'evidence_valid' => (bool)$evidence['valid'],
            'outcome_status' => $outcomeStatus,
            'first_actionable_failure' => $firstFailure,
            'next_action' => $nextAction,
            'decision_basis' => $basis,
        ];

        return [
            'ok' => true,
            'agent_contract' => 'deterministic_v2',
            'goal' => $goal,
            'run_id' => (string)($context['run_id'] ?? ''),
            'report_root' => (string)($context['report_root'] ?? ''),
            'report_scope_rel' => (string)($context['report_scope_rel'] ?? ''),
            'final_status' => $finalStatus,
            'outcome_status' => $outcomeStatus,
            'evidence_valid' => (bool)$evidence['valid'],
            'evidence_invalid_reason' => $evidence['invalid_reason'],
            'selection' => $selection,
            'first_failure' => $firstFailure,
            'first_actionable_failure' => $firstFailure,
            'agent_mode' => $agentMode,
            'next_action' => $nextAction,
            'decision_basis' => $basis,
            'agent_summary' => [
                'evidence_valid' => (bool)$evidence['valid'],
                'outcome_status' => $outcomeStatus,
                'next_action_kind' => (string)($nextAction['kind'] ?? ''),
                'has_first_actionable_failure' => is_array($firstFailure),
            ],
            'agent_decision' => $agentDecision,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function resolveRunContext(string $requestedRunId = ''): array
    {
        return AgentReportLoader::resolveRunContext($requestedRunId);
    }

    /** @return array<string,mixed>|null */
    public static function loadLatestRunManifest(): ?array
    {
        return AgentReportLoader::loadLatestRunManifest();
    }

    /** @return array<string,mixed>|null */
    public static function loadMetaReport(string $reportRoot): ?array
    {
        return AgentReportLoader::loadMetaReport($reportRoot);
    }

    /** @return array<int,array<string,mixed>> */
    public static function loadSuiteReports(string $reportRoot): array
    {
        return AgentReportLoader::loadSuiteReports($reportRoot);
    }

    /** @return array<string,mixed>|null */
    public static function loadJsonFile(string $path): ?array
    {
        return AgentReportLoader::loadJsonFile($path);
    }

    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     * @return array<string,mixed>
     */
    public static function deriveSelection(?array $meta, array $suiteReports): array
    {
        return AgentSelectionDeriver::deriveSelection($meta, $suiteReports);
    }
}
