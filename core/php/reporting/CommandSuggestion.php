<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Testkit\Core\Common\Env;

final class CommandSuggestion
{
    public static function rerun(string $target, string $file): string
    {
        $file = self::shellSingleQuote($file);
        return match (self::wrapperKind()) {
            'bash' => "./bin/testkit run --rm -e TEST_MATCH='{$file}' testkit php runTest.php {$target}",
            'powershell' => ".\\bin\\testkit.ps1 run --rm -e TEST_MATCH='{$file}' testkit php runTest.php {$target}",
            default => "TEST_MATCH='{$file}' php runTest.php {$target}",
        };
    }


    public static function suite(string $target): string
    {
        return match (self::wrapperKind()) {
            'bash' => "./bin/testkit run --rm testkit php runTest.php {$target}",
            'powershell' => ".\\bin\\testkit.ps1 run --rm testkit php runTest.php {$target}",
            default => "php runTest.php {$target}",
        };
    }

    public static function trace(string $target): string
    {
        return match (self::wrapperKind()) {
            'bash' => "./bin/testkit run --rm -e TESTKIT_TRACE_MIGRATIONS=1 testkit php runTest.php {$target}",
            'powershell' => ".\\bin\\testkit.ps1 run --rm -e TESTKIT_TRACE_MIGRATIONS=1 testkit php runTest.php {$target}",
            default => "TESTKIT_TRACE_MIGRATIONS=1 php runTest.php {$target}",
        };
    }

    public static function listSelection(string $target): string
    {
        return match (self::wrapperKind()) {
            'bash' => "./bin/testkit run --rm testkit php runTest.php {$target} --list",
            'powershell' => ".\\bin\\testkit.ps1 run --rm testkit php runTest.php {$target} --list",
            default => "php runTest.php {$target} --list",
        };
    }

    public static function aggregateReport(): string
    {
        return match (self::wrapperKind()) {
            'bash' => './bin/testkit run --rm testkit php scripts/report.php',
            'powershell' => '.\bin\testkit.ps1 run --rm testkit php scripts/report.php',
            default => 'php scripts/report.php',
        };
    }

    public static function wrapperKind(): string
    {
        $kind = Env::string('TESTKIT_WRAPPER_KIND', '');
        return in_array($kind, ['bash', 'powershell', 'direct'], true) ? $kind : 'direct';
    }

    private static function shellSingleQuote(string $value): string
    {
        return str_replace("'", "'\\''", $value);
    }
}
