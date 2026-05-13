<?php
declare(strict_types=1);

namespace Testkit\Core\References;

use Testkit\Core\Common\Paths;

final class PhpIncludeResolver
{
    /**
     * @param array<string,mixed> $directive
     * @return array{resolved:bool,dynamic:bool,reference:string,literal_reference:string,resolved_path:string,resolved_as:string,expression:string}
     */
    public function resolve(array $directive, string $includingFile, string $repoRoot): array
    {
        $tokens = is_array($directive['tokens'] ?? null) ? $directive['tokens'] : [];
        $includingDir = dirname($includingFile);
        $expression = trim((string)($directive['expression'] ?? ''));

        $parts = [];
        $stringParts = [];
        $hasPathAnchor = false;

        foreach ($tokens as $token) {
            if (is_array($token)) {
                $id = $token[0];
                $text = (string)$token[1];

                if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                if ($id === T_CONSTANT_ENCAPSED_STRING) {
                    $value = self::decodeQuotedString($text);
                    $parts[] = $value;
                    $stringParts[] = $value;
                    continue;
                }

                if ($id === T_DIR) {
                    $parts[] = $includingDir;
                    $hasPathAnchor = true;
                    continue;
                }

                return self::dynamic($expression);
            }

            $text = trim((string)$token);
            if ($text === '' || $text === '.' || $text === '(' || $text === ')') {
                continue;
            }

            return self::dynamic($expression);
        }

        if ($parts === []) {
            return self::dynamic($expression);
        }

        $path = implode('', $parts);
        if ($path === '') {
            return self::dynamic($expression);
        }

        $absolutePath = self::isAbsolute($path)
            ? self::normalizeLexical($path)
            : self::normalizeLexical($includingDir . '/' . $path);

        $literalReference = count($stringParts) === 1
            ? $stringParts[0]
            : implode('', $stringParts);

        return [
            'resolved' => true,
            'dynamic' => false,
            'reference' => $literalReference !== '' ? $literalReference : ($expression !== '' ? $expression : $path),
            'literal_reference' => $literalReference,
            'resolved_path' => $absolutePath,
            'resolved_as' => Paths::relativeToRepo($absolutePath),
            'expression' => $expression,
        ];
    }

    /**
     * @return array{resolved:bool,dynamic:bool,reference:string,literal_reference:string,resolved_path:string,resolved_as:string,expression:string}
     */
    private static function dynamic(string $expression): array
    {
        return [
            'resolved' => false,
            'dynamic' => true,
            'reference' => $expression,
            'literal_reference' => '',
            'resolved_path' => '',
            'resolved_as' => '',
            'expression' => $expression,
        ];
    }

    private static function decodeQuotedString(string $literal): string
    {
        $literal = trim($literal);
        if (strlen($literal) < 2) {
            return $literal;
        }

        $quote = $literal[0];
        $body = substr($literal, 1, -1);
        if ($quote === "'") {
            return str_replace(["\\\\", "\\'"], ["\\", "'"], $body);
        }

        return stripcslashes($body);
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private static function normalizeLexical(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = '';
        if (preg_match('/^[A-Za-z]:\//', $path) === 1) {
            $prefix = substr($path, 0, 2);
            $path = substr($path, 2);
        }

        $absolute = str_starts_with($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts !== [] && end($parts) !== '..') {
                    array_pop($parts);
                    continue;
                }
                if (!$absolute) {
                    $parts[] = '..';
                }
                continue;
            }
            $parts[] = $part;
        }

        $normalized = ($absolute ? '/' : '') . implode('/', $parts);
        if ($prefix !== '') {
            $normalized = $prefix . $normalized;
        }

        return rtrim($normalized, '/') ?: ($absolute ? '/' : '.');
    }
}
