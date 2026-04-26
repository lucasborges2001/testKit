<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

final class AgentRun
{
    /**
     * @param array<int,string> $argv
     */
    public static function runCli(array $argv): int
    {
        [$options, $positionals] = self::parseArgs($argv);
        if ((bool)($options['help'] ?? false)) {
            self::printHelp();
            return 0;
        }

        if ($positionals !== []) {
            $first = strtolower(trim((string)$positionals[0]));
            if (in_array($first, ['help', '--help', '-h'], true)) {
                self::printHelp();
                return 0;
            }
            if ($first === 'execute') {
                $options['execute'] = true;
            }
        }

        try {
            $decision = self::buildLatestDecision(
                trim((string)($options['run'] ?? '')),
                trim((string)($options['goal'] ?? ''))
            );
        } catch (\Throwable $e) {
            if ((bool)($options['json'] ?? false)) {
                self::printJson(['ok' => false, 'error' => $e->getMessage()]);
            } else {
                fwrite(STDERR, 'agent-run error: ' . $e->getMessage() . PHP_EOL);
            }
            return 2;
        }

        if ((bool)($options['execute'] ?? false)) {
            $execution = AgentRunExecute::execute($decision);
            $artifact = ['recorded' => false, 'error' => null];

            try {
                $artifact = AgentRunArtifact::record($decision, $execution);
            } catch (\Throwable $e) {
                $artifact = ['recorded' => false, 'error' => $e->getMessage()];
            }

            $payload = [
                'ok' => true,
                'mode' => 'execute',
                'decision' => $decision,
                'agent_decision' => $decision['agent_decision'] ?? null,
                'execution' => $execution,
                'artifact' => $artifact,
            ];

            if ((bool)($options['json'] ?? false)) {
                self::printJson($payload);
            } else {
                self::printExecuteText($payload);
            }

            $exitCode = AgentRunExecute::exitCode($execution);
            if (!(bool)($artifact['recorded'] ?? false) && $exitCode === 0) {
                return 2;
            }

            return $exitCode;
        }

        if ((bool)($options['json'] ?? false)) {
            self::printJson($decision);
            return 0;
        }

        self::printText($decision);
        return 0;
    }

    /**
     * @return array<string,mixed>
     */
    public static function buildLatestDecision(string $requestedRunId = '', string $goal = ''): array
    {
        return AgentDecisionBuilder::buildLatestDecision($requestedRunId, $goal);
    }

    /**
     * @param array<int,string> $argv
     * @return array{0:array<string,mixed>,1:array<int,string>}
     */
    private static function parseArgs(array $argv): array
    {
        $args = array_values(array_slice($argv, 1));
        $options = [
            'json' => false,
            'run' => '',
            'goal' => '',
            'help' => false,
            'execute' => false,
        ];
        $positionals = [];

        foreach ($args as $arg) {
            if ($arg === '--json') {
                $options['json'] = true;
                continue;
            }
            if ($arg === '--help' || $arg === '-h') {
                $options['help'] = true;
                continue;
            }
            if ($arg === '--execute') {
                $options['execute'] = true;
                continue;
            }
            if (str_starts_with($arg, '--run=')) {
                $options['run'] = substr($arg, strlen('--run='));
                continue;
            }
            if (str_starts_with($arg, '--goal=')) {
                $options['goal'] = substr($arg, strlen('--goal='));
                continue;
            }
            $positionals[] = $arg;
        }

        return [$options, $positionals];
    }

