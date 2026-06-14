<?php
declare(strict_types=1);

namespace Testkit\Core\Common;

final class Paths
{
    /** @var array<string,string> */
    private static array $suiteReportRoots = [];

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

    public static function artifactsRoot(): string
    {
        $fromEnv = Env::string('TESTKIT_ARTIFACTS_ROOT');
        if ($fromEnv !== '') {
            return self::normalize($fromEnv);
        }

        return self::normalize(self::repoRoot() . '/.testkit');
    }

    public static function outRoot(): string
    {
        self::ensureDir(self::artifactsRoot());
        return self::artifactsRoot();
    }

    public static function reportsRoot(): string
    {
        return self::normalize(self::outRoot() . '/reports');
    }

    public static function reportRunRoot(string $runId): string
    {
        $runId = preg_replace('/[^a-z0-9._-]+/i', '_', trim($runId)) ?: '';
        if ($runId === '') {
            $runId = 'adhoc';
        }

        return self::normalize(self::reportsRoot() . '/runs/' . $runId);
    }

    public static function latestRunManifestPath(): string
    {
        return self::normalize(self::reportsRoot() . '/latest_run.json');
    }

    public static function historyRoot(): string
    {
        return self::normalize(self::outRoot() . '/history');
    }

    /**
     * Canonical coverage artifact root.
     *
     * Priority:
     * 1. TEST_COVERAGE_ROOT: explicit canonical coverage root.
     * 2. TEST_COVERAGE_DIR: legacy alias, kept as a root for backwards compatibility.
     * 3. .testkit/coverage under the host project artifact root.
     */
    public static function coverageRoot(): string
    {
        $root = Env::string('TEST_COVERAGE_ROOT', '');
        if ($root !== '') {
            return self::normalize($root);
        }

        $legacyRoot = Env::string('TEST_COVERAGE_DIR', '');
        if ($legacyRoot !== '') {
            return self::normalize($legacyRoot);
        }

        return self::normalize(self::outRoot() . '/coverage');
    }

    public static function coverageDirForSuite(string $suiteId): string
    {
        return self::normalize(self::coverageRoot() . '/' . self::sanitizeSuiteId($suiteId));
    }

    public static function legacyCoverageDirForSuite(string $suiteId): string
    {
        $legacyName = match (self::sanitizeSuiteId($suiteId)) {
            'back_php' => 'php_back',
            'front_php' => 'php_front',
            'back_python' => 'python_back',
            default => self::sanitizeSuiteId($suiteId),
        };

        return self::normalize(self::testRoot() . '/coverage/' . $legacyName);
    }

    /**
     * @return array<int,string>
     */
    public static function coverageDirCandidatesForSuite(string $suiteId): array
    {
        $suiteId = self::sanitizeSuiteId($suiteId);
        $candidates = [self::coverageDirForSuite($suiteId)];

        $legacy = self::legacyCoverageDirForSuite($suiteId);
        if (!in_array($legacy, $candidates, true)) {
            $candidates[] = $legacy;
        }

        return $candidates;
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

    /**
     * Resolve the report directory for the current run.
     *
     * Report artifacts are centralized under a single ignored root so the host
     * repository does not accumulate operational state under versioned test
     * paths. Logical scope is preserved in report metadata and filenames.
     *
     * @param array<int,array<string,mixed>> $tests
     */
    public static function resolveReportRoot(array $tests): string
    {
        $runRoot = Env::string('TEST_REPORT_RUN_ROOT');
        if ($runRoot !== '') {
            return self::normalize($runRoot);
        }

        $explicit = Env::string('TEST_REPORT_ROOT');
        if ($explicit !== '') {
            return self::normalize($explicit);
        }

        return self::reportsRoot();
    }

    /**
     * Extract "back/<module>" or "front/<module>" from a repo-relative path.
     * Example: "test/back/auth/integration/login.test.php" => "back/auth"
     * Returns null if the path does not match the expected structure.
     */
    public static function extractFunctionalModule(string $rel): ?string
    {
        $parts = array_values(array_filter(
            explode('/', str_replace('\\', '/', $rel)),
            static fn(string $p): bool => $p !== ''
        ));

        if (count($parts) < 3) {
            return null;
        }
        if ($parts[0] !== 'test') {
            return null;
        }
        if (!in_array($parts[1], ['back', 'front'], true)) {
            return null;
        }

        return $parts[1] . '/' . $parts[2];
    }

    /**
     * Record a report root computed by a suite, for later aggregation by MetaRunner.
     */
    public static function recordSuiteReportRoot(string $root, string $suiteId = ''): void
    {
        $normalized = self::normalize($root);
        if ($normalized === '') {
            return;
        }

        $key = $suiteId !== '' ? $suiteId : ('__idx_' . count(self::$suiteReportRoots));
        self::$suiteReportRoots[$key] = $normalized;
    }

    public static function reportRootForSuite(string $suiteId): ?string
    {
        $root = self::$suiteReportRoots[$suiteId] ?? null;
        return is_string($root) && $root !== '' ? $root : null;
    }

    /**
     * @return array<int,string>
     */
    public static function suiteReportRoots(): array
    {
        return array_values(array_unique(array_values(self::$suiteReportRoots)));
    }

    /**
     * Return the single shared report root if all suites agreed on one, otherwise the fallback.
     */
    public static function aggregateMetaReportRoot(): string
    {
        $fallback = self::resolveReportRoot([]);
        $unique = self::suiteReportRoots();
        if ($unique === []) {
            return $fallback;
        }
        return count($unique) === 1 ? $unique[0] : $fallback;
    }

    private static function sanitizeSuiteId(string $suiteId): string
    {
        $suiteId = preg_replace('/[^a-z0-9._-]+/i', '_', trim($suiteId)) ?: '';
        return $suiteId !== '' ? $suiteId : 'suite';
    }
}
