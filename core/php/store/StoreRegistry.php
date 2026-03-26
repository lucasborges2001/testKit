<?php
declare(strict_types=1);

namespace Testkit\Core\Store;

final class StoreRegistry
{
    public static function normalizeDriver(string $driver): string
    {
        $driver = strtolower(trim($driver));
        if (str_starts_with($driver, 'pg')) {
            return 'pgsql';
        }

        if ($driver === 'mysql' || $driver === '') {
            return 'mysql';
        }

        throw new \RuntimeException('Driver inválido. Usá mysql|pgsql');
    }

    public static function detectDriver(string $fallback = 'mysql'): string
    {
        $driver = (string)(getenv('DB_DRIVER') ?: getenv('TEST_DB_DRIVER') ?: '');
        if ($driver === '') {
            $dsn = trim((string)(getenv('TEST_DB_DSN') ?: ''));
            if ($dsn !== '') {
                $driver = (string)strtok($dsn, ':');
            }
        }

        if ($driver === '') {
            if ((string)(getenv('PG_DB') ?: getenv('TEST_PG_DB') ?: '') !== '') {
                $driver = 'pgsql';
            } else {
                $driver = $fallback;
            }
        }

        return self::normalizeDriver($driver);
    }

    public static function fromDriver(string $driver): StoreAdapter
    {
        return match (self::normalizeDriver($driver)) {
            'mysql' => new MysqlStoreAdapter(),
            'pgsql' => new PgsqlStoreAdapter(),
        };
    }
}
