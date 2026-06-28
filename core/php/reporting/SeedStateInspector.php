<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

require_once __DIR__ . '/SeedStateInspectionCliArgs.php';
require_once __DIR__ . '/SeedStateInspectionJsonPrinter.php';
require_once __DIR__ . '/SeedStateInspectionPayloadBuilder.php';
require_once __DIR__ . '/SeedStateInspectionTextPrinter.php';

final class SeedStateInspector
{
    /**
     * Intercepts only `inspect seed-state` so the rest of inspect can keep using
     * the existing Inspector implementation unchanged.
     *
     * @param array<int,string> $argv
     */
    public static function maybeHandleCli(array $argv): ?int
    {
        $parsed = SeedStateInspectionCliArgs::parse($argv);
        if ((string)$parsed['command'] !== 'seed-state') {
            return null;
        }

        $options = is_array($parsed['options'] ?? null) ? $parsed['options'] : [];

        try {
            $payload = SeedStateInspectionPayloadBuilder::build(
                (string)($options['run'] ?? ''),
                SeedStateInspectionCliArgs::normalizeSuiteId((string)($options['suite'] ?? ''))
            );
        } catch (\Throwable $e) {
            return self::printError($e, (bool)($options['json'] ?? false));
        }

        if ((bool)($options['json'] ?? false)) {
            SeedStateInspectionJsonPrinter::print($payload);
            return 0;
        }

        SeedStateInspectionTextPrinter::print($payload);
        return 0;
    }

    private static function printError(\Throwable $e, bool $json): int
    {
        if ($json) {
            SeedStateInspectionJsonPrinter::print([
                'ok' => false,
                'error' => $e->getMessage(),
            ]);
        } else {
            fwrite(STDERR, 'inspect error: ' . $e->getMessage() . PHP_EOL);
        }

        return 2;
    }
}
