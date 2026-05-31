<?php
declare(strict_types=1);

namespace Testkit\Core\Discovery;

use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;

final class PhpDiscoveryConfig
{
    /**
     * @return array{tests_dir:string,discovery_options?:array<string,mixed>,warnings:array<int,array<string,mixed>>}
     */
    public static function backPhpFromEnv(string $repoRoot): array
    {
        $legacyRel = Env::string('TK_BACK_PHP_DIR', 'test/back');
        $legacyRoot = Paths::normalize($repoRoot . '/' . $legacyRel);
        $legacyTestsDir = is_dir($legacyRoot . '/tests') ? ($legacyRoot . '/tests') : $legacyRoot;

        $rootsCsv = Env::string('TK_BACK_PHP_TEST_ROOTS', '');
        if ($rootsCsv === '') {
            return [
                'tests_dir' => $legacyTestsDir,
                'warnings' => [],
            ];
        }

        $rootPatterns = Env::csv('TK_BACK_PHP_TEST_ROOTS');
        $excludeRootPatterns = Env::csv('TK_BACK_PHP_TEST_EXCLUDE_ROOTS');
        $patterns = TestPatternMatcher::normalizePatterns(
            Env::csv('TK_BACK_PHP_TEST_PATTERNS', '*.test.php'),
            ['*.test.php']
        );
        $excludePatterns = TestPatternMatcher::normalizePatterns(
            Env::csv('TK_BACK_PHP_TEST_EXCLUDE_PATTERNS'),
            []
        );

        $resolved = TestRootResolver::resolve($repoRoot, $rootPatterns, $excludeRootPatterns);
        $roots = $resolved['roots'];

        return [
            'tests_dir' => $roots[0] ?? $legacyTestsDir,
            'discovery_options' => [
                'mode' => 'multi_root',
                'roots' => $roots,
                'patterns' => $patterns,
                'exclude_roots' => $excludeRootPatterns,
                'exclude_patterns' => $excludePatterns,
            ],
            'warnings' => $resolved['warnings'],
        ];
    }
}
