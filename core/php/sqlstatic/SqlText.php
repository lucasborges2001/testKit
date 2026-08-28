<?php
declare(strict_types=1);

namespace Testkit\Core\SqlStatic;

final class SqlText
{
    public static function inspectable(string $sql): string
    {
        $sql = preg_replace('~/\*.*?\*/~s', ' ', $sql) ?? $sql;
        $sql = preg_replace('/--[^\r\n]*/', ' ', $sql) ?? $sql;
        $sql = preg_replace('/#[^\r\n]*/', ' ', $sql) ?? $sql;
        return trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);
    }

    public static function sample(string $sql, int $maxLength = 320): string
    {
        $sql = self::inspectable($sql);
        $sql = preg_replace("/'(?:''|\\\\.|[^'])*'/s", '?', $sql) ?? $sql;
        $sql = preg_replace('/"(?:""|\\\\.|[^"])*"/s', '?', $sql) ?? $sql;
        $sql = preg_replace('/\b(?:0x[0-9a-f]+|\d+(?:\.\d+)?)\b/i', '?', $sql) ?? $sql;
        $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;
        $sql = trim($sql);

        if (strlen($sql) > $maxLength) {
            return substr($sql, 0, max(0, $maxLength - 3)) . '...';
        }
        return $sql;
    }

    public static function fingerprint(string $sql): string
    {
        return strtolower(self::sample($sql, 2000));
    }
}
