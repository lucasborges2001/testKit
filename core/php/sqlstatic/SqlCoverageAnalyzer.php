<?php
declare(strict_types=1);

namespace Testkit\Core\SqlStatic;

final class SqlCoverageAnalyzer
{
    private const SQL_STATEMENT_PATTERN = '/\b(?:SELECT|INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|SHOW)\b/i';

    /** @return array<int,array{rule_id:string,severity:string,confidence:string,line:int,call:string,reason:string}> */
    public static function unresolved(string $path, string $content): array
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'sql') {
            return [];
        }
        $knownSqlVariables = self::knownSqlVariables($content);
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
                $offset = (int)($match[0][1] ?? 0);
                $call = (string)($match[$callGroup][0] ?? 'sql_call');
                $findings[] = [
                    'rule_id' => 'dynamic_sql_unresolved',
                    'severity' => 'info',
                    'confidence' => 'medium',
                    'line' => 1 + substr_count(substr($content, 0, $offset), "\n"),
                    'call' => strtolower($call),
                    'reason' => 'SQL execution receives an expression that cannot be reconstructed as a literal SELECT.',
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
