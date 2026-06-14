<?php
declare(strict_types=1);

namespace Testkit\Core\Cleanup;

use Testkit\Core\Common\Env;

final class CleanupLockInspector
{
    public static function isStaleLock(string $lockPath): bool
    {
        $ownerFile = $lockPath . '/owner.json';
        if (!is_file($ownerFile)) {
            $mtime = @filemtime($lockPath);
            if ($mtime === false) {
                return false;
            }
            $ttlSec = max(60, Env::int('TEST_LOCK_STALE_TTL_SEC', 3600));
            return (time() - $mtime) > $ttlSec;
        }

        $raw = @file_get_contents($ownerFile);
        if (!is_string($raw) || trim($raw) === '') {
            $mtime = @filemtime($lockPath);
            if ($mtime === false) {
                return false;
            }
            $ttlSec = max(60, Env::int('TEST_LOCK_STALE_TTL_SEC', 3600));
            return (time() - $mtime) > $ttlSec;
        }

        $owner = json_decode($raw, true);
        if (!is_array($owner)) {
            return false;
        }

        $ownerPid = isset($owner['pid']) ? (int)$owner['pid'] : 0;
        $ownerHost = (string)($owner['hostname'] ?? '');
        $currentHost = function_exists('gethostname') ? (string)@gethostname() : '';

        if (
            $ownerHost !== ''
            && $currentHost !== ''
            && $ownerHost === $currentHost
            && $ownerPid > 0
            && function_exists('posix_kill')
        ) {
            $alive = @posix_kill($ownerPid, 0);
            if ($alive) {
                return false;
            }
            $errno = function_exists('posix_get_last_error') ? posix_get_last_error() : 0;
            return $errno === 3;
        }

        $acquiredAt = (string)($owner['acquired_at'] ?? '');
        if ($acquiredAt !== '') {
            $ts = strtotime($acquiredAt);
            $ageSec = $ts !== false ? (time() - $ts) : PHP_INT_MAX;
        } else {
            $mtime = @filemtime($ownerFile);
            $ageSec = $mtime !== false ? (time() - $mtime) : PHP_INT_MAX;
        }

        $ttlSec = max(60, Env::int('TEST_LOCK_STALE_TTL_SEC', 3600));
        return $ageSec > $ttlSec;
    }
}
