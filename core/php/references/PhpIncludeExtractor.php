<?php
declare(strict_types=1);

namespace Testkit\Core\References;

final class PhpIncludeExtractor
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function extract(string $source): array
    {
        $tokens = token_get_all($source);
        $directives = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }

            $tokenId = $token[0];
            if (!in_array($tokenId, [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
                continue;
            }

            $statement = strtolower($token[1]);
            $line = (int)$token[2];
            $exprTokens = [];
            $depth = 0;

            for ($j = $i + 1; $j < $count; $j++) {
                $part = $tokens[$j];
                if ($part === '(') {
                    $depth++;
                    $exprTokens[] = $part;
                    continue;
                }
                if ($part === ')') {
                    $depth = max(0, $depth - 1);
                    $exprTokens[] = $part;
                    continue;
                }
                if ($part === ';' && $depth === 0) {
                    break;
                }
                $exprTokens[] = $part;
            }

            $directives[] = [
                'statement' => $statement,
                'line' => $line,
                'expression' => self::tokensToString($exprTokens),
                'tokens' => $exprTokens,
            ];
        }

        return $directives;
    }

    /**
     * @param array<int,mixed> $tokens
     */
    private static function tokensToString(array $tokens): string
    {
        $text = '';
        foreach ($tokens as $token) {
            $text .= is_array($token) ? (string)$token[1] : (string)$token;
        }

        return trim(preg_replace('/\s+/', ' ', $text) ?: $text);
    }
}
