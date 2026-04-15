<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use Testkit\Core\Common\Trace;

require_once __DIR__ . '/SeedFailure.php';
require_once __DIR__ . '/SeedMaterializer.php';
require_once __DIR__ . '/SeedRuntimeContext.php';
require_once __DIR__ . '/SqlSeedExecutor.php';

final class FlatSeedMaterializer implements SeedMaterializer
{
    public function run(SeedRuntimeContext $context): int
    {
        $files = SqlSeedExecutor::listFlatFiles($context->seedDir());
        if ($files === []) {
            throw new SeedFailure('No hay archivos SQL para seed en modo flat.', [
                'stage' => 'flat_discovery',
                'driver' => $context->driver(),
                'db_driver' => $context->driver(),
                'file' => $context->realPathOrOriginal($context->seedDir()),
                'hint' => 'Agregá .sql en ' . $context->realPathOrOriginal($context->seedDir()) . ' o en el subdirectorio seeds/.',
            ]);
        }

        try {
            $pdo = $context->adapter()->connect();
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, 'No se pudo conectar a la DB para ejecutar el seed flat.', [
                'stage' => 'connect',
                'driver' => $context->driver(),
                'db_driver' => $context->driver(),
                'label' => 'flat',
                'db_name' => $context->connectionSummary()['db'] ?? '',
                'hint' => 'Revisá host, puerto, usuario y password de la conexión usada por testkit.',
            ]);
        }

        Trace::log('seed.flat.files', [
            'count' => count($files),
            'files' => $context->normalizePaths($files),
        ]);

        foreach ($files as $file) {
            SqlSeedExecutor::applySqlFile($pdo, $file, 'flat', [
                'driver' => $context->driver(),
                'db_driver' => $context->driver(),
                'label' => 'flat',
            ]);
        }

        echo 'Seeds aplicadas: ' . count($files) . "\n";
        return 0;
    }
}
