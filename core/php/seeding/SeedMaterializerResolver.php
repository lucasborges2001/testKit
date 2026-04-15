<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

require_once __DIR__ . '/FlatSeedMaterializer.php';
require_once __DIR__ . '/LayeredSeedMaterializer.php';
require_once __DIR__ . '/SeedMaterializer.php';
require_once __DIR__ . '/SeedRuntimeContext.php';
require_once __DIR__ . '/SnapshotSeedMaterializer.php';

final class SeedMaterializerResolver
{
    public static function resolve(SeedRuntimeContext $context): SeedMaterializer
    {
        if ($context->baselineMode() === 'snapshot') {
            return new SnapshotSeedMaterializer();
        }

        if (self::hasLayeredLayout($context->seedDir())) {
            return new LayeredSeedMaterializer();
        }

        return new FlatSeedMaterializer();
    }

    private static function hasLayeredLayout(string $seedDir): bool
    {
        return is_dir($seedDir . '/schema') && is_dir($seedDir . '/base');
    }
}
