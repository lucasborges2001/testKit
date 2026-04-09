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
        $safeName = self::safeSlug($name);
        $root = Paths::outRoot() . '/locks';
        Paths::ensureDir($root);

        $lockPath = $root . '/' . $safeName;
        $timeoutMs = $timeoutMs ?? max(0, Env::int('TEST_LOCK_TIMEOUT_SEC', 120) * 1000);
        $deadline = self::nowMs() + $timeoutMs;

        do {
            if (@mkdir($lockPath, 0777, false)) {
                self::writeOwnerFile($lockPath, $name);
                return new LockLease($name, $lockPath);
            }

            if (!$blocking) {
                return null;
            }

            usleep(max(1, $pollMs) * 1000);
        } while (self::nowMs() <= $deadline);

        return null;
    }

    private static function writeOwnerFile(string $lockPath, string $name): void
    {
        $payload = [
            'name' => $name,
            'pid' => function_exists('getmypid') ? getmypid() : null,
            'run_id' => (string)(getenv('TEST_RUN_ID') ?: ''),
            'meta_run_id' => (string)(getenv('TEST_META_RUN_ID') ?: ''),
            'acquired_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'hostname' => function_exists('gethostname') ? @gethostname() : null,
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
