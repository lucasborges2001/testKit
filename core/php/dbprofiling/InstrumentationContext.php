<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling;

use Testkit\Core\Common\Paths;

final class InstrumentationContext
{
    private const CONTEXT_KEYS = [
        'run_id',
        'meta_run_id',
        'suite_id',
        'test_id',
        'test_path',
        'worker_id',
        'process_id',
        'module_id',
        'scenario_id',
        'source',
        'caller',
        'capture_method',
        'connection_id',
    ];

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    public static function current(array $overrides = []): array
    {
        $script = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
        $envTest = self::envFirst(['TESTKIT_DB_PROFILE_TEST_PATH', 'TEST_TEST_PATH', 'TEST_FILE']);
        $testPath = $envTest !== '' ? $envTest : $script;

        $base = [
            'run_id' => self::envFirst(['TESTKIT_DB_PROFILE_RUN_ID', 'TEST_RUN_ID']),
            'meta_run_id' => self::envFirst(['TESTKIT_DB_PROFILE_META_RUN_ID', 'TEST_META_RUN_ID']) ?: self::sessionMetadata('meta_run_id'),
            'suite_id' => self::envFirst(['TESTKIT_DB_PROFILE_SUITE_ID', 'TEST_SUITE_ID', 'TEST_SUITE']),
            'test_id' => self::envFirst(['TESTKIT_DB_PROFILE_TEST_ID', 'TEST_ID']),
            'test_path' => self::normalizePath($testPath),
            'worker_id' => self::envFirst(['TESTKIT_DB_PROFILE_WORKER_ID', 'TEST_WORKER_ID']),
            'process_id' => getmypid(),
            'module_id' => self::envFirst(['TESTKIT_DB_PROFILE_MODULE_ID', 'TEST_MODULE_ID']),
            'scenario_id' => self::envFirst(['TESTKIT_DB_PROFILE_SCENARIO_ID', 'TEST_SCENARIO_ID']),
            'source' => '',
            'caller' => '',
            'capture_method' => MysqlCaptureMethod::UNKNOWN,
            'connection_id' => '',
        ];

        foreach ($overrides as $key => $value) {
            if (in_array((string)$key, self::CONTEXT_KEYS, true)) {
                $base[(string)$key] = $value;
            }
        }

        return self::normalize($base);
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public static function normalize(array $context): array
    {
        $normalized = [];
        foreach (self::CONTEXT_KEYS as $key) {
            $value = $context[$key] ?? '';
            if ($key === 'process_id') {
                $normalized[$key] = is_numeric($value) ? max(0, (int)$value) : 0;
                continue;
            }
            if ($key === 'capture_method') {
                $normalized[$key] = MysqlCaptureMethod::normalize((string)$value);
                continue;
            }
            if (in_array($key, ['test_path', 'source'], true)) {
                $normalized[$key] = self::normalizePath((string)$value);
                continue;
            }
            if ($key === 'caller') {
                $normalized[$key] = self::normalizeCaller((string)$value);
                continue;
            }
            $normalized[$key] = self::sanitizeIdentifier((string)$value, 160);
        }
        return $normalized;
    }

    public static function normalizePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }

        $line = '';
        if (preg_match('/^(.*):(\d+)$/', $path, $match) === 1 && !preg_match('/^[A-Za-z]:\//', $path)) {
            $path = (string)$match[1];
            $line = ':' . (string)$match[2];
        }

        $path = Paths::normalize($path);
        $relative = Paths::relativeToRepo($path);
        if ($relative === $path && self::isAbsolutePath($path)) {
            $relative = basename($path);
        }

        return self::sanitizeText($relative, 240) . $line;
    }

    public static function normalizeCaller(string $caller): string
    {
        $caller = trim(str_replace('\\', '/', $caller));
        if ($caller === '') {
            return '';
        }
        if (preg_match('/^(.*):(\d+)$/', $caller, $match) === 1) {
            return self::normalizePath((string)$match[1]) . ':' . (string)$match[2];
        }
        return self::normalizePath($caller);
    }

    public static function sanitizeIdentifier(string $value, int $maxLength = 160): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = self::redactSecrets($value);
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', '', $value) ?? '';
        return substr($value, 0, max(1, $maxLength));
    }

    public static function sanitizeText(string $value, int $maxLength = 320): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = self::redactSecrets($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $value) ?? '';
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return substr($value, 0, max(1, $maxLength));
    }

    /** @param array<string,mixed> $map @return array<string,mixed> */
    public static function sanitizeMap(array $map): array
    {
        $out = [];
        foreach ($map as $key => $value) {
            $key = self::sanitizeIdentifier((string)$key, 80);
            if ($key === '' || self::looksSensitiveKey($key)) {
                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $out[$key] = $value;
            } elseif (is_string($value)) {
                $out[$key] = self::sanitizeText($value, 240);
            } elseif (is_array($value)) {
                $out[$key] = self::sanitizeMap($value);
            }
            if (count($out) >= 40) {
                break;
            }
        }
        return $out;
    }

    public static function connectionId(object $connection, string $adapter = 'pdo'): string
    {
        $seed = implode('|', [
            self::envFirst(['TESTKIT_DB_PROFILE_RUN_ID', 'TEST_RUN_ID']),
            (string)getmypid(),
            $adapter,
            (string)spl_object_id($connection),
        ]);
        return 'conn_' . substr(hash('sha256', $seed), 0, 16);
    }

    private static function sessionMetadata(string $key): string
    {
        $dir = self::envFirst(['TESTKIT_DB_PROFILE_SHARD_DIR']);
        if ($dir === '') {
            return '';
        }
        $marker = rtrim(str_replace('\\', '/', $dir), '/') . '/.session.json';
        if (!is_file($marker)) {
            return '';
        }
        $payload = json_decode((string)file_get_contents($marker), true);
        return is_array($payload) ? self::sanitizeIdentifier((string)($payload[$key] ?? ''), 160) : '';
    }

    private static function envFirst(array $keys): string
    {
        foreach ($keys as $key) {
            $value = getenv((string)$key);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        return '';
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1;
    }

    private static function looksSensitiveKey(string $key): bool
    {
        return preg_match('/pass(word)?|secret|token|api[_-]?key|cookie|authorization|dsn|user(name)?/i', $key) === 1;
    }

    private static function redactSecrets(string $value): string
    {
        $value = preg_replace(
            '/\b(mysql|pgsql|sqlsrv):[^;\s]*(?:;[^;\s=]+=[^;\s]*)*/i',
            '[redacted-dsn]',
            $value
        ) ?? $value;
        $value = preg_replace('/\b(?:bearer\s+)?[A-Za-z0-9_-]{24,}\b/i', '[redacted-token]', $value) ?? $value;
        $value = preg_replace('/([?&](?:pass(?:word)?|token|secret|api[_-]?key)=)[^&\s]+/i', '$1[redacted]', $value) ?? $value;
        $value = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[redacted-email]', $value) ?? $value;
        return $value;
    }
}
