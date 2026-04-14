<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/seeding/SeedPipeline.php';
require_once __DIR__ . '/../core/php/seeding/SeedFailure.php';

use Testkit\Core\Seeding\SeedFailure;
use Testkit\Core\Seeding\SeedPipeline;

$driver = (string)($argv[1] ?? 'mysql');
$projectRoot = rtrim((string)(getenv('TK_REPO_ROOT') ?: getenv('TESTKIT_PROJECT_ROOT') ?: '/workspace/project'), "/\\");

try {
    exit(SeedPipeline::run($driver, $projectRoot));
} catch (Throwable $e) {
    fwrite(STDERR, render_seed_router_error($e));
    exit(1);
}

/**
 * @param Throwable $error
 */
function render_seed_router_error(Throwable $error): string
{
    if ($error instanceof SeedFailure) {
        return '[seed_router] ' . $error->getMessage() . "\n";
    }

    return '[seed_router] ' . trim($error->getMessage()) . "\n";
}
