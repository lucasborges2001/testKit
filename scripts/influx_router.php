<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/influx/bootstrap.php';

use Testkit\Core\Influx\InfluxClient;
use Testkit\Core\Influx\InfluxConfig;
use Testkit\Core\Influx\InfluxLineProtocol;
use Testkit\Core\Influx\InfluxTestRuntime;

$action = strtolower(trim((string) ($argv[1] ?? 'health')));
$measurement = trim((string) ($argv[2] ?? 'testkit_smoke'));
$client = new InfluxClient(new InfluxConfig());

try {
    switch ($action) {
        case 'health':
            fwrite(STDOUT, json_encode($client->health(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            exit(0);

        case 'ready':
            fwrite(STDOUT, json_encode($client->ready(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            exit(0);

        case 'bucket-ensure':
            $client->ensureBucketExists();
            fwrite(STDOUT, "bucket-ready\n");
            exit(0);

        case 'write-smoke':
            $line = InfluxLineProtocol::point(
                $measurement,
                [
                    'source' => 'testkit',
                    (new InfluxConfig())->runTagKey() => InfluxTestRuntime::runId(),
                ],
                [
                    'ok' => true,
                    'value' => 1,
                    'note' => 'smoke',
                ],
                time() * 1000000000
            );
            $client->write($line);
            fwrite(STDOUT, $line . PHP_EOL);
            exit(0);

        case 'query-smoke':
            $bucket = (new InfluxConfig())->bucket();
            $runTagKey = (new InfluxConfig())->runTagKey();
            $runId = InfluxTestRuntime::runId();
            $flux = <<<FLUX
from(bucket: "{$bucket}")
  |> range(start: -6h)
  |> filter(fn: (r) => r["_measurement"] == "{$measurement}")
  |> filter(fn: (r) => r["{$runTagKey}"] == "{$runId}")
FLUX;
            fwrite(STDOUT, $client->queryCsv($flux));
            exit(0);

        case 'purge-run':
            $runId = trim((string) ($argv[2] ?? InfluxTestRuntime::runId()));
            $client->purgeRun($runId);
            fwrite(STDOUT, "purged-run={$runId}\n");
            exit(0);

        default:
            throw new RuntimeException(
                'Accion invalida. Usa health|ready|bucket-ensure|write-smoke|query-smoke|purge-run.'
            );
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[influx_router] ' . trim($e->getMessage()) . PHP_EOL);
    exit(1);
}
