<?php
declare(strict_types=1);

namespace Testkit\Core\SqlStatic;

final class SqlQueryExtractor
{
    /** @return array<int,array{sql:string,line:int}> */
    public static function extract(string $path, string $content): array
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'sql'
            ? self::fromSql($content)
            : self::fromPhp($content);
    }

    /** @return array<int,array{sql:string,line:int}> */
    private static function fromSql(string $content): array
    {
        $matches = [];
        preg_match_all('/\bSELECT\b.*?(?=;|\z)/is', $content, $matches, PREG_OFFSET_CAPTURE);
        $queries = [];
        foreach ($matches[0] ?? [] as $match) {
            [$sql, $offset] = $match;
            if (!is_string($sql) || trim($sql) === '') {
                continue;
            }
            $queries[] = [
                'sql' => trim($sql),
                'line' => 1 + substr_count(substr($content, 0, (int)$offset), "\n"),
            ];
        }
        return $queries;
    }

    /** @return array<int,array{sql:string,line:int}> */
    private static function fromPhp(string $content): array
    {
        $tokens = token_get_all($content);
        $queries = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $fragment = self::stringFragment($tokens[$i]);
            if ($fragment === null || preg_match('/\bSELECT\b/i', $fragment['text']) !== 1) {
                continue;
            }
            [$sql, $end] = self::collectExpression($tokens, $i);
            if (preg_match('/\bSELECT\b/i', $sql) === 1) {
                $queries[] = ['sql' => trim($sql), 'line' => $fragment['line']];
            }
            $i = max($i, $end);
        }

        return self::deduplicate($queries);
    }

    /** @return array{0:string,1:int} */
    private static function collectExpression(array $tokens, int $start): array
    {
        $parts = [];
        $depth = 0;
        $count = count($tokens);
        $end = $start;

        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];
            $end = $i;
            if (is_string($token)) {
                if (in_array($token, ['(', '[', '{'], true)) {
                    $depth++;
                    continue;
                }
                if (in_array($token, [')', ']', '}'], true)) {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                    continue;
                }
                if ($depth === 0 && in_array($token, [',', ';'], true)) {
                    break;
                }
                if ($token === '.') {
                    continue;
                }
                continue;
            }

            $fragment = self::stringFragment($token);
            if ($fragment !== null) {
                $parts[] = $fragment['text'];
                continue;
            }

            $id = $token[0];
            if (in_array($id, [T_WHITESPACE, T_START_HEREDOC, T_END_HEREDOC], true)) {
                continue;
            }
            if (in_array($id, [T_VARIABLE, T_STRING, T_LNUMBER, T_DNUMBER], true)) {
                $parts[] = ' ? ';
            }
        }

        return [implode('', $parts), $end];
    }

    /** @return array{text:string,line:int}|null */
    private static function stringFragment(mixed $token): ?array
    {
        if (!is_array($token)) {
            return null;
        }
        [$id, $text, $line] = $token;
        if ($id === T_CONSTANT_ENCAPSED_STRING) {
            return ['text' => self::unquote($text), 'line' => (int)$line];
        }
        if ($id === T_ENCAPSED_AND_WHITESPACE) {
            return ['text' => $text, 'line' => (int)$line];
        }
        return null;
    }

    private static function unquote(string $text): string
    {
        if (strlen($text) < 2) {
            return $text;
        }
        $quote = $text[0];
        $body = substr($text, 1, -1);
        return $quote === "'"
            ? str_replace(["\\\\", "\\'"], ["\\", "'"], $body)
            : stripcslashes($body);
    }

    /** @param array<int,array{sql:string,line:int}> $queries */
    private static function deduplicate(array $queries): array
    {
        $seen = [];
        $result = [];
        foreach ($queries as $query) {
            $key = $query['line'] . '|' . SqlText::fingerprint($query['sql']);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $query;
            }
        }
        return $result;
    }
}
