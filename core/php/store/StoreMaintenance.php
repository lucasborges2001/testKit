<?php
declare(strict_types=1);

namespace Testkit\Core\Store;

final class StoreMaintenance
{
    public static function provision(string $driver): void
    {
        $adapter = StoreRegistry::fromDriver($driver);
        $adapter->provision();
    }

    public static function reset(string $driver): void
    {
        $adapter = StoreRegistry::fromDriver($driver);
        $pdo = $adapter->connect();
        $adapter->reset($pdo);
    }

    public static function clean(string $driver): void
    {
        $adapter = StoreRegistry::fromDriver($driver);
        $pdo = $adapter->connect();
        $adapter->clean($pdo);
    }
}
