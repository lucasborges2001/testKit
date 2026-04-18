<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Testkit\Core\Common\Paths;
use Testkit\Core\Execution\ProcessRunner;

final class AgentRunExecute
{
    /**
     * @param array<string,mixed> $decision
     * @return array<string,mixed>
     */
    public static function execute(array $decision): array
    {
        $nextAction = is_array($decision['next_action'] ?? null) ? $decision['next_action'] : [];
        $plan = self::buildExecutionPlan($decision, $nextAction);

        if (!(bool)($plan['should_execute'] ?? false)) {
            return [
                'executed' => false,
                'kind' => (string)($nextAction['kind'] ?? 'stop'),
                'reason' => (string)($nextAction['reason'] ?? 'no_execution_required'),
                'command' => [
                    'argv' => [],
                    'cwd' => Paths::relativeToRepo((string)($plan['cwd'] ?? Paths::testkitRoot())),
                    'env_overrides' => self::normalizeEnvOverrides($plan['env_overrides'] ?? []),
                    'display' => null,
                ],
                'result' => [
                    'exit_code' => 0,
                    'duration_ms' => 0,
                    'stdout_excerpt' => null,
                    'stderr_excerpt' => null,
                ],
                'child_payload' => null,
            ];
        }

        $argv = array_values(array_map('strval', (array)($plan['argv'] ?? [])));
        $cwd = (string)($plan['cwd'] ?? Paths::testkitRoot());
        $envOverrides = self::normalizeEnvOverrides($plan['env_overrides'] ?? []);
        $env = self::currentEnv();
        foreach ($envOverrides as $k => $v) {
            $env[$k] = $v;
        }

        $job = ProcessRunner::start($argv, $cwd, $env);
        $finished = ProcessRunner::finish($job);

        $stdout = (string)($finished['stdout'] ?? '');
        $stderr = (string)($finished['stderr'] ?? '');
        $childPayload = null;
        if ((bool)($plan['expects_json'] ?? false)) {
            $childPayload = self::decodeJsonPayload($stdout);
        }

        return [
            'executed' => true,
            'kind' => (string)($nextAction['kind'] ?? 'unknown'),
            'reason' => (string)($nextAction['reason'] ?? ''),
            'command' => [
                'argv' => $argv,
                'cwd' => Paths::relativeToRepo($cwd),
                'env_overrides' => $envOverrides,
                'display' => ProcessRunner::joinCommand($argv),
            ],
            'result' => [
                'exit_code' => (int)($finished['code'] ?? 127),
                'duration_ms' => (int)($finished['duration_ms'] ?? 0),
                'stdout_excerpt' => self::textExcerpt($stdout, 20),
                'stderr_excerpt' => self::textExcerpt($stderr, 20),
            ],
            'child_payload' => $childPayload,
        ];
    }

    /**
     * @param array<string,mixed> $execution
     */
    public static function exitCode(array $execution): int
    {
        if (!(bool)($execution['executed'] ?? false)) {
            return 0;
        }

        $result = is_array($execution['result'] ?? null) ? $execution['result'] : [];
        return (int)($result['exit_code'] ?? 0);
    }

