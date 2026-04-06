<?php
declare(strict_types=1);

namespace Testkit\Core\Execution;

final class ProcessRunner
{
    /**
     * @param array<int,string> $cmd
     * @param array<string,string> $env
     * @return array<string,mixed>
     */
    public static function start(array $cmd, string $cwd, array $env): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $proc = @proc_open($cmd, $descriptors, $pipes, $cwd, $env);
        if (!is_resource($proc)) {
            return [
                'ok' => false,
                'code' => 127,
                'stdout' => '',
                'stderr' => 'No se pudo ejecutar: ' . self::joinCommand($cmd) . PHP_EOL,
                'start_ms' => self::nowMs(),
                'proc' => null,
                'pipes' => [],
            ];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [
            'ok' => true,
            'code' => null,
            'stdout' => '',
            'stderr' => '',
            'start_ms' => self::nowMs(),
            'proc' => $proc,
            'pipes' => $pipes,
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
                usleep(10000);
            }
        }

        self::consume($job);
        self::closeReadablePipe($job, 1);
        self::closeReadablePipe($job, 2);

        $code = is_resource($job['proc']) ? (int)proc_close($job['proc']) : 127;

        $job['code'] = $code;
        $job['duration_ms'] = max(0, self::nowMs() - (int)($job['start_ms'] ?? self::nowMs()));
        return $job;
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
        $map = [];
        foreach ([1 => 'stdout', 2 => 'stderr'] as $index => $key) {
            if (!isset($job['pipes'][$index]) || !is_resource($job['pipes'][$index])) {
                continue;
            }

            $stream = $job['pipes'][$index];
            $read[] = $stream;
            $map[(int)$stream] = $key;
        }

        if ($read === []) {
            return;
        }

        $write = [];
        $except = [];
        $ready = @stream_select($read, $write, $except, 0, 0);
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
