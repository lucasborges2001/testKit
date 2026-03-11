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
        stream_set_blocking($pipes[1], true);
        stream_set_blocking($pipes[2], true);

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
    public static function isRunning(array $job): bool
    {
        if (!($job['ok'] ?? false)) {
            return false;
        }

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
    public static function finish(array $job): array
    {
        if (!($job['ok'] ?? false)) {
            $job['duration_ms'] = max(0, self::nowMs() - (int)($job['start_ms'] ?? self::nowMs()));
            return $job;
        }

        $stdout = (string)stream_get_contents($job['pipes'][1]);
        $stderr = (string)stream_get_contents($job['pipes'][2]);
        fclose($job['pipes'][1]);
        fclose($job['pipes'][2]);

        $code = is_resource($job['proc']) ? (int)proc_close($job['proc']) : 127;

        $job['code'] = $code;
        $job['stdout'] = $stdout;
        $job['stderr'] = $stderr;
        $job['duration_ms'] = max(0, self::nowMs() - (int)($job['start_ms'] ?? self::nowMs()));
        return $job;
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
