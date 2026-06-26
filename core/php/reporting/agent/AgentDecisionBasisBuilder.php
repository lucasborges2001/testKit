<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting\Agent;

final class AgentDecisionBasisBuilder
{
    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     * @param array{valid:bool,invalid_reason:?string} $evidence
     * @param array<string,mixed>|null $firstFailure
     * @return array<string,mixed>
     */
    public static function initialDecisionBasis(?array $meta, array $suiteReports, bool $canonicalOnly, string $outcomeStatus, array $evidence, ?array $firstFailure): array
    {
        $warnings = AgentReportSignalDeriver::collectWarnings($meta, $suiteReports);
        if (!$canonicalOnly) {
            $warnings[] = [
                'code' => 'AGENT_DECISION_CANONICAL_FALLBACK',
                'summary' => 'Se usaron campos legacy/top-level porque algún reporte no tenía canonical_report completo.',
            ];
        }

        $signals = [
            'outcome_status=' . $outcomeStatus,
            'evidence_valid=' . ((bool)$evidence['valid'] ? 'true' : 'false'),
        ];
        if (is_array($firstFailure)) {
            $signals[] = 'first_failure.file=' . (string)($firstFailure['file'] ?? '');
            $signals[] = 'first_failure.phase=' . (string)($firstFailure['phase'] ?? '');
            $signals[] = 'first_failure.failure_domain=' . (string)($firstFailure['failure_domain'] ?? '');
            $signals[] = 'first_failure.cause_code=' . (string)($firstFailure['cause_code'] ?? '');
        }

        return [
            'uses_canonical_report_only' => $canonicalOnly,
            'rules' => $canonicalOnly ? [] : ['fallback_top_level_fields_used'],
            'signals' => array_values(array_filter($signals, static fn(string $signal): bool => !str_ends_with($signal, '='))),
            'unknowns' => [],
            'warnings' => $warnings,
        ];
    }
}
