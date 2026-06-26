<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting\Agent;

final class AgentReportSignalDeriver
{
    /** @param array<string,mixed>|null $meta @param array<int,array<string,mixed>> $suiteReports */
    public static function deriveFinalStatus(?array $meta, array $suiteReports): string
    {
        if (is_array($meta)) {
            $status = self::finalStatusFromReport($meta);
            if ($status !== '') {
                return $status;
            }
        }

        $seen = [];
        $totalSelected = 0;
        foreach ($suiteReports as $report) {
            $status = self::finalStatusFromReport($report);
            if ($status !== '') {
                $seen[$status] = true;
            }
            $selection = AgentSelectionDeriver::selectionFromReport($report);
            $totalSelected += (int)($selection['selected_test_count'] ?? $report['tests_total'] ?? 0);
        }

        foreach (['ERROR', 'BLOCKED', 'TIMEOUT', 'FAIL'] as $status) {
            if (isset($seen[$status])) {
                return $status;
            }
        }
        if ($totalSelected === 0) {
            return 'NO_TESTS';
        }
        if (isset($seen['PASS']) && count($seen) === 1) {
            return 'PASS';
        }
        if (isset($seen['LISTED']) && count($seen) === 1) {
            return 'LISTED';
        }
        if (isset($seen['SKIP']) && count($seen) === 1) {
            return 'SKIP';
        }
        if ($seen !== []) {
            return 'PARTIAL';
        }

        return 'FAIL';
    }

    /** @param array<string,mixed>|null $meta @param array<int,array<string,mixed>> $suiteReports */
    public static function deriveOutcomeStatus(?array $meta, array $suiteReports, string $finalStatus): string
    {
        $candidates = [];
        if (is_array($meta)) {
            $status = self::outcomeStatusFromReport($meta);
            if ($status !== '') {
                $candidates[] = $status;
            }
        }
        foreach ($suiteReports as $report) {
            $status = self::outcomeStatusFromReport($report);
            if ($status !== '') {
                $candidates[] = $status;
            }
        }

        foreach (['contention', 'bootstrap_error', 'discovery_error', 'timeout', 'reporting_error', 'infra_error', 'failed'] as $priority) {
            if (in_array($priority, $candidates, true)) {
                return $priority;
            }
        }

        if ($candidates !== []) {
            $unique = array_values(array_unique($candidates));
            if (count($unique) === 1) {
                return $unique[0];
            }
            if (in_array('passed', $unique, true) && count($unique) === 1) {
                return 'passed';
            }
            if (in_array('listed', $unique, true) && count($unique) === 1) {
                return 'listed';
            }
            if (in_array('no_tests', $unique, true) && count($unique) === 1) {
                return 'no_tests';
            }
            if (in_array('failed', $unique, true)) {
                return 'failed';
            }
            return 'partial';
        }

        return AgentStatusNormalizer::outcomeFromFinalStatus($finalStatus);
    }

    /** @param array<string,mixed>|null $meta @param array<int,array<string,mixed>> $suiteReports @return array{valid:bool,invalid_reason:?string} */
    public static function deriveEvidence(?array $meta, array $suiteReports): array
    {
        if (is_array($meta)) {
            $evidence = self::evidenceFromReport($meta);
            if (!$evidence['valid']) {
                return $evidence;
            }
        }

        foreach ($suiteReports as $report) {
            $evidence = self::evidenceFromReport($report);
            if (!$evidence['valid']) {
                return $evidence;
            }
        }

        return ['valid' => true, 'invalid_reason' => null];
    }

    /** @param array<string,mixed>|null $meta @param array<int,array<string,mixed>> $suiteReports @return array<string,mixed>|null */
    public static function deriveFirstActionableFailure(?array $meta, array $suiteReports): ?array
    {
        if (is_array($meta)) {
            $first = self::firstFailureFromReport($meta);
            if (is_array($first)) {
                return $first;
            }
        }

        foreach ($suiteReports as $report) {
            $first = self::firstFailureFromReport($report);
            if (is_array($first)) {
                return $first;
            }
        }

        return null;
    }

