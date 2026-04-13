<?php
declare(strict_types=1);

namespace Testkit\Core\Execution;

final class ProcessRunner
{
    /**
     * Start a process.
     *
     * timeoutSec controls how long to wait before killing the child:
     *   0  = read TEST_PROCESS_TIMEOUT_SEC (default 600 s)
     *  -1  = no timeout
     *  >0  = explicit seconds
     *
     * @param array<int,string> $cmd
     * @param array<string,string> $env
     * @return array<string,mixed>
     */
    public static function start(array $cmd, string $cwd, array $env, int $timeoutSec = 0): array
    {
        $timeoutMs = self::resolveTimeoutMs($timeoutSec);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $proc = @proc_open($cmd, $descriptors, $pipes, $cwd, $env);
        if (!is_resource($proc)) {
            return [
                'ok'         => false,
                'code'       => 127,
                'stdout'     => '',
                'stderr'     => 'No se pudo ejecutar: ' . self::joinCommand($cmd) . PHP_EOL,
                'start_ms'   => self::nowMs(),
                'proc'       => null,
                'pipes'      => [],
                'timeout_ms' => $timeoutMs,
                'timeout'    => false,
            ];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [
            'ok'         => true,
            'code'       => null,
            'stdout'     => '',
            'stderr'     => '',
            'start_ms'   => self::nowMs(),
            'proc'       => $proc,
            'pipes'      => $pipes,
            'timeout_ms' => $timeoutMs,
            'timeout'    => false,
        ];
    }

    /**
     * @param array<string,mixed> $job
     */
    public static function isRunning(array &$job): bool
    {
        if (!($job['ok'] ?? false)) {
            return false;
        }

        self::consume($job);

        if (!is_resource($job['proc'])) {
            return false;
        }

        if (self::isTimedOut($job)) {
            self::markTimeoutAndKill($job);
            return false;
        }

        $status = proc_get_status($job['proc']);
        return is_array($status) && (bool)($status['running'] ?? false);
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function finish(array &$job): array
    {
        if (!($job['ok'] ?? false)) {
            $job['duration_ms'] = max(0, self::nowMs() - (int)($job['start_ms'] ?? self::nowMs()));
            return $job;
        }

        if (is_resource($job['proc'] ?? null)) {
            while (true) {
                self::consume($job);
                $status = proc_get_status($job['proc']);
                if (!is_array($status) || !($status['running'] ?? false)) {
                    break;
                }

                if (self::isTimedOut($job)) {
                    self::markTimeoutAndKill($job);
                    break;
                }

                usleep(10000);
            }
        }

        self::consume($job);
        self::closeReadablePipe($job, 1);
        self::closeReadablePipe($job, 2);

        $code = is_resource($job['proc']) ? (int)proc_close($job['proc']) : 127;

        // A timed-out process must never report success.
        // 124 is the conventional exit code used by GNU timeout(1).
        if ($code === 0 && ($job['timeout'] ?? false)) {
            $code = 124;
        }

        $job['code']        = $code;
        $job['duration_ms'] = max(0, self::nowMs() - (int)($job['start_ms'] ?? self::nowMs()));
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     */
    private static function markTimeoutAndKill(array &$job): void
    {
        $job['timeout'] = true;
        $elapsed        = self::nowMs() - (int)($job['start_ms'] ?? self::nowMs());
        $limit          = (int)($job['timeout_ms'] ?? 0);
        $job['stderr']  = ($job['stderr'] ?? '') . sprintf(
            "\n[testkit] TIMEOUT: proceso terminado después de %dms (límite: %dms)\n",
            $elapsed,
            $limit
        );
        self::terminateJob($job);
    }

    /**
     * Terminate a child process: SIGTERM first, then SIGKILL after a grace period.
     *
     * @param array<string,mixed> $job
     */
    private static function terminateJob(array &$job): void
    {
        if (!is_resource($job['proc'] ?? null)) {
            return;
        }

        self::consume($job);

        // Graceful termination
        @proc_terminate($job['proc'], 15);

        $grace = self::nowMs() + 5000;
        while (self::nowMs() < $grace) {
            usleep(100000); // 100 ms
            if (!is_resource($job['proc'])) {
                break;
            }
            $status = @proc_get_status($job['proc']);
            if (!is_array($status) || !(bool)($status['running'] ?? false)) {
                break;
            }
        }

        // Force kill if still alive after grace period
        if (is_resource($job['proc'])) {
            $status = @proc_get_status($job['proc']);
            if (is_array($status) && (bool)($status['running'] ?? false)) {
                @proc_terminate($job['proc'], 9);
                usleep(500000); // 500 ms for SIGKILL to take effect
            }
        }
    }

    /**
     * Resolve timeout in milliseconds from the given parameter or environment.
     */
    private static function resolveTimeoutMs(int $timeoutSec): int
    {
        if ($timeoutSec === -1) {
            return 0; // explicitly disabled
        }

        if ($timeoutSec > 0) {
            return $timeoutSec * 1000;
        }

        // 0 = read from environment, default 600 s
        $raw = getenv('TEST_PROCESS_TIMEOUT_SEC');
        $sec = ($raw !== false && is_numeric($raw) && (int)$raw >= 0) ? (int)$raw : 600;
        return $sec * 1000;
    }

    /**
     * @param array<string,mixed> $job
     */
    private static function isTimedOut(array &$job): bool
    {
        $limit = (int)($job['timeout_ms'] ?? 0);
        if ($limit <= 0) {
            return false;
        }
        $elapsed = self::nowMs() - (int)($job['start_ms'] ?? self::nowMs());
        return $elapsed >= $limit;
    }

    /**
     * @param array<string,mixed> $job
     */
    private static function consume(array &$job): void
    {
        if (!isset($job['stdout']) || !is_string($job['stdout'])) {
            $job['stdout'] = '';
        }
        if (!isset($job['stderr']) || !is_string($job['stderr'])) {
            $job['stderr'] = '';
        }

        if (!isset($job['pipes']) || !is_array($job['pipes'])) {
            return;
        }

        $read = [];
        $map  = [];
        foreach ([1 => 'stdout', 2 => 'stderr'] as $index => $key) {
            if (!isset($job['pipes'][$index]) || !is_resource($job['pipes'][$index])) {
                continue;
            }

            $stream        = $job['pipes'][$index];
            $read[]        = $stream;
            $map[(int)$stream] = $key;
        }

        if ($read === []) {
            return;
        }

        $write  = [];
        $except = [];
        $ready  = @stream_select($read, $write, $except, 0, 0);
        if ($ready === false || $ready === 0) {
            return;
        }

        foreach ($read as $stream) {
            $key = $map[(int)$stream] ?? null;
            if ($key === null) {
                continue;
            }

            while (true) {
                $chunk = fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }

                $job[$key] .= $chunk;

                if (strlen($chunk) < 8192) {
                    break;
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $job
     */
    private static function closeReadablePipe(array &$job, int $index): void
    {
        if (isset($job['pipes'][$index]) && is_resource($job['pipes'][$index])) {
            fclose($job['pipes'][$index]);
        }
        if (isset($job['pipes'][$index])) {
            unset($job['pipes'][$index]);
        }
    }

    /**
     * @param array<int,string> $cmd
     */
    public static function joinCommand(array $cmd): string
    {
        $parts = [];
        foreach ($cmd as $item) {
            if ($item === '') {
                continue;
            }
            if (preg_match('/\s/', $item) === 1) {
                $parts[] = '"' . str_replace('"', '\\"', $item) . '"';
            } else {
                $parts[] = $item;
            }
        }
        return implode(' ', $parts);
    }

    private static function nowMs(): int
    {
        return (int)round(microtime(true) * 1000);
    }
}
