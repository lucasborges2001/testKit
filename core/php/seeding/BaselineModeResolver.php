<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

final class BaselineModeResolver
{
    public static function mode(): string
    {
        $mode = strtolower(trim((string)(getenv('TEST_BASELINE_MODE') ?: 'layered')));
        if (!in_array($mode, ['layered', 'snapshot'], true)) {
            return 'layered';
        }

        return $mode;
    }

    public static function reuseEnabled(): bool
    {
        return self::envBool('TEST_BASELINE_REUSE', false);
    }

    public static function invalidateRequested(): bool
    {
        return self::envBool('TEST_BASELINE_INVALIDATE', false);
    }

    private static function envBool(string $name, bool $default = false): bool
    {
        $raw = getenv($name);
        if ($raw === false) {
            return $default;
        }

        return in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
    }
}
