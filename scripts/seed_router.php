<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/seeding/SeedPipeline.php';

use Testkit\Core\Seeding\SeedPipeline;

$driver = (string)($argv[1] ?? 'mysql');
$projectRoot = rtrim((string)(getenv('TK_REPO_ROOT') ?: getenv('TESTKIT_PROJECT_ROOT') ?: '/workspace/project'), "/\\");

try {
    exit(SeedPipeline::run($driver, $projectRoot));
} catch (Throwable $e) {
    fwrite(STDERR, '[seed_router] ' . $e->getMessage() . "\n");
    exit(1);
}
