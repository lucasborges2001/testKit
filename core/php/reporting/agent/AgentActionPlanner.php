<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting\Agent;

final class AgentActionPlanner
{
    /**
     * @param array<string,mixed>|null $firstFailure
     * @param array<string,mixed> $selection
     * @param array<int,array<string,mixed>> $suiteReports
     * @param array<string,mixed> $agentMode
     * @param array<string,mixed> $basis
     * @return array<string,mixed>
     */
    public static function deriveNextAction(
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
            $suiteId = AgentSelectionDeriver::primarySuiteId($selection, $suiteReports);
        }
        $target = $suiteId !== '' ? str_replace('_', '-', $suiteId) : AgentSelectionDeriver::targetHint($selection, $suiteReports);

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
                self::listTestsCommand(AgentSelectionDeriver::targetHint($selection, $suiteReports), $agentMode),
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
    public static function action(string $kind, ?string $command, string $reason, string $confidence, mixed $target, ?string $suiteId, ?string $file): array
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

    /** @param array<string,mixed> $agentMode */
    public static function inspectCommand(string $inspectCommand, string $runId, array $agentMode): string
    {
        $run = $runId !== '' ? ' --run=' . self::shellArg($runId) : '';
        return self::commandWithAgentMode('./bin/testkit run --rm testkit php scripts/inspect.php ' . $inspectCommand . $run . ' --json', $agentMode);
    }

    /** @param array<string,mixed> $agentMode */
    public static function listTestsCommand(string $target, array $agentMode): string
    {
        return self::commandWithAgentMode('./bin/testkit run --rm testkit php runTest.php ' . $target . ' --list', $agentMode);
    }

    /** @param array<string,mixed> $agentMode */
    public static function rerunSingleFileCommand(string $target, string $file, array $agentMode): string
    {
        return self::commandWithAgentMode('./bin/testkit run --rm -e TEST_MATCH=' . self::shellArg($file) . ' testkit php runTest.php ' . $target, $agentMode);
    }

    /** @param array<string,mixed> $agentMode */
    public static function commandWithAgentMode(string $command, array $agentMode): string
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

    public static function shellArg(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }

    public static function shellArgBare(string $value): string
    {
        return preg_match('/^[A-Za-z0-9._-]+$/', $value) === 1 ? $value : self::shellArg($value);
    }
}