    /** @param array<string,mixed>|null $meta @param array<int,array<string,mixed>> $suiteReports @return array<string,mixed> */
    public static function deriveAgentMode(?array $meta, array $suiteReports): array
    {
        if (is_array($meta)) {
            $mode = self::agentModeFromReport($meta);
            if ($mode !== []) {
                return $mode;
            }
        }

        foreach ($suiteReports as $report) {
            $mode = self::agentModeFromReport($report);
            if (($mode['enabled'] ?? false) === true) {
                return $mode;
            }
        }

        return ['enabled' => false, 'mode' => 'standard', 'enforced' => []];
    }

    /** @param array<string,mixed>|null $meta @param array<int,array<string,mixed>> $suiteReports */
    public static function usesCanonicalReportOnly(?array $meta, array $suiteReports): bool
    {
        $reports = [];
        if (is_array($meta)) {
            $reports[] = $meta;
        }
        foreach ($suiteReports as $report) {
            $reports[] = $report;
        }

        if ($reports === []) {
            return false;
        }

        foreach ($reports as $report) {
            $canonical = $report['canonical_report'] ?? null;
            if (!is_array($canonical) || !array_key_exists('report_version', $canonical)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed>|null $meta @param array<int,array<string,mixed>> $suiteReports @return array<int,array<string,mixed>> */
    public static function collectWarnings(?array $meta, array $suiteReports): array
    {
        $rows = [];
        $reports = [];
        if (is_array($meta)) {
            $reports[] = $meta;
        }
        foreach ($suiteReports as $report) {
            $reports[] = $report;
        }

        foreach ($reports as $report) {
            $canonical = is_array($report['canonical_report'] ?? null) ? $report['canonical_report'] : [];
            foreach ([$canonical['warnings'] ?? null, $report['warnings'] ?? null] as $warnings) {
                if (!is_array($warnings)) {
                    continue;
                }
                foreach ($warnings as $warning) {
                    if (is_array($warning)) {
                        $rows[] = $warning;
                    } elseif (is_scalar($warning)) {
                        $rows[] = ['code' => 'GENERIC_WARNING', 'summary' => (string)$warning];
                    }
                }
            }
        }

        if (class_exists(\Testkit\Core\Reporting\StructuredWarnings::class)) {
            return \Testkit\Core\Reporting\StructuredWarnings::canonicalize($rows);
        }

        return $rows;
    }

    /** @param array<string,mixed> $report */
    public static function finalStatusFromReport(array $report): string
    {
        $canonical = is_array($report['canonical_report'] ?? null) ? $report['canonical_report'] : [];
        $status = strtoupper(trim((string)($canonical['final_status'] ?? '')));
        if ($status !== '') {
            return $status;
        }
        return AgentStatusNormalizer::finalStatusFromRawStatus((string)($report['outcome_status'] ?? $report['suite_status'] ?? $report['final_status'] ?? ''));
    }

    /** @param array<string,mixed> $report */
    public static function outcomeStatusFromReport(array $report): string
    {
        $explicit = strtolower(trim((string)($report['outcome_status'] ?? '')));
        if ($explicit !== '') {
            return AgentStatusNormalizer::normalizeOutcome($explicit);
        }

        $canonical = is_array($report['canonical_report'] ?? null) ? $report['canonical_report'] : [];
        $diagnostics = is_array($canonical['diagnostics'] ?? null) ? $canonical['diagnostics'] : [];
        $diagnosticOutcome = strtolower(trim((string)($diagnostics['outcome_status'] ?? '')));
        if ($diagnosticOutcome !== '') {
            return AgentStatusNormalizer::normalizeOutcome($diagnosticOutcome);
        }

        $first = self::firstFailureFromReport($report);
        if (is_array($first)) {
            $cause = strtolower(trim((string)($first['cause_code'] ?? '')));
            $phase = strtolower(trim((string)($first['phase'] ?? '')));
            $domain = strtolower(trim((string)($first['failure_domain'] ?? '')));
            $kind = strtolower(trim((string)($first['kind'] ?? '')));
            if (in_array($cause, ['shared_store_locked', 'store_resource_locked'], true)) {
                return 'contention';
            }
            if ($kind === 'timeout' || $cause === 'timeout') {
                return 'timeout';
            }
            if (in_array($phase, ['bootstrap', 'store_setup'], true) || in_array($domain, ['bootstrap', 'store'], true)) {
                return 'bootstrap_error';
            }
            if ($phase === 'discovery' || $domain === 'discovery') {
                return 'discovery_error';
            }
            if ($phase === 'reporting' || $domain === 'reporting') {
                return 'reporting_error';
            }
        }

        return AgentStatusNormalizer::outcomeFromFinalStatus(self::finalStatusFromReport($report));
    }

    /** @param array<string,mixed> $report @return array{valid:bool,invalid_reason:?string} */
    public static function evidenceFromReport(array $report): array
    {
        $canonical = is_array($report['canonical_report'] ?? null) ? $report['canonical_report'] : [];
        $evidence = is_array($canonical['evidence'] ?? null) ? $canonical['evidence'] : [];
        $valid = (bool)($evidence['valid'] ?? $report['evidence_valid'] ?? true);
        $reason = $evidence['invalid_reason'] ?? $report['evidence_invalid_reason'] ?? null;
        return [
            'valid' => $valid,
            'invalid_reason' => is_string($reason) && trim($reason) !== '' ? trim($reason) : null,
        ];
    }

    /** @param array<string,mixed> $report @return array<string,mixed>|null */
    public static function firstFailureFromReport(array $report): ?array
    {
        $canonical = is_array($report['canonical_report'] ?? null) ? $report['canonical_report'] : [];
        $evidence = is_array($canonical['evidence'] ?? null) ? $canonical['evidence'] : [];
        $selection = AgentSelectionDeriver::selectionFromReport($report);
        $artifacts = is_array($canonical['artifacts'] ?? null) ? $canonical['artifacts'] : [];
        $first = $evidence['first_failure'] ?? $report['first_failure'] ?? null;
        if (!is_array($first) || $first === []) {
            return null;
        }

        $suiteId = trim((string)($first['suite_id'] ?? $selection['suite_id'] ?? $report['suite_id'] ?? ''));
        $file = trim((string)($first['file'] ?? ''));
        $phase = (string)($first['phase'] ?? '');
        $domain = (string)($first['failure_domain'] ?? '');
        if ($domain === '') {
            $domain = AgentStatusNormalizer::failureDomainFromPhase($phase);
        }
        $artifactPath = trim((string)($first['artifact_path'] ?? ''));
        if ($artifactPath === '') {
            $artifactPath = (string)($artifacts['report_scope_rel'] ?? AgentStatusNormalizer::reportArtifactPath($report));
        }

        return [
            'suite_id' => $suiteId,
            'file' => $file,
            'case' => (string)($first['case'] ?? ''),
            'kind' => (string)($first['kind'] ?? ''),
            'phase' => $phase,
            'failure_domain' => $domain,
            'cause_code' => (string)($first['cause_code'] ?? ''),
            'message' => (string)($first['message'] ?? ''),
            'artifact_path' => $artifactPath,
            'exception_class' => $first['exception_class'] ?? null,
            'stack_excerpt' => AgentStatusNormalizer::normalizeStackExcerpt($first['stack_excerpt'] ?? null),
        ];
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    public static function agentModeFromReport(array $report): array
    {
        $canonical = is_array($report['canonical_report'] ?? null) ? $report['canonical_report'] : [];
        $payload = $canonical['agent_mode'] ?? $report['agent_mode'] ?? null;
        if (!is_array($payload)) {
            return [];
        }

        $enabled = (bool)($payload['enabled'] ?? false);
        $mode = strtolower(trim((string)($payload['mode'] ?? '')));
        if ($mode === '') {
            $mode = $enabled ? 'agent' : 'standard';
        }

        $enforced = [];
        if (is_array($payload['enforced'] ?? null)) {
            foreach ($payload['enforced'] as $key => $value) {
                if (is_string($key) && trim($key) !== '') {
                    $enforced[$key] = (string)$value;
                }
            }
        }

        return ['enabled' => $enabled, 'mode' => $mode, 'enforced' => $enforced];
    }
}
