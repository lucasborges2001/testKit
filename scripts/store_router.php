<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/store/bootstrap.php';
require_once __DIR__ . '/../core/php/common/Lock.php';
require_once __DIR__ . '/../core/php/seeding/SeedPipeline.php';

use Testkit\Core\Common\Lock;
use Testkit\Core\Seeding\SeedPipeline;
use Testkit\Core\Store\StoreMaintenance;
use Testkit\Core\Store\StoreRegistry;

$action = strtolower(trim((string)($argv[1] ?? 'seed')));
$driverArg = (string)($argv[2] ?? '');
$driver = $driverArg !== '' ? StoreRegistry::normalizeDriver($driverArg) : StoreRegistry::detectDriver('mysql');
$projectRoot = rtrim((string)(getenv('TK_REPO_ROOT') ?: getenv('TESTKIT_PROJECT_ROOT') ?: '/workspace/project'), "/\\");

$adapter = StoreRegistry::fromDriver($driver);
$dbName = 'default';
try {
    $resolvedDb = trim((string)$adapter->resolveDatabaseName());
    if ($resolvedDb !== '') {
        $dbName = $resolvedDb;
    }
} catch (Throwable) {
    // dejamos default; el error contractual real lo dará el adapter al ejecutar la acción.
}

$lockKey = 'store_action.' . safe_lock_segment($driver) . '.' . safe_lock_segment($dbName);
$lease = Lock::acquire($lockKey, true);

try {
    switch ($action) {
        case 'provision':
            StoreMaintenance::provision($driver);
            exit(0);

        case 'reset':
            StoreMaintenance::reset($driver);
            exit(0);

        case 'clean':
            StoreMaintenance::clean($driver);
            exit(0);

        case 'seed':
            exit(SeedPipeline::run($driver, $projectRoot));

        case 'bootstrap':
            StoreMaintenance::provision($driver);
            exit(SeedPipeline::run($driver, $projectRoot));

        default:
            throw new RuntimeException('Accion invalida. Usa provision|reset|clean|seed|bootstrap.');
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[store_router] ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    $lease?->release();
}

function safe_lock_segment(string $value): string
{
    $value = preg_replace('/[^a-z0-9._-]+/i', '_', strtolower(trim($value))) ?: '';
    $value = trim($value, '._-');
    return $value !== '' ? $value : 'default';
}
