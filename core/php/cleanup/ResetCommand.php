<?php
declare(strict_types=1);

namespace Testkit\Core\Cleanup;

use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;

require_once __DIR__ . '/CleanupFilesystem.php';
require_once __DIR__ . '/CleanupSafety.php';
require_once __DIR__ . '/CleanupLockInspector.php';

/**
 * Purges disposable TestKit artifacts after the wrapper has stopped the compose stack.
 *
 * Docker lifecycle belongs to bin/testkit and bin/testkit.ps1. This command only
 * removes allowlisted generated paths from the host project.
 */
final class ResetCommand
{
    /**
     * @param array<int,string> $argv
     */
    public static function runCli(array $argv): int
    {
        try {
            $options = self::parse(array_slice($argv, 1));
            if ($options['help']) {
                echo self::usage() . PHP_EOL;
                return 0;
            }

            if (Env::string('TESTKIT_RESET_CONTAINERS_STOPPED') !== '1') {
                throw new \RuntimeException('reset requiere que el wrapper detenga primero los contenedores (TESTKIT_RESET_CONTAINERS_STOPPED=1)');
            }

            $payload = self::execute((bool)$options['hard']);
            if ($options['json']) {
                echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
            } else {
                self::printText($payload);
            }

            return ((int)$payload['summary']['errors']) > 0 ? 1 : 0;
        } catch (\InvalidArgumentException $e) {
            fwrite(STDERR, 'reset error: ' . $e->getMessage() . PHP_EOL . PHP_EOL . self::usage() . PHP_EOL);
            return 2;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'reset error: ' . $e->getMessage() . PHP_EOL);
            return 1;
        }
    }

    /**
     * @param array<int,string> $args
     * @return array{hard:bool,json:bool,help:bool}
     */
    private static function parse(array $args): array
    {
        $options = ['hard' => false, 'json' => false, 'help' => false];
        foreach ($args as $arg) {
            switch ($arg) {
                case '--hard':
                    $options['hard'] = true;
                    break;
                case '--json':
                    $options['json'] = true;
                    break;
                case '--help':
                case '-h':
                    $options['help'] = true;
                    break;
                case '':
                    break;
                default:
                    throw new \InvalidArgumentException('argumento no reconocido: ' . $arg);
            }
        }
        return $options;
    }

    /**
     * @return array<string,mixed>
     */
    private static function execute(bool $hard): array
    {
        $artifactsRoot = Paths::artifactsRoot();
        $targets = [
            ['name' => 'reports', 'group' => 'reports', 'path' => Paths::reportsRoot()],
            ['name' => 'mysql_profile_shards', 'group' => 'profiles', 'path' => $artifactsRoot . '/mysql_profile/shards'],
            ['name' => 'influx_profile_shards', 'group' => 'profiles', 'path' => $artifactsRoot . '/influx_profile/shards'],
            ['name' => 'artifact_coverage', 'group' => 'coverage', 'path' => $artifactsRoot . '/coverage'],
            ['name' => 'legacy_coverage', 'group' => 'coverage', 'path' => Paths::testRoot() . '/coverage'],
        ];

        foreach (['TEST_COVERAGE_ROOT', 'TEST_COVERAGE_DIR'] as $key) {
            $configured = Env::string($key);
            if ($configured !== '') {
                $targets[] = [
                    'name' => strtolower($key),
                    'group' => 'coverage',
                    'path' => CleanupFilesystem::resolvePath($configured),
                ];
            }
        }

        $locksRoot = Paths::normalize($artifactsRoot . '/locks');
        if ($hard) {
            $targets[] = ['name' => 'locks', 'group' => 'locks', 'path' => $locksRoot];
            $targets[] = ['name' => 'history', 'group' => 'history', 'path' => Paths::historyRoot()];
        } else {
            foreach (CleanupFilesystem::listChildDirs($locksRoot) as $lockDir) {
                if (CleanupLockInspector::isStaleLock($lockDir)) {
                    $targets[] = ['name' => 'stale_lock', 'group' => 'locks', 'path' => $lockDir];
                }
            }
        }

        $seen = [];
        $deleted = [];
        $errors = [];
        $bytesDeleted = 0;
        foreach ($targets as $target) {
            $path = Paths::normalize((string)$target['path']);
            if ($path === '' || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;

            if (!file_exists($path) && !is_link($path)) {
                continue;
            }

            $group = (string)$target['group'];
            $safe = $group === 'coverage'
                ? CleanupSafety::isSafeCoveragePath($path)
                : CleanupSafety::isSafeDeletePath($path, $group);
            if (!$safe) {
                $errors[] = 'ruta rechazada por política de seguridad: ' . Paths::relativeToRepo($path);
                continue;
            }

            $bytes = CleanupFilesystem::pathSize($path);
            if (!CleanupFilesystem::deletePath($path)) {
                $errors[] = 'no se pudo eliminar: ' . Paths::relativeToRepo($path);
                continue;
            }

            $bytesDeleted += $bytes;
            $deleted[] = [
                'name' => (string)$target['name'],
                'path' => Paths::relativeToRepo($path),
                'bytes' => $bytes,
            ];
        }

        return [
            'ok' => $errors === [],
            'command' => 'reset',
            'mode' => $hard ? 'hard' : 'safe',
            'deleted' => $deleted,
            'preserved' => [
                'baselines' => true,
                'history' => !$hard,
                'active_locks' => !$hard,
                'env_files' => true,
                'test_sources' => true,
                'test_seeds' => true,
                'artifacts_root' => true,
            ],
            'summary' => [
                'deleted_paths' => count($deleted),
                'bytes_deleted' => $bytesDeleted,
                'errors' => count($errors),
            ],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function printText(array $payload): void
    {
        $summary = (array)$payload['summary'];
        echo 'testkit reset mode=' . (string)$payload['mode'] . PHP_EOL;
        echo 'deleted_paths=' . (int)$summary['deleted_paths']
            . ' bytes_deleted=' . CleanupFilesystem::formatBytes((int)$summary['bytes_deleted'])
            . ' errors=' . (int)$summary['errors'] . PHP_EOL;
        foreach ((array)$payload['deleted'] as $item) {
            if (is_array($item)) {
                echo 'DELETE ' . (string)($item['path'] ?? '') . PHP_EOL;
            }
        }
        foreach ((array)$payload['errors'] as $error) {
            echo 'ERROR ' . (string)$error . PHP_EOL;
        }
    }

    private static function usage(): string
    {
        return <<<'TXT'
Usage:
  testkit reset [--hard] [--json]

Modes:
  reset         Stops TestKit containers and purges reports, profiling shards,
                coverage and stale locks. Docker volumes, history, active locks
                and baselines remain.
  reset --hard  Also removes Docker volumes, history and all TestKit locks.

Safety:
  - baselines are preserved in both modes;
  - env files, test sources and seeds are never reset;
  - this artifact command refuses direct execution unless the wrapper confirms
    that TestKit containers were stopped first.
TXT;
    }
}
