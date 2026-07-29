<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use InvalidArgumentException;
use Testkit\Core\Common\Env;

final class CommandSuggestion
{
    public static function shellKind(): string
    {
        $kind = trim(Env::string('TESTKIT_WRAPPER_KIND', ''));
        if (in_array($kind, ['bash', 'powershell', 'direct'], true)) {
            return $kind;
        }

        return 'direct';
    }

    public static function rerun(string $suite, string $file): string
    {
        $suite = self::suiteName($suite);
        $quotedFile = self::shellSingleQuote($file);

        return match (self::shellKind()) {
            'bash' => "./bin/testkit run --rm testkit php runTest.php --suite {$suite} --test '{$quotedFile}'",
            'powershell' => ".\\bin\\testkit.ps1 run --rm testkit php runTest.php --suite {$suite} --test '{$quotedFile}'",
            default => "php runTest.php --suite {$suite} --test '{$quotedFile}'",
        };
    }

    public static function report(): string
    {
        return match (self::shellKind()) {
            'bash' => './bin/testkit run --rm testkit php scripts/report.php',
            'powershell' => '.\\bin\\testkit.ps1 run --rm testkit php scripts/report.php',
            default => 'php scripts/report.php',
        };
    }

    public static function listSelection(string $suite): string
    {
        $suite = self::suiteName($suite);
        return match (self::shellKind()) {
            'bash' => "./bin/testkit run --rm testkit php runTest.php --suite {$suite} --list",
            'powershell' => ".\\bin\\testkit.ps1 run --rm testkit php runTest.php --suite {$suite} --list",
            default => "php runTest.php --suite {$suite} --list",
        };
    }

    public static function traceMigrations(string $suite): string
    {
        $suite = self::suiteName($suite);
        return match (self::shellKind()) {
            'bash' => "./bin/testkit run --rm -e TESTKIT_TRACE_MIGRATIONS=1 testkit php runTest.php --suite {$suite}",
            'powershell' => ".\\bin\\testkit.ps1 run --rm -e TESTKIT_TRACE_MIGRATIONS=1 testkit php runTest.php --suite {$suite}",
            default => "TESTKIT_TRACE_MIGRATIONS=1 php runTest.php --suite {$suite}",
        };
    }

    private static function suiteName(string $suite): string
    {
        $suite = str_replace('_', '-', strtolower(trim($suite)));
        if ($suite === '' || preg_match('/^[a-z0-9][a-z0-9-]*$/', $suite) !== 1) {
            throw new InvalidArgumentException('suite inválida para comando sugerido: ' . $suite);
        }
        return $suite;
    }

    private static function shellSingleQuote(string $value): string
    {
        return str_replace("'", "'\\''", $value);
    }
}
