<?php
declare(strict_types=1);

namespace Testkit\Core\Store;

use PDO;

interface StoreAdapter
{
    public function driver(): string;

    public function resolveDatabaseName(): string;

    public function connect(?string $database = null): PDO;

    public function provision(?string $database = null): void;

    public function reset(PDO $pdo): void;

    public function clean(PDO $pdo): void;

    public function databaseExists(string $database): bool;

    public function dropDatabase(string $database): void;

    public function cloneDatabase(string $sourceDatabase, string $targetDatabase): void;

    public function restoreSnapshot(string $artifactPath, ?string $database = null): void;
}
