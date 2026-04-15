<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

interface SeedMaterializer
{
    public function run(SeedRuntimeContext $context): int;
}
