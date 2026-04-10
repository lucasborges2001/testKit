<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

final class BaselineManifest
{
    public static function pathFor(string $projectRoot, string $driver, string $database): string
    {
        $override = trim((string)(getenv('TEST_BASELINE_MANIFEST_PATH') ?: ''));
        if ($override !== '') {
            return self::normalizePath($override);
        }

        $projectRoot = rtrim($projectRoot, "/\\");
        return self::normalizePath(
            $projectRoot . '/.testkit/baselines/' . $driver . '/' . $database . '.manifest.json'
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function load(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function save(string $path, array $data): void
    {
        self::ensureParentDirectory($path);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException('No se pudo serializar baseline manifest.');
        }

        $written = file_put_contents($path, $json . PHP_EOL);
        if ($written === false) {
            throw new \RuntimeException('No se pudo escribir baseline manifest: ' . $path);
        }
    }

    public static function delete(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function ensureParentDirectory(string $path): void
    {
        $dir = dirname($path);
        if (is_dir($dir)) {
            return;
        }

        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear directorio para baseline manifest: ' . $dir);
        }
    }

    private static function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
