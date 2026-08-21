<?php
declare(strict_types=1);

namespace Testkit\Core\Execution;

use InvalidArgumentException;

final class CommandSpec
{
    public const SCHEMA = 'testkit.command_spec@1';
    public const EXECUTOR_PROCESS = 'process';

    private const MAX_ARGV = 128;
    private const MAX_ARG_LENGTH = 8192;
    private const MAX_ENV = 64;
    private const MAX_ENV_VALUE_LENGTH = 16384;

    /**
     * @param array<int,string> $argv
     * @param array<string,string> $env
     * @return array<string,mixed>
     */
    public static function create(
        array $argv,
        array $env = [],
        string $cwd = '.',
        bool $expectsJson = false
    ): array {
        return self::normalize([
            'schema' => self::SCHEMA,
            'executor' => self::EXECUTOR_PROCESS,
            'argv' => $argv,
            'env' => $env,
            'cwd' => $cwd,
            'expects_json' => $expectsJson,
        ]);
    }

    /**
     * Validate a versioned command specification and return its canonical shape.
     *
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    public static function normalize(array $spec): array
    {
        $allowedKeys = ['schema', 'executor', 'argv', 'env', 'cwd', 'expects_json'];
        foreach (array_keys($spec) as $key) {
            if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
                throw new InvalidArgumentException('command_spec contains an unknown field.');
            }
        }

        if (($spec['schema'] ?? null) !== self::SCHEMA) {
            throw new InvalidArgumentException('Unsupported command_spec schema.');
        }
        if (($spec['executor'] ?? null) !== self::EXECUTOR_PROCESS) {
            throw new InvalidArgumentException('Unsupported command_spec executor.');
        }

        $argv = $spec['argv'] ?? null;
        if (!is_array($argv) || !array_is_list($argv) || $argv === []) {
            throw new InvalidArgumentException('command_spec argv must be a non-empty list.');
        }
        if (count($argv) > self::MAX_ARGV) {
            throw new InvalidArgumentException('command_spec argv exceeds the argument budget.');
        }

        $normalizedArgv = [];
        foreach ($argv as $index => $arg) {
            if (!is_string($arg)) {
                throw new InvalidArgumentException('command_spec argv entries must be strings.');
            }
            if ($index === 0 && trim($arg) === '') {
                throw new InvalidArgumentException('command_spec executable must not be empty.');
            }
            if (strlen($arg) > self::MAX_ARG_LENGTH || str_contains($arg, "\0")) {
                throw new InvalidArgumentException('command_spec argv entry is invalid or too large.');
            }
            $normalizedArgv[] = $arg;
        }
        self::assertNoFreeFormShell($normalizedArgv);

        $env = $spec['env'] ?? [];
        if (!is_array($env) || array_is_list($env) && $env !== []) {
            throw new InvalidArgumentException('command_spec env must be an object/map.');
        }
        if (count($env) > self::MAX_ENV) {
            throw new InvalidArgumentException('command_spec env exceeds the environment budget.');
        }

        $normalizedEnv = [];
        foreach ($env as $key => $value) {
            if (!is_string($key) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                throw new InvalidArgumentException('command_spec env contains an invalid variable name.');
            }
            if (!is_string($value) || strlen($value) > self::MAX_ENV_VALUE_LENGTH || str_contains($value, "\0")) {
                throw new InvalidArgumentException('command_spec env values must be bounded strings.');
            }
            $normalizedEnv[$key] = $value;
        }
        ksort($normalizedEnv, SORT_STRING);

        $cwd = $spec['cwd'] ?? '.';
        if (!is_string($cwd)) {
            throw new InvalidArgumentException('command_spec cwd must be a repo-relative string.');
        }
        $cwd = self::normalizeRelativeCwd($cwd);

        $expectsJson = $spec['expects_json'] ?? false;
        if (!is_bool($expectsJson)) {
            throw new InvalidArgumentException('command_spec expects_json must be boolean.');
        }

        return [
            'schema' => self::SCHEMA,
            'executor' => self::EXECUTOR_PROCESS,
            'argv' => $normalizedArgv,
            'env' => $normalizedEnv,
            'cwd' => $cwd,
            'expects_json' => $expectsJson,
        ];
    }

    private static function normalizeRelativeCwd(string $cwd): string
    {
        $cwd = str_replace('\\', '/', trim($cwd));
        if ($cwd === '' || $cwd === '.') {
            return '.';
        }
        if (str_starts_with($cwd, '/') || preg_match('/^[A-Za-z]:\//', $cwd) === 1) {
            throw new InvalidArgumentException('command_spec cwd must not be absolute.');
        }
        if (str_contains($cwd, "\0")) {
            throw new InvalidArgumentException('command_spec cwd contains a null byte.');
        }

        $parts = [];
        foreach (explode('/', $cwd) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new InvalidArgumentException('command_spec cwd must not traverse outside the TestKit root.');
            }
            $parts[] = $part;
        }

        return $parts === [] ? '.' : implode('/', $parts);
    }

    /** @param array<int,string> $argv */
    private static function assertNoFreeFormShell(array $argv): void
    {
        $executable = strtolower(basename(str_replace('\\', '/', $argv[0])));
        $shells = ['sh', 'bash', 'dash', 'zsh', 'ksh', 'cmd', 'cmd.exe', 'powershell', 'powershell.exe', 'pwsh', 'pwsh.exe'];
        if (!in_array($executable, $shells, true)) {
            return;
        }

        $inlineFlags = ['-c', '/c', '-command', '-encodedcommand', '-enc'];
        foreach (array_slice($argv, 1) as $arg) {
            if (in_array(strtolower($arg), $inlineFlags, true)) {
                throw new InvalidArgumentException('command_spec forbids free-form shell command modes.');
            }
        }
    }
}
