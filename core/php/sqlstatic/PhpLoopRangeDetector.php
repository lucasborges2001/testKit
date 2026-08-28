<?php
declare(strict_types=1);

namespace Testkit\Core\SqlStatic;

final class PhpLoopRangeDetector
{
    /** @return array<int,array{kind:string,start_line:int,end_line:int}> */
    public static function detect(string $content): array
    {
        $tokens = self::normalizedTokens($content);
        $ranges = [];
        foreach ($tokens as $index => $token) {
            if (!is_int($token['id']) || !in_array($token['id'], [T_FOR, T_FOREACH, T_WHILE, T_DO], true)) {
                continue;
            }
            $range = self::rangeFor($tokens, $index);
            if ($range !== null) {
                $ranges[] = $range;
            }
        }
        return self::unique($ranges);
    }

    /** @return array<int,array{id:int|string,text:string,line:int}> */
    private static function normalizedTokens(string $content): array
    {
        $result = [];
        $line = 1;
        foreach (token_get_all($content) as $token) {
            if (is_array($token)) {
                $result[] = ['id' => $token[0], 'text' => $token[1], 'line' => (int)$token[2]];
                $line = (int)$token[2] + substr_count($token[1], "\n");
            } else {
                $result[] = ['id' => $token, 'text' => $token, 'line' => $line];
                $line += substr_count($token, "\n");
            }
        }
        return $result;
    }

    /** @param array<int,array{id:int|string,text:string,line:int}> $tokens */
    private static function rangeFor(array $tokens, int $index): ?array
    {
        $id = $tokens[$index]['id'];
        $kind = match ($id) {
            T_FOR => 'for', T_FOREACH => 'foreach', T_WHILE => 'while', T_DO => 'do', default => 'loop',
        };
        $body = $id === T_DO ? self::nextSignificant($tokens, $index + 1) : self::afterHeader($tokens, $index + 1);
        if ($body === null) {
            return null;
        }
        $end = self::bodyEnd($tokens, $body);
        if ($end === null) {
            return null;
        }
        return ['kind' => $kind, 'start_line' => $tokens[$index]['line'], 'end_line' => $tokens[$end]['line']];
    }

    /** @param array<int,array{id:int|string,text:string,line:int}> $tokens */
    private static function afterHeader(array $tokens, int $index): ?int
    {
        $open = self::findText($tokens, $index, '(');
        if ($open === null) {
            return null;
        }
        $depth = 0;
        for ($i = $open; $i < count($tokens); $i++) {
            if ($tokens[$i]['text'] === '(') {
                $depth++;
            } elseif ($tokens[$i]['text'] === ')' && --$depth === 0) {
                return self::nextSignificant($tokens, $i + 1);
            }
        }
        return null;
    }

    /** @param array<int,array{id:int|string,text:string,line:int}> $tokens */
    private static function bodyEnd(array $tokens, int $body): ?int
    {
        if ($tokens[$body]['text'] !== '{') {
            return self::findText($tokens, $body, ';');
        }
        $depth = 0;
        for ($i = $body; $i < count($tokens); $i++) {
            if ($tokens[$i]['text'] === '{') {
                $depth++;
            } elseif ($tokens[$i]['text'] === '}' && --$depth === 0) {
                return $i;
            }
        }
        return null;
    }

    /** @param array<int,array{id:int|string,text:string,line:int}> $tokens */
    private static function nextSignificant(array $tokens, int $index): ?int
    {
        for ($i = $index; $i < count($tokens); $i++) {
            $id = $tokens[$i]['id'];
            if (is_int($id) && in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $i;
        }
        return null;
    }

    /** @param array<int,array{id:int|string,text:string,line:int}> $tokens */
    private static function findText(array $tokens, int $index, string $text): ?int
    {
        for ($i = $index; $i < count($tokens); $i++) {
            if ($tokens[$i]['text'] === $text) {
                return $i;
            }
        }
        return null;
    }

    /** @param array<int,array{kind:string,start_line:int,end_line:int}> $ranges */
    private static function unique(array $ranges): array
    {
        $result = [];
        foreach ($ranges as $range) {
            $result[$range['kind'] . ':' . $range['start_line'] . ':' . $range['end_line']] = $range;
        }
        return array_values($result);
    }
}
