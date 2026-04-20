<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Testkit\Core\Common\Env;

final class SuggestedCommandBuilder
{
    private const DEFAULT_WRAPPER_FLAGS = '--rm';

    public static function rerunFiltered(string $suiteId, string $file): string
    {
        return self::buildTestRunCommand($suiteId, ['TEST_MATCH' => $file]);
    }

    public static function rerunSuite(string $suiteId): string
    {
        return self::buildTestRunCommand($suiteId);
    }

    public static function enableSeedTrace(string $suiteId): string
    {
        return self::buildTestRunCommand($suiteId, ['TESTKIT_TRACE_MIGRATIONS' => '1']);
    }

    public static function listSelection(string $suiteId): string
    {
        return self::buildTestRunCommand($suiteId, [], ['--list']);
    }

    public static function aggregateReport(): string
    {
        return self::buildPhpScriptCommand('scripts/report.php');
    }

    private static function buildTestRunCommand(string $suiteId, array $env = [], array $extraArgs = []): string
    {
        $target = str_replace('_', '-', trim($suiteId) !== '' ? $suiteId : 'all');

        if (self::isWrapperInvoker()) {
            $argv = ['php', 'runTest.php', $target];
            foreach ($extraArgs as $arg) {
                $argv[] = (string)$arg;
            }
            return self::buildWrapperCommand($argv, $env);
        }

        $command = self::inlineEnvAssignments($env);
        $command .= ($command !== '' ? ' ' : '') . 'php runTest.php ' . $target;
        if ($extraArgs !== []) {
            $command .= ' ' . implode(' ', array_map(static fn(string $arg): string => trim($arg), $extraArgs));
        }

        return trim($command);
    }

    private static function buildPhpScriptCommand(string $script, array $env = []): string
    {
        $script = trim($script);
        if ($script === '') {
            return 'php';
        }

        if (self::isWrapperInvoker()) {
            return self::buildWrapperCommand(['php', $script], $env);
        }

        $prefix = self::inlineEnvAssignments($env);
        return trim(($prefix !== '' ? $prefix . ' ' : '') . 'php ' . $script);
    }

    private static function buildWrapperCommand(array $argv, array $env = []): string
    {
        $parts = [];
        $parts[] = self::wrapperInvokerBin();
        $parts[] = 'run';

        $flags = self::wrapperRunFlags();
        if ($flags !== '') {
            foreach (preg_split('/\s+/', $flags) ?: [] as $flag) {
                $flag = trim((string)$flag);
                if ($flag !== '') {
                    $parts[] = $flag;
                }
            }
        }

        foreach ($env as $key => $value) {
            $key = trim((string)$key);
            if ($key === '') {
                continue;
            }
            $parts[] = '-e';
            $parts[] = $key . '=' . self::quoteEnvValue((string)$value);
        }

        $parts[] = 'testkit';
        foreach ($argv as $arg) {
            $arg = trim((string)$arg);
            if ($arg !== '') {
                $parts[] = $arg;
            }
        }

        return implode(' ', $parts);
    }

    private static function inlineEnvAssignments(array $env): string
    {
        $parts = [];
        foreach ($env as $key => $value) {
            $key = trim((string)$key);
            if ($key === '') {
                continue;
            }
            $parts[] = $key . '=' . self::quoteEnvValue((string)$value);
        }

        return implode(' ', $parts);
    }

    private static function quoteEnvValue(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }

    private static function isWrapperInvoker(): bool
    {
        return self::wrapperInvokerBin() !== '';
    }

    private static function wrapperInvokerBin(): string
    {
        $explicit = trim(Env::string('TESTKIT_INVOKER_BIN', ''));
        if ($explicit !== '') {
            return $explicit;
        }

        return match (trim(Env::string('TESTKIT_INVOKER', ''))) {
            'bin_testkit_bash' => './bin/testkit',
            'bin_testkit_powershell' => '.\\bin\\testkit.ps1',
            default => '',
        };
    }

    private static function wrapperRunFlags(): string
    {
        $flags = trim(Env::string('TESTKIT_INVOKER_RUN_FLAGS', ''));
        return $flags !== '' ? $flags : self::DEFAULT_WRAPPER_FLAGS;
    }
}
