<?php
declare(strict_types=1);

namespace Testkit\Core\Discovery;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Testkit\Core\Common\Paths;

final class TestDiscovery
{
    /**
     * Legacy wrapper. Kept for existing suites/integrators.
     *
     * @param array<int,string> $extensions
     * @param array<string,mixed> $config
     * @return array<int,array<string,mixed>>
     */
    public static function discover(string $testsDir, array $extensions, array $config): array
    {
        return self::discoverMany(
            [$testsDir],
            TestPatternMatcher::extensionsToPatterns($extensions),
            $config,
            []
        );
    }

    /**
     * @param array<int,string> $roots
     * @param array<int,string> $patterns
     * @param array<string,mixed> $config
     * @param array<string,mixed> $options
     * @return array<int,array<string,mixed>>
     */
    public static function discoverMany(array $roots, array $patterns, array $config, array $options = []): array
    {
        $scope = (string)($config['scope'] ?? 'all');
        $category = (string)($config['category'] ?? 'all');
        $match = strtolower((string)($config['match'] ?? ''));
        $scanLines = (int)($config['metadata_lines'] ?? 60);
        $tagsFromFilename = (bool)($config['tags_from_filename'] ?? true);
        $moduleLevel = max(1, (int)($config['module_level'] ?? 2));
        $tagMap = (string)($config['tag_map'] ?? '');
        $patterns = TestPatternMatcher::normalizePatterns($patterns);
        $excludePatterns = TestPatternMatcher::normalizePatterns(
            is_array($options['exclude_patterns'] ?? null) ? $options['exclude_patterns'] : [],
            []
        );

        $roots = self::normalizeRoots($roots);
        if ($roots === []) {
            return [];
        }

        $out = [];
        $seenFiles = [];
        foreach ($roots as $testsDir) {
            if (!is_dir($testsDir)) {
                continue;
            }

            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsDir));
            foreach ($it as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }

                $fullPath = Paths::normalize($file->getPathname());
                if (isset($seenFiles[$fullPath])) {
                    continue;
                }
                $seenFiles[$fullPath] = true;

                if (self::shouldIgnore($fullPath)) {
                    continue;
                }

                $rel = Paths::relativeToRepo($fullPath);
                if ($excludePatterns !== [] && TestPatternMatcher::matches($rel, $excludePatterns)) {
                    continue;
                }
                if (!TestPatternMatcher::matches($rel, $patterns)) {
                    continue;
                }

                if ($match !== '' && stripos($rel, $match) === false) {
                    continue;
                }

                $tags = TestTagger::tagsFor($fullPath, $scanLines, $tagsFromFilename, $tagMap);

                if (!self::scopeMatch($rel, $tags, $scope)) {
                    continue;
                }
                if (!self::categoryMatch($tags, $category)) {
                    continue;
                }

                $out[] = [
                    'file' => $fullPath,
                    'rel' => $rel,
                    'module' => self::moduleFromRel($rel, $moduleLevel),
                    'tags' => $tags,
                ];
            }
        }

        usort(
            $out,
            static fn(array $a, array $b): int => strcmp((string)$a['rel'], (string)$b['rel'])
        );

        return $out;
    }

    /** @param array<int,string> $roots @return array<int,string> */
    private static function normalizeRoots(array $roots): array
    {
        $out = [];
        foreach ($roots as $root) {
            $root = Paths::normalize((string)$root);
            if ($root === '') {
                continue;
            }
            $real = realpath($root);
            $root = is_string($real) && $real !== '' ? Paths::normalize($real) : $root;
            $out[$root] = $root;
        }
        ksort($out);
        return array_values($out);
    }

    private static function shouldIgnore(string $file): bool
    {
        $normalized = strtolower(str_replace('\\', '/', $file));
        $ignored = ['/__pycache__/', '/_coverage/', '/_out/', '/vendor/', '/node_modules/'];
        foreach ($ignored as $token) {
            if (str_contains($normalized, $token)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int,string> $tags */
    private static function scopeMatch(string $rel, array $tags, string $scope): bool
    {
        $scope = strtolower(trim($scope));
        if ($scope === '' || $scope === 'all') {
            return true;
        }

        if (in_array($scope, $tags, true)) {
            return true;
        }

        $normalized = strtolower(str_replace('\\', '/', $rel));
        return str_contains($normalized, '/' . $scope . '/');
    }

    /** @param array<int,string> $tags */
    private static function categoryMatch(array $tags, string $category): bool
    {
        $category = strtolower(trim($category));
        if ($category === '' || $category === 'all') {
            return true;
        }

        return in_array($category, $tags, true);
    }

    private static function moduleFromRel(string $rel, int $level): string
    {
        $parts = array_values(array_filter(explode('/', str_replace('\\', '/', $rel)), static fn(string $p): bool => $p !== ''));
        if ($parts === []) {
            return 'unknown';
        }

        $level = max(1, $level);

        if ($parts[0] === 'test' && count($parts) >= ($level + 1)) {
            return implode('/', array_slice($parts, 1, $level));
        }

        if ($parts[0] === 'submodules' && count($parts) >= 2) {
            return implode('/', array_slice($parts, 0, min(count($parts), max(2, $level))));
        }

        if (count($parts) >= $level) {
            return implode('/', array_slice($parts, 0, $level));
        }

        return $parts[0];
    }
}
