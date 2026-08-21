<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

require_once __DIR__ . '/../execution/CommandSpec.php';

use Testkit\Core\Common\Paths;
use Testkit\Core\Execution\CommandSpec;
use Testkit\Core\Execution\ProcessRunner;

final class AgentRunExecute
{
    /**
     * Execute only the versioned command_spec emitted by the planner.
     * Human-readable `next_action.command` is presentation and is never parsed.
     *
     * @param array<string,mixed> $decision
     * @return array<string,mixed>
     */
    public static function execute(array $decision): array
    {
        $nextAction = is_array($decision['next_action'] ?? null) ? $decision['next_action'] : [];
        $kind = trim((string)($nextAction['kind'] ?? 'no_action'));
        $rawSpec = $nextAction['command_spec'] ?? null;

        if ($rawSpec === null) {
            return self::notExecuted($kind, (string)($nextAction['reason'] ?? 'no_execution_required'));
        }
        if (!is_array($rawSpec)) {
            return self::rejected($kind, 'command_spec must be an object/map.');
        }

        try {
            $commandSpec = CommandSpec::normalize($rawSpec);
            $cwd = self::resolveCwd((string)$commandSpec['cwd']);
        } catch (\Throwable $e) {
            return self::rejected($kind, $e->getMessage(), $rawSpec);
        }

        $argv = array_values($commandSpec['argv']);
        $envOverrides = self::normalizeEnvOverrides($commandSpec['env']);
        $env = self::currentEnv();
        foreach ($envOverrides as $key => $value) {
            $env[$key] = $value;
        }

        $job = ProcessRunner::start($argv, $cwd, $env);
        $finished = ProcessRunner::finish($job);

        $stdout = (string)($finished['stdout'] ?? '');
        $stderr = (string)($finished['stderr'] ?? '');
        $childPayload = null;
        if ((bool)$commandSpec['expects_json']) {
            $childPayload = self::decodeJsonPayload($stdout);
        }

        return [
            'executed' => true,
            'kind' => $kind,
            'reason' => (string)($nextAction['reason'] ?? ''),
            'admission' => [
                'accepted' => true,
                'schema' => (string)$commandSpec['schema'],
                'executor' => (string)$commandSpec['executor'],
                'error' => null,
            ],
            'command_spec' => $commandSpec,
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

    /** @param array<string,mixed> $execution */
    public static function exitCode(array $execution): int
    {
        $result = is_array($execution['result'] ?? null) ? $execution['result'] : [];
        if (!(bool)($execution['executed'] ?? false)) {
            $admission = is_array($execution['admission'] ?? null) ? $execution['admission'] : [];
            return (($admission['accepted'] ?? true) === false)
                ? max(2, (int)($result['exit_code'] ?? 2))
                : 0;
        }

        return (int)($result['exit_code'] ?? 0);
    }

    /** @return array<string,mixed> */
    private static function notExecuted(string $kind, string $reason): array
    {
        return [
            'executed' => false,
            'kind' => $kind !== '' ? $kind : 'no_action',
            'reason' => $reason !== '' ? $reason : 'no_execution_required',
            'admission' => [
                'accepted' => true,
                'schema' => null,
                'executor' => null,
                'error' => null,
            ],
            'command_spec' => null,
            'command' => [
                'argv' => [],
                'cwd' => Paths::relativeToRepo(Paths::testkitRoot()),
                'env_overrides' => [],
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

    /** @param array<string,mixed>|null $rawSpec @return array<string,mixed> */
    private static function rejected(string $kind, string $error, ?array $rawSpec = null): array
    {
        return [
            'executed' => false,
            'kind' => $kind !== '' ? $kind : 'unknown',
            'reason' => 'command_spec_rejected',
            'admission' => [
                'accepted' => false,
                'schema' => is_array($rawSpec) ? ($rawSpec['schema'] ?? null) : null,
                'executor' => is_array($rawSpec) ? ($rawSpec['executor'] ?? null) : null,
                'error' => $error,
            ],
            'command_spec' => $rawSpec,
            'command' => [
                'argv' => [],
                'cwd' => Paths::relativeToRepo(Paths::testkitRoot()),
                'env_overrides' => [],
                'display' => null,
            ],
            'result' => [
                'exit_code' => 2,
                'duration_ms' => 0,
                'stdout_excerpt' => null,
                'stderr_excerpt' => $error !== '' ? $error : 'command_spec rejected',
            ],
            'child_payload' => null,
        ];
    }

    private static function resolveCwd(string $relativeCwd): string
    {
        $root = rtrim(Paths::normalize(Paths::testkitRoot()), '/\\');
        if ($relativeCwd === '.') {
            return $root;
        }

        return Paths::normalize($root . '/' . $relativeCwd);
    }

    /** @param mixed $value @return array<string,string> */
    private static function normalizeEnvOverrides(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $key => $envValue) {
            if (!is_string($key) || !is_string($envValue)) {
                continue;
            }
            $normalized[$key] = $envValue;
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }

    /** @return array<string,string> */
    private static function currentEnv(): array
    {
        $env = [];

        $raw = getenv();
        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                if (!is_string($key) || $key === '' || !is_scalar($value)) {
                    continue;
                }
                $env[$key] = (string)$value;
            }
        }

        foreach (array_merge($_SERVER, $_ENV) as $key => $value) {
            if (!is_string($key) || $key === '' || !is_scalar($value)) {
                continue;
            }
            if (!array_key_exists($key, $env)) {
                $env[$key] = (string)$value;
            }
        }

        return $env;
    }

    /** @return array<string,mixed>|null */
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
        $lines = array_values(array_filter(
            array_map(static fn(string $line): string => rtrim($line), $lines),
            static fn(string $line): bool => $line !== ''
        ));
        if ($lines === []) {
            return null;
        }

        return implode("\n", array_slice($lines, 0, $maxLines));
    }
}
