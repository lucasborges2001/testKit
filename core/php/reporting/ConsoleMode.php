<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

final class ConsoleMode
{
    public const COMPACT = 'compact';
    public const LIVE = 'live';

    public static function current(): string
    {
        $value = strtolower(trim((string)(getenv('TESTKIT_CONSOLE_MODE') ?: self::COMPACT)));
        return in_array($value, [self::COMPACT, self::LIVE], true) ? $value : self::COMPACT;
    }

    public static function isCompact(): bool
    {
        return self::current() === self::COMPACT;
    }

    private function __construct()
    {
    }
}
