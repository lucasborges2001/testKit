<?php
declare(strict_types=1);

namespace Testkit\Core\Common;

final class Env
{
    /** @var array<int,array<string,mixed>> */
    private static array $warnings = [];

    public static function string(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        $value = trim((string)$value);
        return $value === '' ? $default : $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = getenv($key);
        if ($value === false || trim((string)$value) === '') {
            return $default;
        }

        $normalized = strtolower(trim((string)$value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        self::recordWarning(
            code: 'INVALID_ENV_BOOL',
            key: $key,
            received: (string)$value,
            expected: '1|0|true|false|yes|no|on|off',
            defaultApplied: $default ? 'true' : 'false'
        );

        return $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = getenv($key);
        if ($value === false || trim((string)$value) === '') {
            return $default;
        }
        if (!is_numeric($value)) {
            self::recordWarning(
                code: 'INVALID_ENV_INT',
                key: $key,
                received: (string)$value,
                expected: 'integer',
                defaultApplied: (string)$default
            );
            return $default;
        }
        return (int)$value;
    }

    /**
     * @return array<int,string>
     */
    public static function csv(string $key, string $default = ''): array
    {
        $raw = self::string($key, $default);
        if ($raw === '') {
            return [];
        }

        $values = array_map(
            static fn(string $item): string => trim($item),
            explode(',', $raw)
        );

        return array_values(array_filter($values, static fn(string $item): bool => $item !== ''));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function drainWarnings(): array
    {
        $warnings = self::$warnings;
        self::$warnings = [];
        return $warnings;
    }

    public static function resetWarnings(): void
    {
        self::$warnings = [];
    }

    private static function recordWarning(
        string $code,
        string $key,
        string $received,
        string $expected,
        string $defaultApplied
    ): void {
        self::$warnings[] = [
            'severity' => 'WARN',
            'code' => $code,
            'summary' => sprintf(
                '%s=%s inválido; se aplica default=%s',
                $key,
                $received,
                $defaultApplied
            ),
            'key' => $key,
            'received' => $received,
            'expected' => $expected,
            'default_applied' => $defaultApplied,
        ];
    }
}
