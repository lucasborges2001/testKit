<?php
declare(strict_types=1);

namespace Testkit\Core\Common;

final class ProjectEnv
{
    /**
     * @return array{db_env_path:string,warnings:array<int,string>}
     */
    public static function resolveDbEnv(string $repoRoot): array
    {
        $warnings = [];
        $dbEnvPath = Env::string('DB_ENV_PATH', '');
        if ($dbEnvPath !== '') {
            return ['db_env_path' => $dbEnvPath, 'warnings' => $warnings];
        }

        $primary = [
            $repoRoot . '/test/.env.test',
            $repoRoot . '/.env.test',
        ];

        foreach ($primary as $candidate) {
            if (is_file($candidate)) {
                return ['db_env_path' => Paths::normalize($candidate), 'warnings' => $warnings];
            }
        }

        $legacy = [
            $repoRoot . '/env.test',
            $repoRoot . '/.env.debug',
            $repoRoot . '/env.debug',
            $repoRoot . '/back/.env.test',
            $repoRoot . '/back/.env.debug',
            $repoRoot . '/back/.env',
            $repoRoot . '/.env',
        ];

        foreach ($legacy as $candidate) {
            if (is_file($candidate)) {
                $warnings[] = 'WARN: usando env legacy (no contractual): ' . Paths::normalize($candidate) . '. Recomendado: <project>/test/.env.test o <project>/.env.test.';
                return ['db_env_path' => Paths::normalize($candidate), 'warnings' => $warnings];
            }
        }

        return ['db_env_path' => '', 'warnings' => $warnings];
    }

    /**
     * @return array{db_env_path:string,warnings:array<int,string>}
     */
    public static function hydrateCurrentProcess(string $repoRoot, bool $overrideExisting = false): array
    {
        $resolved = self::resolveDbEnv($repoRoot);
        $dbEnvPath = self::toAbsolutePath($repoRoot, $resolved['db_env_path']);
        if ($dbEnvPath === '' || !is_file($dbEnvPath)) {
            return ['db_env_path' => $dbEnvPath, 'warnings' => $resolved['warnings']];
        }

        self::exportEnv('DB_ENV_PATH', $dbEnvPath);
        self::loadEnvFile($dbEnvPath, $overrideExisting);

        return ['db_env_path' => $dbEnvPath, 'warnings' => $resolved['warnings']];
    }

    private static function toAbsolutePath(string $repoRoot, string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return Paths::normalize($path);
        }

        return Paths::normalize(rtrim($repoRoot, '/\\') . '/' . ltrim($path, '/\\'));
    }

    private static function loadEnvFile(string $path, bool $overrideExisting): void
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $line)) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            $current = getenv($key);
            if (!$overrideExisting && $current !== false && trim((string)$current) !== '') {
                continue;
            }

            self::exportEnv($key, $value);
        }
    }

    private static function exportEnv(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