    /**
     * @param array<string,mixed> $decision
     * @param array<string,mixed> $nextAction
     * @return array<string,mixed>
     */
    private static function buildExecutionPlan(array $decision, array $nextAction): array
    {
        $kind = trim((string)($nextAction['kind'] ?? ''));
        $runId = trim((string)($decision['run_id'] ?? ''));
        $selection = is_array($decision['selection'] ?? null) ? $decision['selection'] : [];
        $firstFailure = is_array($decision['first_failure'] ?? null) ? $decision['first_failure'] : [];
        $php = PHP_BINARY;
        $cwd = Paths::testkitRoot();
        $envOverrides = self::agentModeEnvOverrides($decision);

        return match ($kind) {
            'stop' => [
                'should_execute' => false,
                'cwd' => $cwd,
                'env_overrides' => $envOverrides,
            ],
            'inspect_concurrency' => [
                'should_execute' => true,
                'cwd' => $cwd,
                'argv' => [$php, 'scripts/inspect.php', 'concurrency', '--run=' . $runId, '--json'],
                'env_overrides' => $envOverrides,
                'expects_json' => true,
            ],
            'inspect_failure' => [
                'should_execute' => true,
                'cwd' => $cwd,
                'argv' => [$php, 'scripts/inspect.php', 'failure', '--run=' . $runId, '--json'],
                'env_overrides' => $envOverrides,
                'expects_json' => true,
            ],
            'refine_selection' => [
                'should_execute' => true,
                'cwd' => $cwd,
                'argv' => [$php, 'scripts/inspect.php', 'latest', '--run=' . $runId, '--json'],
                'env_overrides' => $envOverrides,
                'expects_json' => true,
            ],
            'run_selected_tests' => [
                'should_execute' => true,
                'cwd' => $cwd,
                'argv' => [$php, 'runTest.php', self::selectionTargetHint($selection)],
                'env_overrides' => $envOverrides,
                'expects_json' => false,
            ],
            'rerun_single_file' => [
                'should_execute' => true,
                'cwd' => $cwd,
                'argv' => [$php, 'runTest.php', self::suiteTargetHint($nextAction, $selection)],
                'env_overrides' => array_merge($envOverrides, [
                    'TEST_MATCH' => trim((string)($nextAction['target'] ?? $firstFailure['file'] ?? '')),
                ]),
                'expects_json' => false,
            ],
            default => [
                'should_execute' => false,
                'cwd' => $cwd,
                'env_overrides' => $envOverrides,
            ],
        };
    }

    /**
     * @param array<string,mixed> $decision
     * @return array<string,string>
     */
    private static function agentModeEnvOverrides(array $decision): array
    {
        $agentMode = is_array($decision['agent_mode'] ?? null) ? $decision['agent_mode'] : [];
        if (!(bool)($agentMode['enabled'] ?? false)) {
            return [];
        }

        $mode = trim((string)($agentMode['mode'] ?? ''));
        if ($mode === '') {
            $mode = 'agent';
        }

        return ['TESTKIT_MODE' => $mode];
    }

    /**
     * @param array<string,mixed> $nextAction
     * @param array<string,mixed> $selection
     */
    private static function suiteTargetHint(array $nextAction, array $selection): string
    {
        $suiteId = trim((string)($nextAction['suite_id'] ?? $selection['primary_suite_id'] ?? ''));
        if ($suiteId !== '') {
            return str_replace('_', '-', $suiteId);
        }

        return self::selectionTargetHint($selection);
    }

    /**
     * @param array<string,mixed> $selection
     */
    private static function selectionTargetHint(array $selection): string
    {
        $target = trim((string)($selection['target'] ?? ''));
        if ($target !== '') {
            return $target;
        }

        $suiteId = trim((string)($selection['primary_suite_id'] ?? ''));
        if ($suiteId !== '') {
            return str_replace('_', '-', $suiteId);
        }

        return 'all';
    }

    /**
     * @param mixed $value
     * @return array<string,string>
     */
    private static function normalizeEnvOverrides(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $k => $v) {
            if (!is_string($k) || trim($k) === '') {
                continue;
            }
            $normalized[$k] = (string)$v;
        }

        return $normalized;
    }

    /**
     * @return array<string,string>
     */
    private static function currentEnv(): array
    {
        $env = [];

        $raw = getenv();
        if (is_array($raw)) {
            foreach ($raw as $k => $v) {
                if (!is_string($k) || $k === '' || !is_scalar($v)) {
                    continue;
                }
                $env[$k] = (string)$v;
            }
        }

        foreach (array_merge($_SERVER, $_ENV) as $k => $v) {
            if (!is_string($k) || $k === '' || !is_scalar($v)) {
                continue;
            }
            if (!array_key_exists($k, $env)) {
                $env[$k] = (string)$v;
            }
        }

        return $env;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function decodeJsonPayload(string $stdout): ?array
    {
        $stdout = trim($stdout);
        if ($stdout === '') {
            return null;
        }

        $json = json_decode($stdout, true);
        return is_array($json) ? $json : null;
    }

    private static function textExcerpt(string $text, int $maxLines): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $lines = array_values(array_filter(array_map(static fn(string $line): string => rtrim($line), $lines), static fn(string $line): bool => $line !== ''));
        if ($lines === []) {
            return null;
        }

        return implode("\n", array_slice($lines, 0, $maxLines));
    }
}