    /** @param array<string,mixed> $payload */
    private static function printJson(array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException('no se pudo serializar la salida JSON de agent-run');
        }
        echo $json . PHP_EOL;
    }

    /** @param array<string,mixed> $payload */
    private static function printText(array $payload): void
    {
        echo 'agent-run' . PHP_EOL;
        echo str_repeat('=', 72) . PHP_EOL;
        echo 'run_id: ' . (string)($payload['run_id'] ?? '') . PHP_EOL;
        echo 'goal: ' . (string)($payload['goal'] ?? '') . PHP_EOL;
        echo 'contract: ' . (string)($payload['agent_contract'] ?? '') . PHP_EOL;
        echo 'outcome_status: ' . (string)($payload['outcome_status'] ?? '') . PHP_EOL;
        echo 'evidence_valid: ' . ((bool)($payload['evidence_valid'] ?? true) ? 'true' : 'false') . PHP_EOL;
        if ((string)($payload['evidence_invalid_reason'] ?? '') !== '') {
            echo 'evidence_invalid_reason: ' . (string)$payload['evidence_invalid_reason'] . PHP_EOL;
        }

        $failure = is_array($payload['first_actionable_failure'] ?? null)
            ? $payload['first_actionable_failure']
            : (is_array($payload['first_failure'] ?? null) ? $payload['first_failure'] : null);
        if (is_array($failure)) {
            echo 'first_actionable_failure: ' . (string)($failure['file'] ?? '')
                . ' [' . (string)($failure['failure_domain'] ?? '') . '/' . (string)($failure['cause_code'] ?? '') . ']'
                . PHP_EOL;
            if ((string)($failure['message'] ?? '') !== '') {
                echo '  message: ' . (string)$failure['message'] . PHP_EOL;
            }
        } else {
            echo 'first_actionable_failure: none' . PHP_EOL;
        }

        $nextAction = is_array($payload['next_action'] ?? null) ? $payload['next_action'] : [];
        echo 'next_action: ' . (string)($nextAction['kind'] ?? '')
            . ' confidence=' . (string)($nextAction['confidence'] ?? '')
            . PHP_EOL;
        echo 'reason: ' . (string)($nextAction['reason'] ?? '') . PHP_EOL;
        if ((string)($nextAction['command'] ?? '') !== '') {
            echo 'command: ' . (string)$nextAction['command'] . PHP_EOL;
        }

        $basis = is_array($payload['decision_basis'] ?? null) ? $payload['decision_basis'] : [];
        $rules = array_values((array)($basis['rules'] ?? []));
        echo 'decision_rules: ' . ($rules !== [] ? implode(', ', array_map('strval', $rules)) : 'none') . PHP_EOL;
    }

    /** @param array<string,mixed> $payload */
    private static function printExecuteText(array $payload): void
    {
        $decision = is_array($payload['decision'] ?? null) ? $payload['decision'] : [];
        self::printText($decision);

        $execution = is_array($payload['execution'] ?? null) ? $payload['execution'] : [];
        $result = is_array($execution['result'] ?? null) ? $execution['result'] : [];
        echo 'execution:' . PHP_EOL;
        echo '  executed: ' . ((bool)($execution['executed'] ?? false) ? 'true' : 'false') . PHP_EOL;
        echo '  exit_code: ' . (int)($result['exit_code'] ?? 0) . PHP_EOL;

        $artifact = is_array($payload['artifact'] ?? null) ? $payload['artifact'] : [];
        echo 'artifact_recorded: ' . ((bool)($artifact['recorded'] ?? false) ? 'true' : 'false') . PHP_EOL;
        if ((string)($artifact['latest_path'] ?? '') !== '') {
            echo 'artifact_latest: ' . (string)$artifact['latest_path'] . PHP_EOL;
        }
        if ((string)($artifact['error'] ?? '') !== '') {
            echo 'artifact_error: ' . (string)$artifact['error'] . PHP_EOL;
        }
    }

    private static function printHelp(): void
    {
        echo "Usage:\n";
        echo "  php scripts/agent-run.php [--run=<id>] [--goal=<text>] [--json]\n";
        echo "  php scripts/agent-run.php execute [--run=<id>] [--goal=<text>] [--json]\n";
        echo "  php scripts/agent-run.php --execute [--run=<id>] [--goal=<text>] [--json]\n";
    }
}
