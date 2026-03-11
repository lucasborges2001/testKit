<?php
declare(strict_types=1);

namespace Testkit\Core\Common;

final class Bootstrap
{
    public static function init(): void
    {
        $testkitRoot = Paths::testkitRoot();
        $repoRoot = Paths::repoRoot();

        putenv('TESTKIT_ROOT=' . $testkitRoot);
        putenv('TK_REPO_ROOT=' . $repoRoot);

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
