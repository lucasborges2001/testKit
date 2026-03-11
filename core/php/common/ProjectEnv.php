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
}
