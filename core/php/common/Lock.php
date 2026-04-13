<?php
declare(strict_types=1);

namespace Testkit\Core\Common;

final class LockLease
{
    private bool $released = false;

    public function __construct(
        private readonly string $name,
        private readonly string $path
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $ownerFile = $this->path . '/owner.json';
        if (is_file($ownerFile)) {
            @unlink($ownerFile);
        }

        @rmdir($this->path);
        $this->released = true;
    }

    public function __destruct()
    {
        $this->release();
    }
}

final class Lock
{
    public static function acquire(
        string $name,
        bool $blocking = false,
        ?int $timeoutMs = null,
        int $pollMs = 200
    ): ?LockLease {
        $lockPath  = self::pathFor($name);
        $timeoutMs = $timeoutMs ?? max(0, Env::int('TEST_LOCK_TIMEOUT_SEC', 120) * 1000);
        $deadline  = self::nowMs() + $timeoutMs;

        do {
            if (@mkdir($lockPath, 0777, false)) {
                self::writeOwnerFile($lockPath, $name);
                return new LockLease($name, $lockPath);
            }

            // If the lock directory exists but its owner is dead, reclaim it.
            if (self::isStale($lockPath)) {
                self::clearStaleLock($lockPath);
                // Retry mkdir immediately (no sleep on this iteration).
                continue;
            }

            if (!$blocking) {
                return null;
            }

            usleep(max(1, $pollMs) * 1000);
        } while (self::nowMs() <= $deadline);

        return null;
    }

    public static function pathFor(string $name): string
    {
        $safeName = self::safeSlug($name);
        $root     = Paths::outRoot() . '/locks';
        Paths::ensureDir($root);

        return $root . '/' . $safeName;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function readOwner(string $name): ?array
    {
        $ownerFile = self::pathFor($name) . '/owner.json';
        if (!is_file($ownerFile)) {
            return null;
        }

        $raw = @file_get_contents($ownerFile);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $json = json_decode($raw, true);
        return is_array($json) ? $json : null;
    }

    /**
     * Decide if a lock directory belongs to a process that is no longer alive.
     *
     * Decision criteria (in order):
     *
     * 1. Same hostname + PID verifiable via posix_kill:
     *    - posix_kill($pid, 0) returns true  → alive, NOT stale
     *    - posix_kill returns false + errno ESRCH (3) → dead, STALE
     *    - posix_kill returns false + errno EPERM (1) → exists, different user, NOT stale
     *
     * 2. Different hostname, or posix unavailable, or no PID:
     *    Fall back to TTL comparison using the acquired_at timestamp (or the
     *    owner.json mtime as a proxy). Default TTL: TEST_LOCK_STALE_TTL_SEC (3600 s).
     */
    private static function isStale(string $lockPath): bool
    {
        $ownerFile = $lockPath . '/owner.json';

        // Lock directory exists but no owner file: use directory mtime for TTL.
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
            // Corrupt owner file — treat as stale if old enough.
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

        $ownerPid   = isset($owner['pid']) ? (int)$owner['pid'] : 0;
        $ownerHost  = (string)($owner['hostname'] ?? '');
        $currentHost = function_exists('gethostname') ? (string)@gethostname() : '';

        // Same host with a valid PID: use process-existence check.
        if (
            $ownerHost !== ''
            && $currentHost !== ''
            && $ownerHost === $currentHost
            && $ownerPid > 0
            && function_exists('posix_kill')
        ) {
            $alive = @posix_kill($ownerPid, 0);
            if ($alive) {
                return false; // process is running
            }
            // posix_kill returns false for two reasons:
            //   ESRCH (3): no such process → stale
            //   EPERM (1): exists but owned by another user → not stale
            $errno = function_exists('posix_get_last_error') ? posix_get_last_error() : 0;
            return $errno === 3; // ESRCH only
        }

        // Different host or cannot verify PID: rely on TTL from acquired_at.
        $acquiredAt = (string)($owner['acquired_at'] ?? '');
        if ($acquiredAt !== '') {
            $ts     = strtotime($acquiredAt);
            $ageSec = $ts !== false ? (time() - $ts) : PHP_INT_MAX;
        } else {
            $mtime  = @filemtime($ownerFile);
            $ageSec = $mtime !== false ? (time() - $mtime) : PHP_INT_MAX;
        }

        $ttlSec = max(60, Env::int('TEST_LOCK_STALE_TTL_SEC', 3600));
        return $ageSec > $ttlSec;
    }

    private static function clearStaleLock(string $lockPath): void
    {
        $ownerFile = $lockPath . '/owner.json';
        if (is_file($ownerFile)) {
            @unlink($ownerFile);
        }
        @rmdir($lockPath);
    }

    private static function writeOwnerFile(string $lockPath, string $name): void
    {
        $payload = [
            'name'        => $name,
            'pid'         => function_exists('getmypid') ? getmypid() : null,
            'run_id'      => (string)(getenv('TEST_RUN_ID') ?: ''),
            'meta_run_id' => (string)(getenv('TEST_META_RUN_ID') ?: ''),
            'acquired_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'hostname'    => function_exists('gethostname') ? @gethostname() : null,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        @file_put_contents($lockPath . '/owner.json', $json);
    }

    private static function safeSlug(string $value): string
    {
        $value = preg_replace('/[^a-z0-9._-]+/i', '_', strtolower(trim($value))) ?: '';
        $value = trim($value, '._-');
        return $value !== '' ? $value : 'lock';
    }

    private static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
