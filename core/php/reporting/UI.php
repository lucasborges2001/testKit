<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Testkit\Core\Common\AgentMode;

final class UI
{
    private const RESET = "\033[0m";
    private const BOLD = "\033[1m";
    private const RED = "\033[31m";
    private const GREEN = "\033[32m";
    private const YELLOW = "\033[33m";
    private const CYAN = "\033[36m";
    private const GRAY = "\033[90m";

    public static function header(string $title): void
    {
        if (!self::supportsAnsi()) {
            echo "\n== {$title} ==\n";
            return;
        }

        echo "\n" . self::BOLD . "== {$title} ==" . self::RESET . "\n";
    }

    public static function section(string $title): void
    {
        if (!self::supportsAnsi()) {
            echo "\n[{$title}]\n";
            return;
        }

        echo "\n" . self::CYAN . "[{$title}]" . self::RESET . "\n";
    }

    public static function separator(int $len = 60): void
    {
        if (!self::supportsAnsi()) {
            echo str_repeat('-', $len) . "\n";
            return;
        }

        echo self::GRAY . str_repeat('-', $len) . self::RESET . "\n";
    }

    public static function label(string $label, string $value): void
    {
        if (!self::supportsAnsi()) {
            echo str_pad($label . ':', 12) . " " . $value . "\n";
            return;
        }

        echo str_pad($label . ':', 12) . " " . self::BOLD . $value . self::RESET . "\n";
    }

    public static function success(string $msg): string
    {
        return self::paint(self::GREEN, $msg);
    }

    public static function failure(string $msg): string
    {
        return self::paint(self::RED, $msg);
    }

    public static function warning(string $msg): string
    {
        return self::paint(self::YELLOW, $msg);
    }

    public static function info(string $msg): string
    {
        return self::paint(self::CYAN, $msg);
    }

    public static function gray(string $msg): string
    {
        return self::paint(self::GRAY, $msg);
    }

    public static function bold(string $msg): string
    {
        return self::paint(self::BOLD, $msg);
    }

    private static function supportsAnsi(): bool
    {
        if (AgentMode::isEnabled()) {
            return false;
        }

        $noColor = getenv('NO_COLOR');
        if (is_string($noColor) && trim($noColor) !== '') {
            return false;
        }

        return true;
    }

    private static function paint(string $prefix, string $msg): string
    {
        if (!self::supportsAnsi()) {
            return $msg;
        }

        return $prefix . $msg . self::RESET;
    }
}
