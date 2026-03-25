<?php
declare(strict_types=1);

namespace Testkit\Core\Common;

final class Paths
{
    public static function testkitRoot(): string
    {
        $fromEnv = Env::string('TESTKIT_ROOT');
        if ($fromEnv !== '' && is_dir($fromEnv)) {
            return self::normalize($fromEnv);
        }
        return self::normalize(dirname(__DIR__, 3));
    }

    public static function repoRoot(): string
    {
        $fromEnv = Env::string('TK_REPO_ROOT', Env::string('TESTKIT_PROJECT_ROOT'));
        if ($fromEnv !== '' && is_dir($fromEnv)) {
            return self::normalize($fromEnv);
        }
        return self::normalize(dirname(self::testkitRoot()));
    }

    public static function testRoot(): string
    {
        return self::normalize(self::repoRoot() . '/test');
    }

    public static function outRoot(): string
    {
        self::ensureDir(self::testRoot());
        return self::testRoot();
    }

    public static function reportsRoot(): string
    {
        return self::normalize(self::outRoot() . '/reports');
    }

    public static function historyRoot(): string
    {
        return self::normalize(self::outRoot() . '/history');
    }

    public static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    public static function relativeToRepo(string $path): string
    {
        $path = self::normalize($path);
        $root = self::repoRoot() . '/';
        if (str_starts_with($path, $root)) {
            return substr($path, strlen($root));
        }
        return $path;
    }

    public static function ensureDir(string $dir): void
    {
        if ($dir === '') {
            return;
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }
}
