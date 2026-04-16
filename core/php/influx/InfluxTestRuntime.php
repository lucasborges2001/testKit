<?php
declare(strict_types=1);

namespace Testkit\Core\Influx;

final class InfluxTestRuntime
{
    private static ?string $runId = null;

    public static function client(): InfluxClient
    {
        return new InfluxClient(new InfluxConfig());
    }

    public static function runId(): string
    {
        if (self::$runId !== null) {
            return self::$runId;
        }

        $candidates = [
            getenv('TEST_INFLUX_RUN_ID') ?: null,
            getenv('TEST_RUN_ID') ?: null,
            getenv('TEST_RAND_SEED') ?: null,
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                self::$runId = $candidate;
                return self::$runId;
            }
        }

        self::$runId = 'run_' . date('Ymd_His') . '_' . getmypid();
        return self::$runId;
    }

    /**
     * @param array<string,string|int|float|bool> $tags
     * @param array<string,string|int|float|bool> $fields
     */
    public static function writePoint(string $measurement, array $tags, array $fields, ?int $timestamp = null): void
    {
        $config = new InfluxConfig();
        $tags[$config->runTagKey()] = self::runId();

        $line = InfluxLineProtocol::point($measurement, $tags, $fields, $timestamp);
        self::client()->write($line);
    }

    public static function purgeCurrentRun(): void
    {
        self::client()->purgeRun(self::runId());
    }
}
