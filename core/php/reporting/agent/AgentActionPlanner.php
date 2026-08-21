<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting\Agent;

require_once __DIR__ . '/../../execution/CommandSpec.php';

use Testkit\Core\Config\ContractRegistry;
use Testkit\Core\Execution\CommandSpec;

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
        $suiteName = self::suitePublicName($suiteId);

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
                self::inspectCommandSpec('concurrency', $runId, $agentMode),
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
                self::inspectCommandSpec('latest', $runId, $agentMode),
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
                self::inspectCommandSpec('seed-state', $runId, $agentMode),
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
                self::inspectCommandSpec('latest', $runId, $agentMode),
                self::inspectCommand('latest', $runId, $agentMode),
                'El fallo ocurrió en discovery; revisar selección y artefactos antes de tocar tests.',
                'medium',
                $runId,
                $suiteId !== '' ? $suiteId : null,
                null
            );
        }

        if ($outcomeStatus === 'timeout' && $file !== '' && $suiteName !== '') {
            $basis['rules'][] = 'outcome_timeout_with_file_then_rerun_single_file';
            return self::action(
                'rerun_single_file',
                self::rerunSingleFileCommandSpec($suiteName, $file, $agentMode),
                self::rerunSingleFileCommand($suiteName, $file, $agentMode),
                'Timeout con archivo identificado; rerun focalizado preserva evidencia y acota duración.',
                'medium',
                $file,
                $suiteId !== '' ? $suiteId : null,
                $file
            );
        }

        if ($outcomeStatus === 'failed' && $file !== '' && $suiteName !== '') {
            $basis['rules'][] = 'outcome_failed_with_file_then_rerun_single_file';
            return self::action(
                'rerun_single_file',
                self::rerunSingleFileCommandSpec($suiteName, $file, $agentMode),
                self::rerunSingleFileCommand($suiteName, $file, $agentMode),
                'Fallo de dominio/ejecución con archivo disponible; el siguiente paso más barato es aislar ese archivo.',
                'high',
                $file,
                $suiteId !== '' ? $suiteId : null,
                $file
            );
        }

        if ($outcomeStatus === 'no_tests') {
            $basis['rules'][] = 'outcome_no_tests_then_list_tests';
            [$selectorKind, $selectorName] = self::selectorForSelection($selection, $suiteReports);
            return self::action(
                'list_tests',
                self::listTestsCommandSpec($selectorKind, $selectorName, $agentMode),
                self::listTestsCommand($selectorKind, $selectorName, $agentMode),
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
            null,
            'No hay fallo accionable suficiente para sugerir una mutación o rerun específico sin inventar causalidad.',
            'low',
            null,
            null,
            null
        );
    }

    /** @param array<string,mixed>|null $commandSpec @return array<string,mixed> */
    public static function action(
        string $kind,
        ?array $commandSpec,
        ?string $command,
        string $reason,
        string $confidence,
        mixed $target,
        ?string $suiteId,
        ?string $file
    ): array {
        $action = [
            'kind' => $kind,
            'command_spec' => $commandSpec !== null ? CommandSpec::normalize($commandSpec) : null,
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

    /** @param array<string,mixed> $agentMode @return array<string,mixed> */
    public static function inspectCommandSpec(string $inspectCommand, string $runId, array $agentMode): array
    {
        $argv = ['php', 'scripts/inspect.php', $inspectCommand];
        if ($runId !== '') {
            $argv[] = '--run=' . $runId;
        }
        $argv[] = '--json';

        return CommandSpec::create($argv, self::agentModeEnv($agentMode), '.', true);
    }

    /** @param array<string,mixed> $agentMode @return array<string,mixed> */
    public static function listTestsCommandSpec(string $selectorKind, string $selectorName, array $agentMode): array
    {
        return CommandSpec::create(
            ['php', 'runTest.php', '--' . $selectorKind, $selectorName, '--list'],
            self::agentModeEnv($agentMode)
        );
    }

    /** @param array<string,mixed> $agentMode @return array<string,mixed> */
    public static function rerunSingleFileCommandSpec(string $suiteName, string $file, array $agentMode): array
    {
        return CommandSpec::create(
            ['php', 'runTest.php', '--suite', $suiteName, '--test', $file],
            self::agentModeEnv($agentMode)
        );
    }

    /** @param array<string,mixed> $agentMode */
    public static function inspectCommand(string $inspectCommand, string $runId, array $agentMode): string
    {
        $run = $runId !== '' ? ' --run=' . self::shellArg($runId) : '';
        return self::commandWithAgentMode('./bin/testkit run --rm testkit php scripts/inspect.php ' . $inspectCommand . $run . ' --json', $agentMode);
    }

    /** @param array<string,mixed> $agentMode */
    public static function listTestsCommand(string $selectorKind, string $selectorName, array $agentMode): string
    {
        return self::commandWithAgentMode(
            './bin/testkit run --rm testkit php runTest.php --'
            . self::shellArgBare($selectorKind)
            . ' '
            . self::shellArgBare($selectorName)
            . ' --list',
            $agentMode
        );
    }

    /** @param array<string,mixed> $agentMode */
    public static function rerunSingleFileCommand(string $suiteName, string $file, array $agentMode): string
    {
        return self::commandWithAgentMode(
            './bin/testkit run --rm testkit php runTest.php --suite '
            . self::shellArgBare($suiteName)
            . ' --test '
            . self::shellArg($file),
            $agentMode
        );
    }

    /** @param array<string,mixed> $selection @param array<int,array<string,mixed>> $suiteReports @return array{0:string,1:string} */
    private static function selectorForSelection(array $selection, array $suiteReports): array
    {
        $kind = strtolower(trim((string)($selection['selector_kind'] ?? '')));
        $name = strtolower(trim((string)($selection['selector_name'] ?? '')));
        if (in_array($kind, ContractRegistry::selectorKinds(), true)
            && $name !== ''
            && ContractRegistry::definition($kind, $name) !== null) {
            return [$kind, $name];
        }

        $suiteName = self::suitePublicName(AgentSelectionDeriver::primarySuiteId($selection, $suiteReports));
        if ($suiteName !== '') {
            return ['suite', $suiteName];
        }

        $category = strtolower(trim((string)($selection['category'] ?? '')));
        if ($category !== '' && $category !== 'all' && ContractRegistry::definition('category', $category) !== null) {
            return ['category', $category];
        }

        $target = strtolower(trim(AgentSelectionDeriver::targetHint($selection, $suiteReports)));
        foreach (['group', 'suite', 'category'] as $candidateKind) {
            if (ContractRegistry::definition($candidateKind, $target) !== null) {
                return [$candidateKind, $target];
            }
        }

        return ['group', 'all'];
    }

    private static function suitePublicName(string $suiteId): string
    {
        $suiteId = strtolower(trim($suiteId));
        $suite = ContractRegistry::suites()[$suiteId] ?? null;
        return is_array($suite) ? (string)($suite['public_name'] ?? '') : '';
    }

    /** @param array<string,mixed> $agentMode @return array<string,string> */
    private static function agentModeEnv(array $agentMode): array
    {
        if (!(bool)($agentMode['enabled'] ?? false)) {
            return [];
        }

        $mode = trim((string)($agentMode['mode'] ?? ''));
        return ['TESTKIT_MODE' => $mode !== '' ? $mode : 'agent'];
    }

    /** @param array<string,mixed> $agentMode */
    public static function commandWithAgentMode(string $command, array $agentMode): string
    {
        $env = self::agentModeEnv($agentMode);
        if ($env === []) {
            return $command;
        }

        return 'TESTKIT_MODE=' . self::shellArgBare($env['TESTKIT_MODE']) . ' ' . $command;
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
