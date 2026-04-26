<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
putenv('TESTKIT_ROOT=' . $root);
putenv('TESTKIT_INFLUX_PROFILE=1');
putenv('TESTKIT_INFLUX_PROFILE_RUN_ID=' . (getenv('TESTKIT_INFLUX_PROFILE_RUN_ID') ?: 'influx_simulated'));

require_once $root . '/core/php/influxprofiling/public_api.php';

use Testkit\Core\InfluxProfiling\InfluxProfileCollector;
use Testkit\Core\InfluxProfiling\InfluxProfileReporter;

InfluxProfileReporter::prepareRun((string)getenv('TESTKIT_INFLUX_PROFILE_RUN_ID'));
InfluxProfileCollector::record(
    'from(bucket: "prod_metrics") |> range(start: -1h) |> filter(fn: (r) => r._measurement == "charger_sessions" and r.charger_id == "ABC123") |> aggregateWindow(every: 5m, fn: mean)',
    42.5,
    'flux',
    'examples/influx-query-profiling/simulated_profile.php'
);
InfluxProfileCollector::record(
    'from(bucket: "prod_metrics") |> filter(fn: (r) => r._measurement == "charger_sessions")',
    86.0,
    'flux',
    'examples/influx-query-profiling/simulated_profile.php'
);
InfluxProfileCollector::record(
    'from(bucket: "prod_metrics") |> range(start: -12h) |> pivot(rowKey:["_time"], columnKey:["_field"], valueColumn:"_value") |> filter(fn: (r) => r.charger_id == "ABC123")',
    320.0,
    'flux',
    'examples/influx-query-profiling/simulated_profile.php'
);
InfluxProfileCollector::record(
    'join(tables: {left: usage, right: sessions}, on: ["_time", "charger_id"])',
    640.0,
    'flux',
    'examples/influx-query-profiling/simulated_profile.php'
);
for ($i = 0; $i < 110; $i++) {
    InfluxProfileCollector::record(
        'from(bucket: "prod_metrics") |> range(start: -15m) |> filter(fn: (r) => r.connector_id == "' . $i . '")',
        8.0,
        'flux',
        'examples/influx-query-profiling/simulated_profile.php'
    );
}
InfluxProfileCollector::record(
    'from(bucket: "prod_metrics") |> range(start: -30d) |> filter(fn: (r) => r.host =~ /api-.*/) |> sort(columns:["_time"], desc:true)',
    960.0,
    'flux',
    'examples/influx-query-profiling/simulated_profile.php'
);

InfluxProfileReporter::writeProcessShard(InfluxProfileCollector::snapshot());
$report = InfluxProfileReporter::writeLatestFromShards((string)getenv('TESTKIT_INFLUX_PROFILE_RUN_ID'));

echo 'Generated Influx profile: ' . ($report['summary']['total_queries'] ?? 0) . " queries, ";
echo ($report['summary']['unique_fingerprints'] ?? 0) . " fingerprints\n";
echo 'Report path: ' . ($report['config']['output']['report_path'] ?? 'testkit/_out/reports/influx_profile_latest.json') . "\n";
