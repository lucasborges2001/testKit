<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/store/bootstrap.php';
require_once __DIR__ . '/../core/php/common/Lock.php';
require_once __DIR__ . '/../core/php/seeding/SeedPipeline.php';
require_once __DIR__ . '/../core/php/seeding/BaselineManifest.php';
require_once __DIR__ . '/../core/php/seeding/SeedFailure.php';
require_once __DIR__ . '/../core/php/suites/ContractWorldBootstrap.php';

use Testkit\Core\Common\Lock;
use Testkit\Core\Seeding\BaselineManifest;
use Testkit\Core\Seeding\SeedFailure;
use Testkit\Core\Seeding\SeedPipeline;
use Testkit\Core\Store\StoreMaintenance;
use Testkit\Core\Store\StoreRegistry;
use Testkit\Core\Suites\ContractWorldBootstrap;

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
        case 'prepare-baseline':
            ContractWorldBootstrap::prepare('cli', $projectRoot, $driver, false);
            exit(0);

        case 'drop-database':
            $targetDb = trim((string)($argv[3] ?? ''));
            if ($targetDb === '') {
                throw new RuntimeException('drop-database requiere nombre de DB en argv[3].');
            }
            StoreMaintenance::dropDatabase($driver, $targetDb);
            exit(0);

        case 'clone-database':
            $sourceDb = trim((string)($argv[3] ?? ''));
            $targetDb = trim((string)($argv[4] ?? ''));
            if ($sourceDb === '' || $targetDb === '') {
                throw new RuntimeException('clone-database requiere source_db y target_db.');
            }
            StoreMaintenance::cloneDatabase($driver, $sourceDb, $targetDb);
            exit(0);

        case 'invalidate-baseline':
            $targetDb = trim((string)($argv[3] ?? $dbName));
            $manifestPath = BaselineManifest::pathFor($projectRoot, $driver, $targetDb);
            if (StoreMaintenance::databaseExists($driver, $targetDb)) {
                StoreMaintenance::dropDatabase($driver, $targetDb);
            }
            BaselineManifest::delete($manifestPath);
            exit(0);

        default:
            throw new RuntimeException(
                'Accion invalida. Usa provision|reset|clean|seed|bootstrap|prepare-baseline|drop-database|clone-database|invalidate-baseline.'
            );
    }
} catch (Throwable $e) {
    fwrite(STDERR, render_store_router_error($e));
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

/**
 * @param Throwable $error
 */
function render_store_router_error(Throwable $error): string
{
    if ($error instanceof SeedFailure) {
        return '[store_router] ' . $error->getMessage() . "\n";
    }

    return '[store_router] ' . trim($error->getMessage()) . "\n";
}
