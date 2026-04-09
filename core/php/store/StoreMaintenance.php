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

    public static function databaseExists(string $driver, string $database): bool
    {
        $adapter = StoreRegistry::fromDriver($driver);
        return $adapter->databaseExists($database);
    }

    public static function dropDatabase(string $driver, string $database): void
    {
        $adapter = StoreRegistry::fromDriver($driver);
        $adapter->dropDatabase($database);
    }

    public static function cloneDatabase(string $driver, string $sourceDatabase, string $targetDatabase): void
    {
        $adapter = StoreRegistry::fromDriver($driver);
        $adapter->cloneDatabase($sourceDatabase, $targetDatabase);
    }

    public static function restoreSnapshot(string $driver, string $artifactPath, ?string $database = null): void
    {
        $adapter = StoreRegistry::fromDriver($driver);
        $adapter->restoreSnapshot($artifactPath, $database);
    }
}
