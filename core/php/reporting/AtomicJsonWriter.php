<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Testkit\Core\Common\Paths;

final class AtomicJsonWriter
{
    /**
     * @return array<string,mixed>
     */
    public static function loadJsonFile(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $raw = file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }

    public static function writeFileAtomic(string $path, string $contents): void
    {
        $dir = dirname($path);
        Paths::ensureDir($dir);

        $tmp = tempnam($dir, basename($path) . '.tmp.');
        if (!is_string($tmp) || $tmp === '') {
            file_put_contents($path, $contents, LOCK_EX);
            return;
        }

        file_put_contents($tmp, $contents, LOCK_EX);

        if (!@rename($tmp, $path)) {
            @copy($tmp, $path);
            @unlink($tmp);
        }
    }
}
