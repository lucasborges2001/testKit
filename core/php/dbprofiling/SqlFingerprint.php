<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling;

final class SqlFingerprint
{
    public static function fingerprint(string $sql): string
    {
        $sql = self::sanitize($sql, true);
        $sql = preg_replace('/\bin\s*\((?:\s*\?\s*,)*\s*\?\s*\)/i', 'in (?)', $sql) ?? $sql;
        return strtolower($sql);
    }

    public static function sampleSql(string $sql, int $maxLength = 2000): string
    {
        $sample = self::sanitize($sql, false);
        $maxLength = max(120, $maxLength);
        if (strlen($sample) > $maxLength) {
            return substr($sample, 0, $maxLength - 3) . '...';
        }
        return $sample;
    }

    public static function isExplainable(string $sql): bool
    {
        $sql = trim(self::stripComments($sql));
        if ($sql === '') {
            return false;
        }
        if (self::containsMultipleStatements($sql)) {
            return false;
        }
        if (self::hasUnboundPlaceholders($sql)) {
            return false;
        }
        return preg_match('/^\s*(select|with)\b/i', $sql) === 1;
    }

    public static function containsMultipleStatements(string $sql): bool
    {
        $sql = trim($sql);
        if ($sql === '') {
            return false;
        }
        $stripped = self::replaceQuotedStrings($sql);
        $stripped = rtrim($stripped);
        if (str_ends_with($stripped, ';')) {
            $stripped = rtrim(substr($stripped, 0, -1));
        }
        return str_contains($stripped, ';');
    }

    public static function hasUnboundPlaceholders(string $sql): bool
    {
        $stripped = self::replaceQuotedStrings($sql);
        if (str_contains($stripped, '?')) {
            return true;
        }
        return preg_match('/(?<!:):(?!:)[a-z_][a-z0-9_]*/i', $stripped) === 1;
    }

    private static function sanitize(string $sql, bool $collapseIn): string
    {
        $sql = self::stripComments($sql);
        $sql = self::replaceQuotedStrings($sql);
        $sql = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i', '?', $sql) ?? $sql;
        $sql = preg_replace('/\b\d{4}-\d{2}-\d{2}(?:[ t]\d{2}:\d{2}:\d{2}(?:\.\d+)?)?\b/i', '?', $sql) ?? $sql;
        $sql = preg_replace('/\b0x[0-9a-f]+\b/i', '?', $sql) ?? $sql;
        $sql = preg_replace('/\b(true|false|null)\b/i', '?', $sql) ?? $sql;
        $sql = preg_replace('/(?<![a-zA-Z_])[-+]?\d+(?:\.\d+)?(?:e[-+]?\d+)?(?![a-zA-Z_])/i', '?', $sql) ?? $sql;
        if ($collapseIn) {
            $sql = preg_replace('/\bin\s*\((?:\s*\?\s*,)*\s*\?\s*\)/i', 'in (?)', $sql) ?? $sql;
        }
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
        return $sql;
    }

    private static function stripComments(string $sql): string
    {
        $sql = preg_replace('#/\*.*?\*/#s', ' ', $sql) ?? $sql;
        $sql = preg_replace('/--[^\r\n]*/', ' ', $sql) ?? $sql;
        $sql = preg_replace('/#[^\r\n]*/', ' ', $sql) ?? $sql;
        return $sql;
    }

    private static function replaceQuotedStrings(string $sql): string
    {
        $sql = preg_replace("/'(?:''|\\\\'|[^'])*'/s", '?', $sql) ?? $sql;
        $sql = preg_replace('/"(?:""|\\\\"|[^"])*"/s', '?', $sql) ?? $sql;
        return $sql;
    }
}
