<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

final class SeedConsoleNarrative
{
    /** @var array<string,bool> */
    private static array $seenSignatures = [];

    private static bool $compact = false;
    private static string $currentSignature = '';

    public static function beginSuiteBootstrap(
        string $suiteId,
        string $driver,
        string $strategy,
        string $baselineMode,
        string $databaseKey = ''
    ): void {
        $signature = self::signature($driver, $strategy, $baselineMode, $databaseKey);
        self::$compact = isset(self::$seenSignatures[$signature]);
        self::$currentSignature = $signature;
        self::$seenSignatures[$signature] = true;

        $message = sprintf(
            '[testkit] bootstrap suite=%s driver=%s strategy=%s',
            $suiteId,
            $driver,
            $strategy
        );

        if (self::$compact) {
            $message .= sprintf(
                ' narrative=reused baseline_mode=%s resource=%s',
                $baselineMode,
                self::resourceLabel($driver, $databaseKey)
            );
        }

        fwrite(STDERR, $message . PHP_EOL);
    }

    public static function isCompact(): bool
    {
        return self::$compact;
    }

    public static function printCompletion(SeedRuntimeContext $context, string $successMessage): void
    {
        if (!self::$compact) {
            echo $successMessage . PHP_EOL;
            return;
        }

        $resource = self::resourceLabel($context->driver(), $context->databaseName());
        echo sprintf(
            '[testkit] bootstrap detail deduped baseline_mode=%s resource=%s',
            $context->baselineMode(),
            $resource
        ) . PHP_EOL;
    }

    private static function signature(string $driver, string $strategy, string $baselineMode, string $databaseKey): string
    {
        $databaseKey = trim(strtolower($databaseKey));
        if ($databaseKey === '') {
            $databaseKey = '_unknown';
        }

        return strtolower(trim($driver))
            . '|'
            . strtolower(trim($strategy))
            . '|'
            . strtolower(trim($baselineMode))
            . '|'
            . $databaseKey;
    }

    private static function resourceLabel(string $driver, string $databaseKey): string
    {
        $databaseKey = trim($databaseKey);
        return $databaseKey !== '' ? ($driver . '/' . $databaseKey) : $driver;
    }
}
