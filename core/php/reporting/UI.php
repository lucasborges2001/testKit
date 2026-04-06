<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

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
        echo "\n" . self::BOLD . "== {$title} ==" . self::RESET . "\n";
    }

    public static function section(string $title): void
    {
        echo "\n" . self::CYAN . "[{$title}]" . self::RESET . "\n";
    }

    public static function separator(int $len = 60): void
    {
        echo self::GRAY . str_repeat('-', $len) . self::RESET . "\n";
    }

    public static function label(string $label, string $value): void
    {
        echo str_pad($label . ':', 12) . " " . self::BOLD . $value . self::RESET . "\n";
    }

    public static function success(string $msg): string
    {
        return self::GREEN . $msg . self::RESET;
    }

    public static function failure(string $msg): string
    {
        return self::RED . $msg . self::RESET;
    }

    public static function warning(string $msg): string
    {
        return self::YELLOW . $msg . self::RESET;
    }

    public static function info(string $msg): string
    {
        return self::CYAN . $msg . self::RESET;
    }

    public static function gray(string $msg): string
    {
        return self::GRAY . $msg . self::RESET;
    }

    public static function bold(string $msg): string
    {
        return self::BOLD . $msg . self::RESET;
    }
}
