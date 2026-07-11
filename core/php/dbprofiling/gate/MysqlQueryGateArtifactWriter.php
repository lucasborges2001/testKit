<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Gate;

use Testkit\Core\Common\Paths;

final class MysqlQueryGateArtifactWriter
{
    /** @param array<string,mixed> $payload */
    public static function writeJson(string $path, array $payload): void
    {
        $json = json_encode(
            self::sanitizeRecursive($payload),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        self::writeText($path, $json . PHP_EOL);
    }

    public static function writeText(string $path, string $content): void
    {
        if ($path === '') {
            return;
        }
        self::assertSafeOutputPath($path);
        Paths::ensureDir(dirname($path));
        $tmp = $path . '.tmp.' . getmypid() . '.' . self::token(10);
        $handle = @fopen($tmp, 'xb');
        if (!is_resource($handle)) {
            throw new MysqlQueryGateException(
                'Unable to create temporary gate artifact.',
                '$.outputs',
                'gate_artifact_temp_create_failed',
                MysqlQueryGateConfig::EXIT_OPERATIONAL
            );
        }
        try {
            $bytes = fwrite($handle, $content);
            if ($bytes === false || $bytes !== strlen($content)) {
                throw new MysqlQueryGateException(
                    'Unable to write gate artifact.',
                    '$.outputs',
                    'gate_artifact_write_failed',
                    MysqlQueryGateConfig::EXIT_OPERATIONAL
                );
            }
            if (!fflush($handle)) {
                throw new MysqlQueryGateException(
                    'Unable to flush gate artifact.',
                    '$.outputs',
                    'gate_artifact_flush_failed',
                    MysqlQueryGateConfig::EXIT_OPERATIONAL
                );
            }
            if (function_exists('fsync')) {
                @fsync($handle);
            }
        } finally {
            fclose($handle);
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new MysqlQueryGateException(
                'Unable to publish gate artifact atomically.',
                '$.outputs',
                'gate_artifact_publish_failed',
                MysqlQueryGateConfig::EXIT_OPERATIONAL
            );
        }
    }

    /** @return array<string,mixed> */
    public static function loadJson(string $path, int $maxBytes = 10485760): array
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new MysqlQueryGateException(
                'Gate input file is missing or unreadable.',
                '$.inputs',
                'gate_input_missing',
                MysqlQueryGateConfig::EXIT_OPERATIONAL
            );
        }
        if (is_link($path)) {
            $resolved = realpath($path);
            if ($resolved === false) {
                throw new MysqlQueryGateException(
                    'Gate input symlink cannot be resolved.',
                    '$.inputs',
                    'gate_input_symlink_invalid',
                    MysqlQueryGateConfig::EXIT_OPERATIONAL
                );
            }
        }
        $size = filesize($path);
        if ($size === false || $size > $maxBytes) {
            throw new MysqlQueryGateException(
                'Gate input exceeds the allowed size.',
                '$.inputs',
                'gate_input_too_large',
                MysqlQueryGateConfig::EXIT_INVALID_CONTRACT
            );
        }
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new MysqlQueryGateException(
                'Unable to read gate input.',
                '$.inputs',
                'gate_input_read_failed',
                MysqlQueryGateConfig::EXIT_OPERATIONAL
            );
        }
        try {
            $decoded = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MysqlQueryGateException(
                'Invalid JSON input: ' . self::sanitizeText($e->getMessage(), 180),
                '$',
                'invalid_json',
                MysqlQueryGateConfig::EXIT_INVALID_CONTRACT
            );
        }
        if (!is_array($decoded)) {
            throw new MysqlQueryGateException(
                'JSON input must be an object.',
                '$',
                'invalid_json_root',
                MysqlQueryGateConfig::EXIT_INVALID_CONTRACT
            );
        }
        return $decoded;
    }

    public static function fileHash(string $path): string
    {
        if ($path === '' || !is_file($path)) {
            return '';
        }
        $hash = hash_file('sha256', $path);
        return is_string($hash) ? strtolower($hash) : '';
    }

    public static function payloadHash(array $payload): string
    {
        $copy = self::canonicalize($payload);
        $json = json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? '' : hash('sha256', $json);
    }

    public static function sanitizeText(string $value, int $max = 240): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = preg_replace('/\b(password|passwd|secret|token|api[_-]?key|authorization|cookie|dsn|username|email)\b\s*[:=]\s*[^\s,;]+/iu', '$1=[redacted]', $value) ?? $value;
        $value = preg_replace('~(?:[A-Za-z]:[\\/]|/(?:home|Users|var|tmp|srv|opt)/)[^\s]+~u', '[path]', $value) ?? $value;
        $value = trim($value);
        if (strlen($value) > $max) {
            $value = substr($value, 0, $max - 3) . '...';
        }
        return $value;
    }

    public static function safeRelativePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '' || str_contains($path, "\0")) {
            return '';
        }
        if (preg_match('#^(?:[A-Za-z]:/|/|file://|https?://)#i', $path) === 1) {
            return '';
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return '';
            }
            $parts[] = preg_replace('/[^A-Za-z0-9._-]+/', '_', $part) ?: '_';
        }
        return implode('/', $parts);
    }

    /** @return array<string,mixed>|array<int,mixed>|string|int|float|bool|null */
    public static function sanitizeRecursive(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::sanitizeText($value, 2000);
        }
        if (!is_array($value)) {
            if (is_float($value) && !is_finite($value)) {
                return null;
            }
            return $value;
        }
        $list = array_keys($value) === range(0, count($value) - 1);
        $out = [];
        foreach ($value as $key => $item) {
            if (!$list && preg_match('/pass(word)?|secret|token|api[_-]?key|authorization|cookie|dsn|user(name)?|email/i', (string)$key) === 1) {
                continue;
            }
            $out[$key] = self::sanitizeRecursive($item);
        }
        return $list ? array_values($out) : $out;
    }

    /** @return array<string,mixed>|array<int,mixed> */
    private static function canonicalize(array $value): array
    {
        $isList = array_keys($value) === range(0, count($value) - 1);
        if (!$isList) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalize($item);
            }
        }
        return $value;
    }

    private static function assertSafeOutputPath(string $path): void
    {
        if (str_contains($path, "\0")) {
            throw new MysqlQueryGateException(
                'Output path contains a null byte.',
                '$.outputs',
                'gate_output_path_invalid',
                MysqlQueryGateConfig::EXIT_OPERATIONAL
            );
        }
        $normalized = str_replace('\\', '/', $path);
        if (preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1) {
            throw new MysqlQueryGateException(
                'Output path traversal is not allowed.',
                '$.outputs',
                'gate_output_path_traversal',
                MysqlQueryGateConfig::EXIT_OPERATIONAL
            );
        }
        if (is_link($path)) {
            throw new MysqlQueryGateException(
                'Gate output may not replace a symlink.',
                '$.outputs',
                'gate_output_symlink_rejected',
                MysqlQueryGateConfig::EXIT_OPERATIONAL
            );
        }
    }

    private static function token(int $length): string
    {
        try {
            return substr(bin2hex(random_bytes((int)ceil($length / 2))), 0, $length);
        } catch (\Throwable) {
            return substr(hash('sha256', uniqid('', true)), 0, $length);
        }
    }
}
