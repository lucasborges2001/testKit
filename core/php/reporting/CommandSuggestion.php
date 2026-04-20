<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

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

    public static function rerun(string $target, string $file): string
    {
        $quoted = self::shellSingleQuote($file);

        return match (self::shellKind()) {
            'bash' => "./bin/testkit run --rm -e TEST_MATCH='{$quoted}' testkit php runTest.php {$target}",
            'powershell' => ".\\bin\\testkit.ps1 run --rm -e TEST_MATCH='{$quoted}' testkit php runTest.php {$target}",
            default => "TEST_MATCH='{$quoted}' php runTest.php {$target}",
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

    public static function listSelection(string $target): string
    {
        return match (self::shellKind()) {
            'bash' => "./bin/testkit run --rm testkit php runTest.php {$target} --list",
            'powershell' => ".\\bin\\testkit.ps1 run --rm testkit php runTest.php {$target} --list",
            default => "php runTest.php {$target} --list",
        };
    }

    public static function traceMigrations(string $target): string
    {
        return match (self::shellKind()) {
            'bash' => "./bin/testkit run --rm -e TESTKIT_TRACE_MIGRATIONS=1 testkit php runTest.php {$target}",
            'powershell' => ".\\bin\\testkit.ps1 run --rm -e TESTKIT_TRACE_MIGRATIONS=1 testkit php runTest.php {$target}",
            default => "TESTKIT_TRACE_MIGRATIONS=1 php runTest.php {$target}",
        };
    }

    private static function shellSingleQuote(string $value): string
    {
        return str_replace("'", "'\\''", $value);
    }
}
