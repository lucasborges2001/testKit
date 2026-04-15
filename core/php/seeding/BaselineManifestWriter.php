<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use Testkit\Core\Common\Trace;

require_once __DIR__ . '/BaselineManifest.php';
require_once __DIR__ . '/SeedRuntimeContext.php';

final class BaselineManifestWriter
{
    /**
     * @param array<string,mixed> $manifestPlan
     */
    public static function write(string $manifestPath, array $manifestPlan, SeedRuntimeContext $context): void
    {
        $payload = [
            'status' => 'ready',
            'driver' => $context->driver(),
            'db_name' => $context->databaseName(),
            'baseline_mode' => $context->baselineMode(),
            'baseline_fingerprint' => (string)($manifestPlan['fingerprint'] ?? ''),
            'generated_at' => gmdate(DATE_ATOM),
            'project_root' => $context->realPathOrOriginal($context->projectRoot()),
            'seed_dir' => $context->realPathOrOriginal($context->seedDir()),
            'manifest_path' => $context->realPathOrOriginal($manifestPath),
            'resolved_snapshot' => $context->resolvedSnapshot(),
            'migration_state' => $manifestPlan['migration_state'] ?? null,
            'plan' => $manifestPlan,
        ];

        BaselineManifest::save($manifestPath, $payload);

        Trace::log('baseline.manifest.write', [
            'driver' => $context->driver(),
            'db' => $context->databaseName(),
            'manifest_path' => $context->realPathOrOriginal($manifestPath),
            'resolved_snapshot' => $context->resolvedSnapshot(),
            'baseline_mode' => $context->baselineMode(),
            'fingerprint' => (string)($manifestPlan['fingerprint'] ?? ''),
        ]);
    }
}
