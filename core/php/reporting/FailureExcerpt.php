<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

final class FailureExcerpt
{
    public static function extractFirstMessage(string $text): ?string
    {
        if ($text === '') {
            return null;
        }

        $lines = self::normalizedLines($text);
        if ($lines === []) {
            return null;
        }

        foreach ($lines as $line) {
            if (preg_match('/^\[FAIL\].+/i', $line)) {
                return substr($line, 0, 200);
            }
        }

        foreach ($lines as $line) {
            if (preg_match('/^(FAIL|ERROR):\s+.+/i', $line)) {
                return substr($line, 0, 200);
            }
        }

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = $lines[$i];
            if (preg_match('/^(Assertion(?:Error|FailedError)?|TypeError|ValueError|RuntimeError|KeyError|IndexError|AttributeError|ImportError|ModuleNotFoundError|LookupError|OSError|Exception):\s*.+/i', $line)) {
                return substr($line, 0, 200);
            }
            if (preg_match('/^[A-Za-z_\\\\]+(?:Error|Exception):\s*.+/', $line)) {
                return substr($line, 0, 200);
            }
        }

        foreach ($lines as $line) {
            if (self::isNoiseMessageLine($line)) {
                continue;
            }
            return substr($line, 0, 200);
        }

        return substr($lines[0], 0, 200);
    }

    public static function extractTrace(string $text, int $maxLines): ?string
    {
        if ($text === '') {
            return null;
        }

        $traceLines = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $trimmed = rtrim($line);
            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/^\s*(#\d+|Stack trace:|at\s+|Traceback \(most recent call last\):|File ".*", line \d+|[A-Za-z_\\\\]+(?:Error|Exception):|\w.*\.(php|mjs|js|ts|py):\d+)/', $trimmed)) {
                $traceLines[] = $trimmed;
            }
        }

        if ($traceLines === []) {
            return null;
        }

        return implode("\n", array_slice($traceLines, 0, $maxLines));
    }

    public static function textExcerpt(string $text, int $maxLines): ?string
    {
        if ($text === '') {
            return null;
        }

        $lines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $text) ?: [],
            static fn(string $line): bool => trim($line) !== ''
        ));
        if ($lines === []) {
            return null;
        }

        return implode("\n", array_slice($lines, 0, $maxLines));
    }

    /**
     * @return array<int,string>
     */
    public static function traceToLines(string $text, int $maxLines): array
    {
        if (trim($text) === '') {
            return [];
        }

        $lines = preg_split('/\R/', $text) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''));
        return array_slice($lines, 0, $maxLines);
    }

    /**
     * @return array<int,string>
     */
    private static function normalizedLines(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $lines = array_map(static fn(string $line): string => trim($line), $lines);
        return array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
    }

    private static function isNoiseMessageLine(string $line): bool
    {
        if ($line === '') {
            return true;
        }

        return (bool)preg_match(
            '/^(#\d+\s|Stack trace:|at\s+|Traceback \(most recent call last\):|File ".*", line \d+, in |-+|=+|Ran \d+ tests? in |OK$|FAILED \(.+\)$|\w.*\.(php|mjs|js|ts|py):\d+$|test[\w\.\(\)_ ]+\.\.\.\s+(ok|FAIL|ERROR|skipped.*))$/i',
            $line
        );
    }
}
