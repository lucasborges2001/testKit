<?php
declare(strict_types=1);

namespace Testkit\Core\Cleanup;

final class CleanupExecutor
{
    /**
     * @param array<string,mixed> $payload
     */
    public static function executePlan(array &$payload): void
    {
        foreach ($payload['candidates'] as $idx => $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $relativePath = (string)($candidate['path'] ?? '');
            $absolute = CleanupFilesystem::resolvePath($relativePath);
            $group = (string)($candidate['group'] ?? '');

            if (!CleanupSafety::isSafeDeletePath($absolute, $group)) {
                $payload['candidates'][$idx]['deleted'] = false;
                $payload['candidates'][$idx]['error'] = 'unsafe path rejected at execution';
                CleanupPlanner::addError($payload, 'unsafe path rejected at execution: ' . $relativePath);
                continue;
            }

            $bytes = CleanupFilesystem::pathSize($absolute);
            $ok = CleanupFilesystem::deletePath($absolute);
            $payload['candidates'][$idx]['deleted'] = $ok;

            if ($ok) {
                $payload['summary']['deleted']++;
                $payload['summary']['bytes_deleted'] += $bytes;
            } else {
                $payload['candidates'][$idx]['error'] = 'delete failed';
                CleanupPlanner::addError($payload, 'delete failed: ' . $relativePath);
            }
        }
    }
}
