<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use InvalidArgumentException;
use Testkit\Core\Common\Env;

final class SuggestedCommandBuilder
{
    private const DEFAULT_WRAPPER_FLAGS = '--rm';

    public static function rerunFiltered(string $suiteId, string $file): string
    {
        return self::buildTestRunCommand($suiteId, [], ['--test', $file]);
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

    /** @param array<string,string> $env @param list<string> $extraArgs */
    private static function buildTestRunCommand(string $suiteId, array $env = [], array $extraArgs = []): string
    {
        $suiteName = str_replace('_', '-', strtolower(trim($suiteId)));
        if ($suiteName === '') {
            throw new InvalidArgumentException('suiteId vacío al construir comando de test');
        }

        $argv = ['php', 'runTest.php', '--suite', $suiteName];
        foreach ($extraArgs as $arg) {
            $arg = trim((string)$arg);
            if ($arg !== '') {
                $argv[] = $arg;
            }
        }

        if (self::isWrapperInvoker()) {
            return self::buildWrapperCommand($argv, $env);
        }

        $prefix = self::inlineEnvAssignments($env);
        $command = implode(' ', array_map(self::shellToken(...), $argv));
        return trim(($prefix !== '' ? $prefix . ' ' : '') . $command);
    }

    /** @param array<string,string> $env */
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
        return trim(($prefix !== '' ? $prefix . ' ' : '') . 'php ' . self::shellToken($script));
    }

    /** @param list<string> $argv @param array<string,string> $env */
    private static function buildWrapperCommand(array $argv, array $env = []): string
    {
        $parts = [self::wrapperInvokerBin(), 'run'];

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
            $parts[] = $key . '=' . self::quoteValue((string)$value);
        }

        $parts[] = 'testkit';
        foreach ($argv as $arg) {
            $arg = trim((string)$arg);
            if ($arg !== '') {
                $parts[] = self::shellToken($arg);
            }
        }

        return implode(' ', $parts);
    }

    /** @param array<string,string> $env */
    private static function inlineEnvAssignments(array $env): string
    {
        $parts = [];
        foreach ($env as $key => $value) {
            $key = trim((string)$key);
            if ($key === '') {
                continue;
            }
            $parts[] = $key . '=' . self::quoteValue((string)$value);
        }

        return implode(' ', $parts);
    }

    private static function shellToken(string $value): string
    {
        return preg_match('#^[A-Za-z0-9._/\\:=\-]+$#', $value) === 1
            ? $value
            : self::quoteValue($value);
    }

    private static function quoteValue(string $value): string
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
