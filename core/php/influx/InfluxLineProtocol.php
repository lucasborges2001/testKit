<?php
declare(strict_types=1);

namespace Testkit\Core\Influx;

use RuntimeException;

final class InfluxLineProtocol
{
    /**
     * @param array<string,string|int|float|bool> $tags
     * @param array<string,string|int|float|bool> $fields
     */
    public static function point(string $measurement, array $tags, array $fields, ?int $timestamp = null): string
    {
        $measurement = self::escapeKey($measurement);

        $tagParts = [];
        ksort($tags);
        foreach ($tags as $key => $value) {
            $tagParts[] = self::escapeKey((string) $key) . '=' . self::escapeTagValue((string) $value);
        }

        if ($fields === []) {
            throw new RuntimeException('Influx point requiere al menos un field.');
        }

        $fieldParts = [];
        ksort($fields);
        foreach ($fields as $key => $value) {
            $fieldParts[] = self::escapeKey((string) $key) . '=' . self::formatFieldValue($value);
        }

        $line = $measurement;
        if ($tagParts !== []) {
            $line .= ',' . implode(',', $tagParts);
        }
        $line .= ' ' . implode(',', $fieldParts);

        if ($timestamp !== null) {
            $line .= ' ' . $timestamp;
        }

        return $line;
    }

    /**
     * @param array<int,string> $lines
     */
    public static function join(array $lines): string
    {
        return implode("\n", array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''));
    }

    private static function escapeKey(string $value): string
    {
        return str_replace(
            ['\\', ' ', ',', '='],
            ['\\\\', '\\ ', '\\,', '\\='],
            $value
        );
    }

    private static function escapeTagValue(string $value): string
    {
        return self::escapeKey($value);
    }

    private static function formatFieldValue(string|int|float|bool $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return $value . 'i';
        }

        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
        }

        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        return '"' . $escaped . '"';
    }
}
