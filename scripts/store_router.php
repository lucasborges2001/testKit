<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/store/bootstrap.php';
require_once __DIR__ . '/../core/php/seeding/SeedPipeline.php';

use Testkit\Core\Seeding\SeedPipeline;
use Testkit\Core\Store\StoreMaintenance;
use Testkit\Core\Store\StoreRegistry;

$action = strtolower(trim((string)($argv[1] ?? 'seed')));
$driverArg = (string)($argv[2] ?? '');
$driver = $driverArg !== '' ? StoreRegistry::normalizeDriver($driverArg) : StoreRegistry::detectDriver('mysql');
$projectRoot = rtrim((string)(getenv('TK_REPO_ROOT') ?: getenv('TESTKIT_PROJECT_ROOT') ?: '/workspace/project'), "/\\");

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
}
