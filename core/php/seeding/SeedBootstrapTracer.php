<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use Testkit\Core\Common\Trace;

require_once __DIR__ . '/BaselineModeResolver.php';
require_once __DIR__ . '/SeedRuntimeContext.php';

final class SeedBootstrapTracer
{
    public static function trace(SeedRuntimeContext $context, string $manifestPath): void
    {
        Trace::log('seed.bootstrap.context', [
            'driver' => $context->driver(),
            'project_root' => $context->realPathOrOriginal($context->projectRoot()),
            'seed_dir' => $context->realPathOrOriginal($context->seedDir()),
            'baseline_mode' => $context->baselineMode(),
            'db_name' => $context->databaseName(),
            'baseline_reuse' => BaselineModeResolver::reuseEnabled(),
            'baseline_invalidate' => BaselineModeResolver::invalidateRequested(),
            'baseline_manifest_path' => $context->realPathOrOriginal($manifestPath),
            'resolved_snapshot' => $context->resolvedSnapshot(),
            'DB_ENV_PATH' => (string)(getenv('DB_ENV_PATH') ?: ''),
            'TESTKIT_PROJECT_ROOT' => (string)(getenv('TESTKIT_PROJECT_ROOT') ?: ''),
            'TK_REPO_ROOT' => (string)(getenv('TK_REPO_ROOT') ?: ''),
            'TEST_MATCH' => (string)(getenv('TEST_MATCH') ?: ''),
        ]);
    }
}
