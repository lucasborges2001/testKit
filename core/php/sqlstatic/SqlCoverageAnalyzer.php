<?php
declare(strict_types=1);

namespace Testkit\Core\SqlStatic;

final class SqlCoverageAnalyzer
{
    private const SQL_STATEMENT_PATTERN = '/\b(?:SELECT|INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|SHOW)\b/i';

    /** @return array<int,array{rule_id:string,severity:string,confidence:string,line:int,call:string,reason:string,summary:string}> */
    public static function unresolved(string $path, string $content): array
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'sql') {
            return [];
        }
        $knownSqlVariables = self::knownSqlVariables($content);
        $externalVariables = self::externalVariables($content);
        $patterns = [
            '/->\s*(query|prepare|exec)\s*\(\s*([^,\r\n)]*)/i' => [1, 2],
            '/\b(base_db_query_all|base_db_query_one|base_db_exec)\s*\(\s*([^,\r\n)]*)/i' => [1, 2],
            '/\b(mysqli_query|mysqli_prepare)\s*\(\s*[^,\r\n]+,\s*([^,\r\n)]*)/i' => [1, 2],
        ];
        $findings = [];
        foreach ($patterns as $pattern => [$callGroup, $argGroup]) {
            $matches = [];
            preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $offset = (int)($match[0][1] ?? 0);
                if (preg_match('/\bfunction\s*$/i', substr($content, max(0, $offset - 40), 40)) === 1) {
                    continue;
                }
                $argument = (string)($match[$argGroup][0] ?? '');
                if ($argument === '' || preg_match(self::SQL_STATEMENT_PATTERN, $argument) === 1) {
                    continue;
                }
                $simpleVariable = trim($argument);
                if (isset($knownSqlVariables[$simpleVariable])) {
                    continue;
                }
                if (preg_match('/[$A-Za-z_]/', $argument) !== 1) {
                    continue;
                }
                $call = (string)($match[$callGroup][0] ?? 'sql_call');
                $reason = self::reason($content, $offset, $simpleVariable, $externalVariables);
                $findings[] = [
                    'rule_id' => 'dynamic_sql_unresolved',
                    'severity' => 'info',
                    'confidence' => 'medium',
                    'line' => 1 + substr_count(substr($content, 0, $offset), "\n"),
                    'call' => strtolower($call),
                    'reason' => $reason,
                    'summary' => match ($reason) {
                        'parameter_passthrough' => 'SQL execution receives a function parameter whose callers are outside local static reconstruction.',
                        'external_statement' => 'SQL execution receives a statement derived from external file content.',
                        default => 'SQL execution receives an expression that cannot be reconstructed as a literal SELECT.',
                    },
                ];
            }
        }
        return self::unique($findings);
    }

    /** @return array<string,bool> */
    private static function knownSqlVariables(string $content): array
    {
        $tokens = token_get_all($content);
        $known = [];
        for ($i = 0; $i < count($tokens); $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_VARIABLE) {
                continue;
            }
            $variable = (string)$tokens[$i][1];
            $equals = self::nextSignificantToken($tokens, $i + 1);
            if ($equals === null || $tokens[$equals] !== '=') {
                continue;
            }
            $value = self::nextSignificantToken($tokens, $equals + 1);
            if ($value === null || !is_array($tokens[$value]) || $tokens[$value][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            if (preg_match(self::SQL_STATEMENT_PATTERN, (string)$tokens[$value][1]) === 1) {
                $known[$variable] = true;
            }
        }
        return $known;
    }

    /** @return array<string,bool> */
    private static function externalVariables(string $content): array
    {
        $assignments = [];
        preg_match_all('/(\$[A-Za-z_][A-Za-z0-9_]*)\s*=\s*([^;]+);/s', $content, $assignments, PREG_SET_ORDER);
        $external = [];
        foreach ($assignments as $assignment) {
            if (preg_match('/\bfile_get_contents\s*\(/i', $assignment[2]) === 1) {
                $external[$assignment[1]] = true;
            }
        }

        do {
            $changed = false;
            foreach ($assignments as $assignment) {
                foreach ($external as $variable => $_) {
                    if (!isset($external[$assignment[1]]) && preg_match('/' . preg_quote($variable, '/') . '\b/', $assignment[2]) === 1) {
                        $external[$assignment[1]] = true;
                        $changed = true;
                    }
                }
            }
            $loops = [];
            preg_match_all('/\bforeach\s*\(\s*(\$[A-Za-z_][A-Za-z0-9_]*)\s+as\s+(?:\$[A-Za-z_][A-Za-z0-9_]*\s*=>\s*)?(\$[A-Za-z_][A-Za-z0-9_]*)\s*\)/', $content, $loops, PREG_SET_ORDER);
            foreach ($loops as $loop) {
                if (isset($external[$loop[1]]) && !isset($external[$loop[2]])) {
                    $external[$loop[2]] = true;
                    $changed = true;
                }
            }
        } while ($changed);

        return $external;
    }

    /** @param array<string,bool> $externalVariables */
    private static function reason(string $content, int $offset, string $argument, array $externalVariables): string
    {
        if (preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/', $argument) !== 1) {
            return 'dynamic_expression';
        }
        if (self::isFunctionParameter($content, $offset, $argument)) {
            return 'parameter_passthrough';
        }
        return isset($externalVariables[$argument]) ? 'external_statement' : 'dynamic_expression';
    }

    private static function isFunctionParameter(string $content, int $offset, string $variable): bool
    {
        $headers = [];
        preg_match_all(
            '/\bfunction\s+(?:&\s*)?[A-Za-z_][A-Za-z0-9_]*\s*\((.*?)\)\s*(?::\s*[^\{]+)?\{/s',
            substr($content, 0, $offset),
            $headers,
            PREG_SET_ORDER
        );
        $header = $headers === [] ? null : $headers[array_key_last($headers)];
        return is_array($header) && preg_match('/' . preg_quote($variable, '/') . '\b/', (string)$header[1]) === 1;
    }

    private static function nextSignificantToken(array $tokens, int $start): ?int
    {
        for ($i = $start; $i < count($tokens); $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $i;
        }
        return null;
    }

    /** @param array<int,array<string,mixed>> $findings */
    private static function unique(array $findings): array
    {
        $result = [];
        foreach ($findings as $finding) {
            $key = $finding['line'] . ':' . $finding['call'];
            $result[$key] = $finding;
        }
        return array_values($result);
    }
}
