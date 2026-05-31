<?php
declare(strict_types=1);

namespace Testkit\Core\Discovery;

final class TestPatternMatcher
{
    /**
     * @param array<int,string> $patterns
     * @return array<int,string>
     */
    public static function normalizePatterns(array $patterns, array $default = ['*.test.php']): array
    {
        $out = [];
        foreach ($patterns as $pattern) {
            $pattern = trim(str_replace('\\', '/', (string)$pattern));
            if ($pattern === '') {
                continue;
            }
            $out[] = $pattern;
        }

        if ($out === []) {
            $out = $default;
        }

        return array_values(array_unique($out));
    }

    /**
     * Convert legacy suffix extensions into fnmatch patterns.
     *
     * @param array<int,string> $extensions
     * @return array<int,string>
     */
    public static function extensionsToPatterns(array $extensions): array
    {
        $patterns = [];
        foreach ($extensions as $extension) {
            $extension = trim((string)$extension);
            if ($extension === '') {
                continue;
            }
            $patterns[] = str_starts_with($extension, '*') ? $extension : ('*' . $extension);
        }

        return self::normalizePatterns($patterns);
    }

    /**
     * @param array<int,string> $patterns
     */
    public static function matches(string $repoRelativePath, array $patterns): bool
    {
        $rel = ltrim(str_replace('\\', '/', $repoRelativePath), '/');
        $basename = basename($rel);

        foreach (self::normalizePatterns($patterns) as $pattern) {
            $pattern = str_replace('\\', '/', $pattern);
            if (fnmatch($pattern, $basename, FNM_CASEFOLD)) {
                return true;
            }
            if (fnmatch($pattern, $rel, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }
}
