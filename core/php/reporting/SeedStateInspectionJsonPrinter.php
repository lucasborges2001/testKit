<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

final class SeedStateInspectionJsonPrinter
{
    /** @param array<string,mixed> $payload */
    public static function print(array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException('no se pudo serializar la salida JSON de inspect seed-state');
        }

        echo $json . PHP_EOL;
    }
}
