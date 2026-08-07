<?php
declare(strict_types=1);

namespace Testkit\Core\Store;

final class StoreDriverContractException extends \RuntimeException
{
    public function __construct(
        private readonly string $contractCode,
        string $message
    ) {
        parent::__construct('[' . $contractCode . '] ' . $message);
    }

    public function contractCode(): string
    {
        return $this->contractCode;
    }
}

final class StoreRegistry
{
    public const DRIVER_ENV = 'TEST_STORE_DRIVER';
    public const ALLOWED_DRIVERS = ['mysql', 'pgsql', 'none'];

    public static function normalizeDriver(string $driver): string
    {
        if (!in_array($driver, self::ALLOWED_DRIVERS, true)) {
            throw new StoreDriverContractException(
                'TEST_STORE_DRIVER_INVALID',
                "Driver inválido '{$driver}'. Valores válidos exactos: mysql|pgsql|none."
            );
        }

        return $driver;
    }

    public static function detectDriver(): string
    {
        $driver = getenv(self::DRIVER_ENV);
        if ($driver === false || $driver === '') {
            throw new StoreDriverContractException(
                'TEST_STORE_DRIVER_REQUIRED',
                'TEST_STORE_DRIVER es obligatorio. Declaralo explícitamente como mysql|pgsql|none.'
            );
        }

        return self::normalizeDriver((string)$driver);
    }

    public static function fromDriver(string $driver): StoreAdapter
    {
        return match (self::normalizeDriver($driver)) {
            'mysql' => new MysqlStoreAdapter(),
            'pgsql' => new PgsqlStoreAdapter(),
            default => throw new \RuntimeException('Driver sin store no tiene adapter estructural.'),
        };
    }
}
