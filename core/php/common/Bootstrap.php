<?php
declare(strict_types=1);

namespace Testkit\Core\Common;

final class Bootstrap
{
    private static bool $initialized = false;

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        $repoRoot = Paths::repoRoot();
        $testkitRoot = Paths::testkitRoot();
        $embeddedTestkitRoot = Paths::normalize($repoRoot . '/testkit');
        if (is_dir($embeddedTestkitRoot)) {
            $testkitRoot = $embeddedTestkitRoot;
        }

        putenv('TESTKIT_ROOT=' . $testkitRoot);
        putenv('TK_REPO_ROOT=' . $repoRoot);

        $resolved = ProjectEnv::hydrateCurrentProcess($repoRoot);
        foreach ($resolved['warnings'] as $warning) {
            fwrite(STDERR, $warning . PHP_EOL);
        }

        $constPath = $testkitRoot . '/utils/constants.php';
        if (is_file($constPath)) {
            require_once $constPath;
        }

        $uiPath = $testkitRoot . '/utils/php/ui.php';
        if (is_file($uiPath)) {
            require_once $uiPath;
        }

        Paths::ensureDir(Paths::outRoot());
        Paths::ensureDir(Paths::reportsRoot());
        Paths::ensureDir(Paths::historyRoot());
    }
}
