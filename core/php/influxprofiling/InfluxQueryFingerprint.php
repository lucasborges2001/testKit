<?php
declare(strict_types=1);

namespace Testkit\Core\InfluxProfiling;

final class InfluxQueryFingerprint
{
    public static function fingerprint(string $query, string $dialect = 'flux'): string
    {
        $query = self::sanitize($query);
        if ($query === '') {
            return '';
        }

        $query = self::replaceLiterals($query);
        $query = preg_replace('/\s*\|>\s*/', ' |> ', $query) ?? $query;
        $query = preg_replace('/\s*,\s*/', ', ', $query) ?? $query;
        $query = preg_replace('/\s+/', ' ', trim($query)) ?? trim($query);
        $query = preg_replace('/\(\s+/', '(', $query) ?? $query;
        $query = preg_replace('/\s+\)/', ')', $query) ?? $query;

        if (strtolower($dialect) === 'influxql') {
            $query = strtolower($query);
        }

        return trim($query);
    }

    public static function sampleQuery(string $query, int $maxLength = 4000): string
    {
        $sample = self::fingerprint($query);
        if ($sample === '') {
            return '';
        }
        if (strlen($sample) > $maxLength) {
            return substr($sample, 0, max(0, $maxLength - 3)) . '...';
        }
        return $sample;
    }

    private static function sanitize(string $query): string
    {
        $query = str_replace("\0", '', $query);
        $query = preg_replace('/https?:\/\/[^\s\)\]\}\"\']+/i', '?', $query) ?? $query;
        $query = preg_replace('/\b(token|authorization|password|passwd|secret|api[_-]?key)\s*[:=]\s*[^\s,\)]+/i', '$1: ?', $query) ?? $query;
        return trim($query);
    }

    private static function replaceLiterals(string $query): string
    {
        $query = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i', '?', $query) ?? $query;
        $query = preg_replace('/\b\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z?\b/i', '?', $query) ?? $query;
        $query = preg_replace('/\b\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}\b/', '?', $query) ?? $query;
        $query = preg_replace('/\bnow\(\)\s*-\s*\d+(?:\.\d+)?\s*[smhdw]\b/i', '?', $query) ?? $query;
        $query = preg_replace('/(?<![A-Za-z0-9_])-?\d+(?:\.\d+)?\s*(?:ns|us|µs|ms|s|m|h|d|w|mo|y)\b/i', '?', $query) ?? $query;
        $query = self::replaceQuotedStrings($query);
        $query = preg_replace('/\/(?:\\\\.|[^\/\\\\])+\/[a-z]*/i', '?', $query) ?? $query;
        $query = preg_replace('/\b(?:true|false)\b/i', '?', $query) ?? $query;
        $query = preg_replace('/(?<![A-Za-z0-9_])-?\d+(?:\.\d+)?(?:e[+-]?\d+)?\b/i', '?', $query) ?? $query;
        return $query;
    }

    private static function replaceQuotedStrings(string $query): string
    {
        $out = '';
        $len = strlen($query);
        for ($i = 0; $i < $len; $i++) {
            $ch = $query[$i];
            if ($ch !== '"' && $ch !== "'") {
                $out .= $ch;
                continue;
            }
            $quote = $ch;
            $out .= '?';
            $i++;
            while ($i < $len) {
                if ($query[$i] === '\\') {
                    $i += 2;
                    continue;
                }
                if ($query[$i] === $quote) {
                    break;
                }
                $i++;
            }
        }
        return $out;
    }
}
