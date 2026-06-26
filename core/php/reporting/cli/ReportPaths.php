<?php
declare(strict_types=1);

/**
 * ============================================================================
 * @file    testkit/core/php/reporting/cli/ReportPaths.php
 * @brief   Resuelve rutas de workspace, TestKit y artefactos para report.php.
 * ============================================================================
 */

namespace Base\TestKit\Reporting\Cli;

require_once __DIR__ . '/ReportArguments.php';

final class ReportPaths
{
    /**
     * @param list<string> $artifactRoots
     */
    public function __construct(
        public readonly string $scriptPath,
        public readonly string $testkitRoot,
        public readonly string $workspaceRoot,
        public readonly array $artifactRoots
    ) {
    }

    public static function fromScript(string $scriptPath, ReportArguments $arguments): self
    {
        $resolvedScript = self::canonicalFile($scriptPath);
        $testkitRoot = dirname(dirname($resolvedScript));
        $workspaceRoot = self::resolveWorkspaceRoot($testkitRoot, $arguments->workspaceRoot);
        $artifactRoots = self::resolveArtifactRoots($workspaceRoot, $testkitRoot, $arguments);

        return new self($resolvedScript, $testkitRoot, $workspaceRoot, $artifactRoots);
    }

    public function relative(string $path): string
    {
        $normalized = self::normalizePath($path);
        $base = rtrim(self::normalizePath($this->workspaceRoot), '/') . '/';

        if (str_starts_with($normalized, $base)) {
            return substr($normalized, strlen($base));
        }

        return $normalized;
    }

    private static function resolveWorkspaceRoot(string $testkitRoot, ?string $requested): string
    {
        if ($requested !== null) {
            return self::canonicalDirectory($requested);
        }

        $envRoot = getenv('TESTKIT_PROJECT_ROOT') ?: getenv('WORKSPACE_ROOT') ?: getenv('PROJECT_ROOT') ?: null;
        if (is_string($envRoot) && trim($envRoot) !== '') {
            return self::canonicalDirectory($envRoot);
        }

        $workspace = '/workspace';
        if (is_dir($workspace)) {
            return self::canonicalDirectory($workspace);
        }

        $cwd = getcwd();
        if (is_string($cwd) && $cwd !== '') {
            return self::canonicalDirectory($cwd);
        }

        return dirname($testkitRoot);
    }

    /**
     * @return list<string>
     */
    private static function resolveArtifactRoots(string $workspaceRoot, string $testkitRoot, ReportArguments $arguments): array
    {
        $candidates = [];

        if ($arguments->artifactsRoot !== null) {
            $candidates[] = $arguments->artifactsRoot;
        }

        foreach (['TESTKIT_REPORT_ROOT', 'REPORT_ROOT', 'TEST_REPORT_ROOT', 'TESTKIT_RESULTS_ROOT'] as $envName) {
            $value = getenv($envName);
            if (is_string($value) && trim($value) !== '') {
                $candidates[] = $value;
            }
        }

        foreach ($arguments->positionalPaths as $path) {
            $candidates[] = $path;
        }

        $candidates = array_merge($candidates, [
            $workspaceRoot . '/reports/testkit',
            $workspaceRoot . '/reports',
            $workspaceRoot . '/.testkit/reports',
            $workspaceRoot . '/.testkit/results',
            $workspaceRoot . '/testkit/reports',
            $testkitRoot . '/reports',
            $testkitRoot . '/.testkit/results',
        ]);

        $roots = [];
        foreach ($candidates as $candidate) {
            $path = self::absolutePath($candidate, $workspaceRoot);
            if (is_dir($path) || is_file($path)) {
                $real = realpath($path);
                $roots[] = $real !== false ? self::normalizePath($real) : self::normalizePath($path);
            }
        }

        return array_values(array_unique($roots));
    }

    private static function absolutePath(string $path, string $base): string
    {
        $path = trim($path);
        if ($path === '') {
            return $base;
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim($base, '/') . '/' . $path;
    }

    private static function canonicalFile(string $path): string
    {
        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            throw new \RuntimeException(sprintf('No se pudo resolver el archivo de script: %s', $path));
        }

        return self::normalizePath($real);
    }

    private static function canonicalDirectory(string $path): string
    {
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException(sprintf('No se pudo resolver el directorio: %s', $path));
        }

        return self::normalizePath($real);
    }

    private static function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
