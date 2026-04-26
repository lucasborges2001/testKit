<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Testkit\Core\Common\Paths;

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
        $finalStatus = self::deriveFinalStatus($meta, $suiteReports);
        $outcomeStatus = self::deriveOutcomeStatus($meta, $suiteReports, $finalStatus);
        $evidence = self::deriveEvidence($meta, $suiteReports);
        $firstFailure = self::deriveFirstActionableFailure($meta, $suiteReports);
        $agentMode = self::deriveAgentMode($meta, $suiteReports);
        $canonicalOnly = self::usesCanonicalReportOnly($meta, $suiteReports);
        $basis = self::initialDecisionBasis($meta, $suiteReports, $canonicalOnly, $outcomeStatus, $evidence, $firstFailure);

        $nextAction = self::deriveNextAction(
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

        $latestManifest = self::loadLatestRunManifest();
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

    /** @return array<string,mixed>|null */
    public static function loadLatestRunManifest(): ?array
    {
        return self::loadJsonFile(Paths::latestRunManifestPath());
    }

    /** @return array<string,mixed>|null */
    public static function loadMetaReport(string $reportRoot): ?array
    {
        $candidate = rtrim($reportRoot, '/\\') . '/meta_latest.json';
        $json = self::loadJsonFile($candidate);
        if (!is_array($json)) {
            return null;
        }

        $json['_source_file'] = $candidate;
        return $json;
    }

    /** @return array<int,array<string,mixed>> */
    public static function loadSuiteReports(string $reportRoot): array
    {
        if (!is_dir($reportRoot)) {
            return [];
        }

        $reports = [];
        foreach (glob(rtrim($reportRoot, '/\\') . '/*_latest.json') ?: [] as $file) {
            $name = basename($file);
            if ($name === 'meta_latest.json' || str_contains($name, '__')) {
                continue;
            }

            $json = self::loadJsonFile($file);
            if (!is_array($json) || !isset($json['suite_id'])) {
                continue;
            }

            $json['_source_file'] = $file;
            $reports[] = $json;
        }

        usort($reports, static fn(array $a, array $b): int => strcmp((string)($a['suite_id'] ?? ''), (string)($b['suite_id'] ?? '')));
        return $reports;
    }

    /** @return array<string,mixed>|null */
    public static function loadJsonFile(string $path): ?array
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

    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     * @return array<string,mixed>
     */
    public static function deriveSelection(?array $meta, array $suiteReports): array
    {
        $suiteIds = [];
        $selectedTestCount = 0;
        $match = '';
        $scope = '';
        $category = '';
        $moduleScope = '';
        $target = '';

        foreach (array_values(array_filter([$meta], 'is_array')) as $report) {
            $selection = self::selectionFromReport($report);
            $target = (string)($selection['target'] ?? $target);
            $match = (string)($selection['match'] ?? $match);
            $scope = (string)($selection['scope'] ?? $scope);
            $category = (string)($selection['category'] ?? $category);
            $moduleScope = (string)($selection['selected_module_scope'] ?? $moduleScope);
            $selectedTestCount = max($selectedTestCount, (int)($selection['selected_test_count'] ?? 0));
        }

        foreach ($suiteReports as $report) {
            $selection = self::selectionFromReport($report);
            $suiteId = trim((string)($selection['suite_id'] ?? $report['suite_id'] ?? ''));
            if ($suiteId !== '') {
                $suiteIds[$suiteId] = true;
            }
            if ($selectedTestCount === 0) {
                $selectedTestCount += (int)($selection['selected_test_count'] ?? 0);
            }
            if ($match === '') {
                $match = (string)($selection['match'] ?? '');
            }
            if ($scope === '') {
                $scope = (string)($selection['scope'] ?? '');
            }
            if ($category === '') {
                $category = (string)($selection['category'] ?? '');
            }
            if ($moduleScope === '') {
                $moduleScope = (string)($selection['selected_module_scope'] ?? '');
            }
        }

        return [
            'target' => $target,
            'scope' => $scope,
            'category' => $category,
            'match' => $match,
            'selected_test_count' => $selectedTestCount,
            'selected_module_scope' => $moduleScope,
            'suite_ids' => array_values(array_keys($suiteIds)),
            'primary_suite_id' => count($suiteIds) === 1 ? (string)array_key_first($suiteIds) : '',
        ];
    }

    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     */
    private static function deriveFinalStatus(?array $meta, array $suiteReports): string
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
            $selection = self::selectionFromReport($report);
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

    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     */
    private static function deriveOutcomeStatus(?array $meta, array $suiteReports, string $finalStatus): string
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

        return self::outcomeFromFinalStatus($finalStatus);
    }

    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     * @return array{valid:bool,invalid_reason:?string}
     */
    private static function deriveEvidence(?array $meta, array $suiteReports): array
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

    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     * @return array<string,mixed>|null
     */
    private static function deriveFirstActionableFailure(?array $meta, array $suiteReports): ?array
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

    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     * @return array<string,mixed>
     */
    private static function deriveAgentMode(?array $meta, array $suiteReports): array
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

    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     */
    private static function usesCanonicalReportOnly(?array $meta, array $suiteReports): bool
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

    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     * @param array{valid:bool,invalid_reason:?string} $evidence
     * @param array<string,mixed>|null $firstFailure
     * @return array<string,mixed>
     */
    private static function initialDecisionBasis(?array $meta, array $suiteReports, bool $canonicalOnly, string $outcomeStatus, array $evidence, ?array $firstFailure): array
    {
        $warnings = self::collectWarnings($meta, $suiteReports);
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

    /**
     * @param array<string,mixed>|null $firstFailure
     * @param array<string,mixed> $selection
     * @param array<int,array<string,mixed>> $suiteReports
     * @param array<string,mixed> $agentMode
     * @param array<string,mixed> $basis
     * @return array<string,mixed>
     */
    private static function deriveNextAction(
        string $runId,
        string $outcomeStatus,
        bool $evidenceValid,
        string $evidenceInvalidReason,
        ?array $firstFailure,
        array $selection,
        array $suiteReports,
        array $agentMode,
        array &$basis
    ): array {
        $file = is_array($firstFailure) ? trim((string)($firstFailure['file'] ?? '')) : '';
        $suiteId = is_array($firstFailure) ? trim((string)($firstFailure['suite_id'] ?? '')) : '';
        if ($suiteId === '') {
            $suiteId = self::selectionPrimarySuiteId($selection, $suiteReports);
        }
        $target = $suiteId !== '' ? str_replace('_', '-', $suiteId) : self::selectionTargetHint($selection, $suiteReports);

        if ($outcomeStatus === 'contention') {
            $basis['rules'][] = 'outcome_contention_then_inspect_concurrency';
            if (!$evidenceValid) {
                $basis['warnings'][] = [
                    'code' => 'AGENT_DECISION_INVALID_EVIDENCE',
                    'summary' => 'La evidencia de la corrida no es válida; no se sugiere rerun genérico.',
                ];
            }
            return self::action(
                'inspect_concurrency',
                self::inspectCommand('concurrency', $runId, $agentMode),
                'El resultado indica contención/lock; primero hay que inspeccionar concurrencia y ownership.',
                $evidenceValid ? 'high' : 'medium',
                $runId,
                null,
                null
            );
        }

        if (!$evidenceValid) {
            $basis['rules'][] = 'evidence_invalid_then_inspect_latest';
            $basis['warnings'][] = [
                'code' => 'AGENT_DECISION_INVALID_EVIDENCE',
                'summary' => 'Evidencia inválida' . ($evidenceInvalidReason !== '' ? ': ' . $evidenceInvalidReason : '.')
            ];
            return self::action(
                'inspect_latest',
                self::inspectCommand('latest', $runId, $agentMode),
                'No hay evidencia confiable para inferir causa; se debe inspeccionar la corrida antes de actuar.',
                'low',
                $runId,
                null,
                null
            );
        }

        if ($outcomeStatus === 'bootstrap_error') {
            $basis['rules'][] = 'outcome_bootstrap_error_then_inspect_seed_state';
            return self::action(
                'inspect_seed_state',
                self::inspectCommand('seed-state', $runId, $agentMode),
                'El fallo pertenece a bootstrap/seeding; un test aislado ocultaría la causa.',
                'high',
                $runId,
                $suiteId !== '' ? $suiteId : null,
                null
            );
        }

        if ($outcomeStatus === 'discovery_error') {
            $basis['rules'][] = 'outcome_discovery_error_then_inspect_latest';
            return self::action(
                'inspect_latest',
                self::inspectCommand('latest', $runId, $agentMode),
                'El fallo ocurrió en discovery; revisar selección y artefactos antes de tocar tests.',
                'medium',
                $runId,
                $suiteId !== '' ? $suiteId : null,
                null
            );
        }

        if ($outcomeStatus === 'timeout' && $file !== '') {
            $basis['rules'][] = 'outcome_timeout_with_file_then_rerun_single_file';
            return self::action(
                'rerun_single_file',
                self::rerunSingleFileCommand($target, $file, $agentMode),
                'Timeout con archivo identificado; rerun focalizado preserva evidencia y acota duración.',
                'medium',
                $file,
                $suiteId !== '' ? $suiteId : null,
                $file
            );
        }

        if ($outcomeStatus === 'failed' && $file !== '') {
            $basis['rules'][] = 'outcome_failed_with_file_then_rerun_single_file';
            return self::action(
                'rerun_single_file',
                self::rerunSingleFileCommand($target, $file, $agentMode),
                'Fallo de dominio/ejecución con archivo disponible; el siguiente paso más barato es aislar ese archivo.',
                'high',
                $file,
                $suiteId !== '' ? $suiteId : null,
                $file
            );
        }

        if ($outcomeStatus === 'no_tests') {
            $basis['rules'][] = 'outcome_no_tests_then_list_tests';
            return self::action(
                'list_tests',
                self::listTestsCommand(self::selectionTargetHint($selection, $suiteReports), $agentMode),
                'La selección no encontró tests; validar filtros/listado, no arreglar tests inexistentes.',
                'high',
                null,
                null,
                null
            );
        }

        if (in_array($outcomeStatus, ['passed', 'listed'], true)) {
            $basis['rules'][] = 'outcome_' . $outcomeStatus . '_then_no_action';
            return self::action(
                'no_action',
                null,
                $outcomeStatus === 'passed'
                    ? 'La corrida pasó con evidencia válida.'
                    : 'La corrida solo listó tests; no hay fallo accionable.',
                'high',
                null,
                null,
                null
            );
        }

        if ($outcomeStatus === 'timeout') {
            $basis['rules'][] = 'outcome_timeout_without_file_then_no_action';
            $basis['unknowns'][] = 'timeout_without_actionable_file';
        } elseif ($outcomeStatus === 'failed') {
            $basis['rules'][] = 'outcome_failed_without_file_then_no_action';
            $basis['unknowns'][] = 'failed_without_actionable_file';
        } else {
            $basis['rules'][] = 'outcome_unhandled_then_no_action';
            $basis['unknowns'][] = 'unhandled_outcome_status:' . $outcomeStatus;
        }

        return self::action(
            'no_action',
            null,
            'No hay fallo accionable suficiente para sugerir una mutación o rerun específico sin inventar causalidad.',
            'low',
            null,
            null,
            null
        );
    }

    /** @return array<string,mixed> */
    private static function action(string $kind, ?string $command, string $reason, string $confidence, mixed $target, ?string $suiteId, ?string $file): array
    {
        $action = [
            'kind' => $kind,
            'command' => $command,
            'reason' => $reason,
            'confidence' => $confidence,
            'target' => $target,
        ];
        if ($suiteId !== null && $suiteId !== '') {
            $action['suite_id'] = $suiteId;
        }
        if ($file !== null && $file !== '') {
            $action['file'] = $file;
        }
        return $action;
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private static function selectionFromReport(array $report): array
    {
        $canonical = is_array($report['canonical_report'] ?? null) ? $report['canonical_report'] : [];
        if (is_array($canonical['selection'] ?? null)) {
            return $canonical['selection'];
        }

        return [
            'suite_id' => (string)($report['suite_id'] ?? ''),
            'target' => (string)($report['target'] ?? ''),
            'scope' => (string)($report['scope'] ?? ($report['filters']['scope'] ?? '')),
            'category' => (string)($report['category'] ?? ($report['filters']['category'] ?? '')),
            'match' => (string)($report['match'] ?? ($report['filters']['match'] ?? '')),
            'selected_test_count' => (int)($report['selected_test_count'] ?? $report['tests_total'] ?? ($report['summary']['total'] ?? 0)),
            'selected_test_files' => array_values((array)($report['selected_test_files'] ?? [])),
            'selected_module_scope' => (string)($report['selected_module_scope'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $report */
    private static function finalStatusFromReport(array $report): string
    {
        $canonical = is_array($report['canonical_report'] ?? null) ? $report['canonical_report'] : [];
        $status = strtoupper(trim((string)($canonical['final_status'] ?? '')));
        if ($status !== '') {
            return $status;
        }
        return self::finalStatusFromRawStatus((string)($report['outcome_status'] ?? $report['suite_status'] ?? $report['final_status'] ?? ''));
    }

    /** @param array<string,mixed> $report */
    private static function outcomeStatusFromReport(array $report): string
    {
        $explicit = strtolower(trim((string)($report['outcome_status'] ?? '')));
        if ($explicit !== '') {
            return self::normalizeOutcome($explicit);
        }

        $canonical = is_array($report['canonical_report'] ?? null) ? $report['canonical_report'] : [];
        $diagnostics = is_array($canonical['diagnostics'] ?? null) ? $canonical['diagnostics'] : [];
        $diagnosticOutcome = strtolower(trim((string)($diagnostics['outcome_status'] ?? '')));
        if ($diagnosticOutcome !== '') {
            return self::normalizeOutcome($diagnosticOutcome);
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

        return self::outcomeFromFinalStatus(self::finalStatusFromReport($report));
    }

    /** @return array{valid:bool,invalid_reason:?string} */
    private static function evidenceFromReport(array $report): array
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
    private static function firstFailureFromReport(array $report): ?array
    {
        $canonical = is_array($report['canonical_report'] ?? null) ? $report['canonical_report'] : [];
        $evidence = is_array($canonical['evidence'] ?? null) ? $canonical['evidence'] : [];
        $selection = self::selectionFromReport($report);
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
            $domain = self::failureDomainFromPhase($phase);
        }
        $artifactPath = trim((string)($first['artifact_path'] ?? ''));
        if ($artifactPath === '') {
            $artifactPath = (string)($artifacts['report_scope_rel'] ?? self::reportArtifactPath($report));
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
            'stack_excerpt' => self::normalizeStackExcerpt($first['stack_excerpt'] ?? null),
        ];
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private static function agentModeFromReport(array $report): array
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

    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     * @return array<int,array<string,mixed>>
     */
    private static function collectWarnings(?array $meta, array $suiteReports): array
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

        if (class_exists(StructuredWarnings::class)) {
            return StructuredWarnings::canonicalize($rows);
        }

        return $rows;
    }

    private static function finalStatusFromRawStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'passed', 'pass' => 'PASS',
            'failed', 'fail' => 'FAIL',
            'partial' => 'PARTIAL',
            'timeout' => 'TIMEOUT',
            'contention', 'blocked' => 'BLOCKED',
            'infra_error', 'bootstrap_error', 'discovery_error', 'reporting_error', 'error' => 'ERROR',
            'skipped', 'skip', 'all_skipped' => 'SKIP',
            'no_tests' => 'NO_TESTS',
            'listed' => 'LISTED',
            default => '',
        };
    }

    private static function outcomeFromFinalStatus(string $finalStatus): string
    {
        return match (strtoupper(trim($finalStatus))) {
            'PASS' => 'passed',
            'FAIL' => 'failed',
            'TIMEOUT' => 'timeout',
            'BLOCKED' => 'contention',
            'NO_TESTS' => 'no_tests',
            'LISTED' => 'listed',
            'SKIP' => 'skipped',
            'PARTIAL' => 'partial',
            'ERROR' => 'infra_error',
            default => 'failed',
        };
    }

    private static function normalizeOutcome(string $status): string
    {
        return match (strtolower(trim($status))) {
            'pass' => 'passed',
            'fail' => 'failed',
            'blocked' => 'contention',
            default => strtolower(trim($status)),
        };
    }

    private static function failureDomainFromPhase(string $phase): string
    {
        return match (strtolower(trim($phase))) {
            'bootstrap' => 'bootstrap',
            'store_setup' => 'store',
            'discovery' => 'discovery',
            'reporting' => 'reporting',
            'admission' => 'runner',
            'execution' => 'domain',
            default => '',
        };
    }

    /** @param array<string,mixed> $selection @param array<int,array<string,mixed>> $suiteReports */
    private static function selectionPrimarySuiteId(array $selection, array $suiteReports): string
    {
        $primary = trim((string)($selection['primary_suite_id'] ?? ''));
        if ($primary !== '') {
            return $primary;
        }
        foreach ($suiteReports as $report) {
            $suiteId = trim((string)($report['suite_id'] ?? self::selectionFromReport($report)['suite_id'] ?? ''));
            if ($suiteId !== '') {
                return $suiteId;
            }
        }
        return '';
    }

    /** @param array<string,mixed> $selection @param array<int,array<string,mixed>> $suiteReports */
    private static function selectionTargetHint(array $selection, array $suiteReports): string
    {
        $target = trim((string)($selection['target'] ?? ''));
        if ($target !== '') {
            return $target;
        }
        $suiteId = self::selectionPrimarySuiteId($selection, $suiteReports);
        if ($suiteId !== '') {
            return str_replace('_', '-', $suiteId);
        }
        return 'all';
    }

    private static function inspectCommand(string $inspectCommand, string $runId, array $agentMode): string
    {
        $run = $runId !== '' ? ' --run=' . self::shellArg($runId) : '';
        return self::commandWithAgentMode('./bin/testkit run --rm testkit php scripts/inspect.php ' . $inspectCommand . $run . ' --json', $agentMode);
    }

    private static function listTestsCommand(string $target, array $agentMode): string
    {
        return self::commandWithAgentMode('./bin/testkit run --rm testkit php runTest.php ' . $target . ' --list', $agentMode);
    }

    private static function rerunSingleFileCommand(string $target, string $file, array $agentMode): string
    {
        return self::commandWithAgentMode('./bin/testkit run --rm -e TEST_MATCH=' . self::shellArg($file) . ' testkit php runTest.php ' . $target, $agentMode);
    }

    /** @param array<string,mixed> $agentMode */
    private static function commandWithAgentMode(string $command, array $agentMode): string
    {
        if (!(bool)($agentMode['enabled'] ?? false)) {
            return $command;
        }

        $mode = trim((string)($agentMode['mode'] ?? ''));
        if ($mode === '') {
            $mode = 'agent';
        }

        return 'TESTKIT_MODE=' . self::shellArgBare($mode) . ' ' . $command;
    }

    private static function shellArg(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }

    private static function shellArgBare(string $value): string
    {
        return preg_match('/^[A-Za-z0-9._-]+$/', $value) === 1 ? $value : self::shellArg($value);
    }

    /** @param array<string,mixed> $report */
    private static function reportArtifactPath(array $report): string
    {
        $source = trim((string)($report['_source_file'] ?? ''));
        if ($source !== '') {
            return Paths::relativeToRepo($source);
        }
        return (string)($report['report_scope_rel'] ?? $report['report_root'] ?? '');
    }

    /** @return array<int,string> */
    private static function normalizeStackExcerpt(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(static fn(mixed $line): string => trim((string)$line), $value), static fn(string $line): bool => $line !== ''));
        }

        $text = trim((string)$value);
        if ($text === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $lines = array_values(array_filter(array_map(static fn(string $line): string => trim($line), $lines), static fn(string $line): bool => $line !== ''));
        return array_slice($lines, 0, 8);
    }
}
