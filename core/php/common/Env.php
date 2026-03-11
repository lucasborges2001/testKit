<?php
declare(strict_types=1);

namespace Testkit\Core\Common;

final class Env
{
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

        return $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = getenv($key);
        if ($value === false || trim((string)$value) === '') {
            return $default;
        }
        if (!is_numeric($value)) {
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
}
