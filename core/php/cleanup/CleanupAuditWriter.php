<?php
declare(strict_types=1);

namespace Testkit\Core\Cleanup;

use Testkit\Core\Common\Paths;

final class CleanupAuditWriter
{
    /**
     * @param array<string,mixed> $payload
     */
    public static function write(array $payload): void
    {
        $root = Paths::reportsRoot() . '/cleanup';
        Paths::ensureDir($root);

        $timestamp = gmdate('Ymd_His');
        $json = CleanupReporter::encodeJson($payload);
        @file_put_contents($root . '/cleanup_latest.json', $json . PHP_EOL);
        @file_put_contents($root . '/cleanup_' . $timestamp . '.json', $json . PHP_EOL);
    }
}
