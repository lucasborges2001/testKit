<?php
declare(strict_types=1);

namespace Testkit\Core\Discovery;

use InvalidArgumentException;
use Testkit\Core\Common\Paths;

final class TestRootResolver
{
    /**
     * @param array<int,string> $rootPatterns
     * @param array<int,string> $excludeRootPatterns
     * @return array{roots:array<int,string>,warnings:array<int,array<string,mixed>>}
     */
    public static function resolve(string $repoRoot, array $rootPatterns, array $excludeRootPatterns = []): array
    {
        $repoRoot = self::canonicalRepoRoot($repoRoot);
        $excludeRoots = self::resolveExcludeRoots($repoRoot, $excludeRootPatterns);

        $roots = [];
        $warnings = [];
        foreach ($rootPatterns as $rawPattern) {
            $pattern = self::normalizeRelativeInput((string)$rawPattern, 'TK_BACK_PHP_TEST_ROOTS');
            if ($pattern === '') {
                continue;
            }

            $matches = self::expandPattern($repoRoot, $pattern);
            if ($matches === []) {
                $warnings[] = self::warning(
                    'TEST_ROOT_NOT_FOUND',
                    'test root inexistente ignorado: ' . $pattern,
                    'TK_BACK_PHP_TEST_ROOTS',
                    $pattern
                );
                continue;
            }

            foreach ($matches as $candidate) {
                $candidate = self::canonicalPath($candidate);
                self::assertInsideRepo($repoRoot, $candidate, 'TK_BACK_PHP_TEST_ROOTS');
                if (!is_dir($candidate)) {
                    $warnings[] = self::warning(
                        'TEST_ROOT_NOT_DIRECTORY',
                        'test root no es directorio y fue ignorado: ' . Paths::relativeToRepo($candidate),
                        'TK_BACK_PHP_TEST_ROOTS',
                        $pattern
                    );
                    continue;
                }
                if (self::isExcludedRoot($candidate, $excludeRoots)) {
                    continue;
                }
                $roots[$candidate] = $candidate;
            }
        }

        ksort($roots);
        return ['roots' => array_values($roots), 'warnings' => $warnings];
    }

    private static function canonicalRepoRoot(string $repoRoot): string
    {
        $real = realpath($repoRoot);
        if (!is_string($real) || $real === '') {
            throw new InvalidArgumentException('repo root inválido para discovery: ' . $repoRoot);
        }
        return Paths::normalize($real);
    }

    private static function normalizeRelativeInput(string $value, string $key): string
    {
        $value = trim(str_replace('\\', '/', $value));
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '/') || preg_match('/^[a-zA-Z]:\//', $value) === 1) {
            throw new InvalidArgumentException($key . ' no acepta paths absolutos: ' . $value);
        }

        $parts = array_values(array_filter(explode('/', $value), static fn(string $part): bool => $part !== '' && $part !== '.'));
        foreach ($parts as $part) {
            if ($part === '..') {
                throw new InvalidArgumentException($key . ' no puede escapar del repo con ../: ' . $value);
            }
        }

        return implode('/', $parts);
    }

    /** @return array<int,string> */
    private static function expandPattern(string $repoRoot, string $pattern): array
    {
        $absolutePattern = $repoRoot . '/' . $pattern;
        $hasGlob = strpbrk($pattern, '*?[') !== false;
        if (!$hasGlob) {
            return is_dir($absolutePattern) ? [$absolutePattern] : [];
        }

        $matches = glob($absolutePattern, GLOB_ONLYDIR) ?: [];
        sort($matches);
        return $matches;
    }

    /**
     * @param array<int,string> $excludeRootPatterns
     * @return array<int,string>
     */
    private static function resolveExcludeRoots(string $repoRoot, array $excludeRootPatterns): array
    {
        $out = [];
        foreach ($excludeRootPatterns as $rawPattern) {
            $pattern = self::normalizeRelativeInput((string)$rawPattern, 'TK_BACK_PHP_TEST_EXCLUDE_ROOTS');
            if ($pattern === '') {
                continue;
            }
            foreach (self::expandPattern($repoRoot, $pattern) as $candidate) {
                $candidate = self::canonicalPath($candidate);
                self::assertInsideRepo($repoRoot, $candidate, 'TK_BACK_PHP_TEST_EXCLUDE_ROOTS');
                if (is_dir($candidate)) {
                    $out[$candidate] = $candidate;
                }
            }
        }
        ksort($out);
        return array_values($out);
    }

    private static function canonicalPath(string $path): string
    {
        $real = realpath($path);
        if (is_string($real) && $real !== '') {
            return Paths::normalize($real);
        }
        return Paths::normalize($path);
    }

    private static function assertInsideRepo(string $repoRoot, string $path, string $key): void
    {
        $repoRoot = rtrim(Paths::normalize($repoRoot), '/');
        $path = Paths::normalize($path);
        if ($path !== $repoRoot && !str_starts_with($path, $repoRoot . '/')) {
            throw new InvalidArgumentException($key . ' resolvió fuera del repo: ' . $path);
        }
    }

    /** @param array<int,string> $excludeRoots */
    private static function isExcludedRoot(string $candidate, array $excludeRoots): bool
    {
        foreach ($excludeRoots as $excludeRoot) {
            if ($candidate === $excludeRoot || str_starts_with($candidate, rtrim($excludeRoot, '/') . '/')) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    private static function warning(string $code, string $summary, string $key, string $value): array
    {
        return [
            'severity' => 'WARN',
            'code' => $code,
            'summary' => $summary,
            'key' => $key,
            'received' => $value,
        ];
    }
}
