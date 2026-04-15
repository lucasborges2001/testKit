<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use PDO;

require_once __DIR__ . '/SeedFailure.php';
require_once __DIR__ . '/SeedRuntimeContext.php';

final class SeedDatabaseLifecycle
{
    public static function connect(
        SeedRuntimeContext $context,
        string $label,
        string $message,
        string $hint
    ): PDO {
        try {
            return $context->adapter()->connect();
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, $message, [
                'stage' => 'connect',
                'driver' => $context->driver(),
                'db_driver' => $context->driver(),
                'label' => $label,
                'db_name' => $context->connectionSummary()['db'] ?? '',
                'hint' => $hint,
            ]);
        }
    }

    /**
     * @param array<string,mixed> $extraContext
     */
    public static function reset(
        SeedRuntimeContext $context,
        PDO $pdo,
        string $label,
        string $message,
        string $hint,
        array $extraContext = []
    ): void {
        try {
            $context->adapter()->reset($pdo);
        } catch (\Throwable $e) {
            throw SeedFailure::wrap($e, $message, array_merge($extraContext, [
                'stage' => 'reset',
                'driver' => $context->driver(),
                'db_driver' => $context->driver(),
                'db_name' => $context->currentDatabaseName($pdo),
                'label' => $label,
                'hint' => $hint,
            ]));
        }
    }
}
