<?php
declare(strict_types=1);

namespace Testkit\Core\Common;

final class Trace
{
    public static function migrationsEnabled(): bool
    {
        return self::envEnabled('TESTKIT_TRACE_MIGRATIONS');
    }

    public static function log(string $message, array $context = []): void
    {
        if (!self::migrationsEnabled()) {
            return;
        }

        $suffix = '';
        if ($context !== []) {
            $pairs = [];
            foreach ($context as $key => $value) {
                $pairs[] = $key . '=' . self::stringify($value);
            }
            $suffix = ' [' . implode(' ', $pairs) . ']';
        }

        fwrite(STDERR, '[testkit-trace] ' . $message . $suffix . PHP_EOL);
    }

    private static function envEnabled(string $name): bool
    {
        $raw = getenv($name);
        if ($raw === false) {
            return false;
        }

        return in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on', 'debug'], true);
    }

    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded !== false ? $encoded : '[unserializable]';
    }
}
