<?php
declare(strict_types=1);

namespace Testkit\Core\Cleanup;

use Testkit\Core\Common\Paths;

final class CleanupSafety
{
    public static function isSafeDeletePath(string $path, string $group): bool
    {
        $path = Paths::normalize($path);
        if ($path === '' || $path === '/' || $path === '.') {
            return false;
        }

        $repoRoot = Paths::repoRoot();
        $testkitRoot = Paths::testkitRoot();
        $testRoot = Paths::testRoot();
        $artifactsRoot = Paths::artifactsRoot();

        foreach ([$repoRoot, $testkitRoot, $testRoot, $artifactsRoot] as $protected) {
            if ($path === Paths::normalize($protected)) {
                return false;
            }
        }

        if ($group === 'coverage') {
            return self::isSafeCoveragePath($path);
        }

        return self::isDescendant($path, $artifactsRoot);
    }

    public static function isSafeCoveragePath(string $path): bool
    {
        $path = Paths::normalize($path);
        $defaultCoverage = Paths::normalize(Paths::repoRoot() . '/test/coverage');
        if ($path === $defaultCoverage || self::isDescendant($path, $defaultCoverage)) {
            return true;
        }

        $artifactsRoot = Paths::artifactsRoot();
        if (self::isDescendant($path, $artifactsRoot) && str_contains('/' . $path . '/', '/coverage/')) {
            return true;
        }

        return false;
    }

    public static function isDescendant(string $path, string $root): bool
    {
        $path = Paths::normalize($path);
        $root = Paths::normalize($root);
        return $path !== $root && str_starts_with($path . '/', $root . '/');
    }
}
