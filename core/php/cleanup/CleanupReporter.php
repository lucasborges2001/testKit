<?php
declare(strict_types=1);

namespace Testkit\Core\Cleanup;

final class CleanupReporter
{
    public static function encodeJson(mixed $payload): string
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || $encoded === '') {
            throw new \RuntimeException('no se pudo serializar JSON');
        }
        return $encoded;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     */
    public static function printText(array $payload, array $options): void
    {
        $mode = (string)$payload['mode'];
        $summary = $payload['summary'];

        echo 'testkit cleanup ' . $mode . PHP_EOL;
        echo str_repeat('=', 72) . PHP_EOL;
        echo 'artifacts_root: ' . (string)$payload['artifacts_root'] . PHP_EOL;
        echo 'group:          ' . (string)$options['group'] . PHP_EOL;
        echo 'keep_runs:      ' . (int)$options['keep_runs'] . PHP_EOL;
        echo 'keep_days:      ' . (int)$options['keep_days'] . PHP_EOL;
        echo 'max_runs:       ' . ($options['max_runs'] === null ? '-' : (string)(int)$options['max_runs']) . PHP_EOL;
        echo PHP_EOL;

        foreach ($payload['groups'] as $name => $group) {
            if (!is_array($group)) {
                continue;
            }
            echo $name . ':' . PHP_EOL;
            foreach ($group as $key => $value) {
                echo '  ' . str_pad((string)$key . ':', 28) . (string)$value . PHP_EOL;
            }
            echo PHP_EOL;
        }

        echo 'summary:' . PHP_EOL;
        echo '  scanned:                    ' . (int)$summary['scanned'] . PHP_EOL;
        echo '  delete_candidates:          ' . (int)$summary['delete_candidates'] . PHP_EOL;
        echo '  bytes_reclaimable:          ' . CleanupFilesystem::formatBytes((int)$summary['bytes_reclaimable']) . PHP_EOL;
        if ($mode === 'apply') {
            echo '  deleted:                    ' . (int)$summary['deleted'] . PHP_EOL;
            echo '  bytes_deleted:              ' . CleanupFilesystem::formatBytes((int)$summary['bytes_deleted']) . PHP_EOL;
        } else {
            echo PHP_EOL . 'Nothing deleted. Re-run with --apply to delete candidates.' . PHP_EOL;
        }

        if ((int)$summary['errors'] > 0) {
            echo PHP_EOL . 'errors:' . PHP_EOL;
            foreach ($payload['errors'] as $error) {
                echo '  - ' . (string)$error . PHP_EOL;
            }
        }
    }
}
