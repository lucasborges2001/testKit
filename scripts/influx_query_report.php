<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/influxprofiling/bootstrap.php';

use Testkit\Core\Common\Paths;
use Testkit\Core\InfluxProfiling\InfluxProfileConfig;

$path = resolve_report_path($argv);

if (!is_file($path)) {
    echo "Influx Query Profile\n\n";
    echo 'Report: ' . Paths::relativeToRepo($path) . "\n";
    echo "No profile report found.\n";
    echo "Run:\n";
    echo "  TESTKIT_INFLUX_PROFILE=1 php runTest.php back-php\n";
    echo "  php scripts/influx_query_report.php\n";
    exit(0);
}

$raw = file_get_contents($path);
$report = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($report)) {
    fwrite(STDERR, "Invalid Influx profile report: {$path}\n");
    exit(1);
}

$summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
$rankings = is_array($report['rankings'] ?? null) ? $report['rankings'] : [];
$recommendations = is_array($report['recommendations'] ?? null) ? $report['recommendations'] : [];

echo "Influx Query Profile\n";
echo str_repeat('=', 72) . "\n\n";
echo 'Report: ' . Paths::relativeToRepo($path) . "\n";
echo 'Run: ' . (string)($report['run_id'] ?? '') . "\n";
echo 'Engine: ' . (string)($report['engine'] ?? 'influx') . "\n";
echo 'Profile enabled: ' . (!empty($report['profile_enabled']) ? 'yes' : 'no') . "\n";
echo 'Window: ' . (string)($report['started_at'] ?? '') . ' -> ' . (string)($report['finished_at'] ?? '') . "\n\n";

echo "Summary\n";
echo '- Total queries: ' . (int)($summary['total_queries'] ?? 0) . "\n";
echo '- Unique fingerprints: ' . (int)($summary['unique_fingerprints'] ?? 0) . "\n";
echo '- Total query time: ' . fmt_ms((float)($summary['total_query_time_ms'] ?? 0.0)) . "\n";
echo '- Slow queries: ' . (int)($summary['slow_count'] ?? 0) . "\n";
echo '- Hotspots: ' . (int)($summary['hotspot_count'] ?? 0) . "\n";
echo '- N+1 candidates: ' . (int)($summary['n_plus_one_candidates'] ?? 0) . "\n";
echo '- Risky queries: ' . (int)($summary['risky_count'] ?? 0) . "\n\n";

render_ranking('Top by max latency', is_array($rankings['by_max_ms'] ?? null) ? $rankings['by_max_ms'] : []);
render_ranking('Top by total time', is_array($rankings['by_total_ms'] ?? null) ? $rankings['by_total_ms'] : []);
render_ranking('Top by calls', is_array($rankings['by_calls'] ?? null) ? $rankings['by_calls'] : []);
render_risky(is_array($rankings['by_risk'] ?? null) ? $rankings['by_risk'] : []);

echo "Recommendations\n";
if ($recommendations === []) {
    echo "- No immediate recommendations. Use this run as baseline.\n";
} else {
    $i = 1;
    foreach (array_slice($recommendations, 0, 20) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $flags = implode(', ', array_values(array_filter((array)($item['risk_flags'] ?? []), 'is_string')));
        echo $i . '. [' . (string)($item['classification'] ?? 'watch') . '] ' . (string)($item['recommendation'] ?? '') . "\n";
        if ($flags !== '') {
            echo '   flags: ' . $flags . "\n";
        }
        echo '   ' . shorten((string)($item['fingerprint'] ?? ''), 140) . "\n";
        $i++;
    }
}

echo "\n";

/** @param array<int,string> $argv */
function resolve_report_path(array $argv): string
{
    $config = InfluxProfileConfig::fromEnv();
    $default = (string)($config['output']['report_path'] ?? (Paths::reportsRoot() . '/influx_profile_latest.json'));
    foreach (array_slice($argv, 1) as $idx => $arg) {
        if ($arg === '--path' && isset($argv[$idx + 2])) {
            return Paths::normalize((string)$argv[$idx + 2]);
        }
        if (str_starts_with($arg, '--path=')) {
            return Paths::normalize(substr($arg, 7));
        }
    }
    return $default;
}

/** @param array<int,array<string,mixed>> $rows */
function render_ranking(string $title, array $rows): void
{
    echo $title . "\n";
    if ($rows === []) {
        echo "- none\n\n";
        return;
    }

    $i = 1;
    foreach (array_slice($rows, 0, 20) as $row) {
        echo sprintf(
            "%d. %s total | %s max | %s avg | %d calls | %s | %s\n",
            $i,
            fmt_ms((float)($row['total_ms'] ?? 0.0)),
            fmt_ms((float)($row['max_ms'] ?? 0.0)),
            fmt_ms((float)($row['avg_ms'] ?? 0.0)),
            (int)($row['calls'] ?? 0),
            (string)($row['classification'] ?? 'ok'),
            shorten((string)($row['sample_query'] ?? $row['fingerprint'] ?? ''), 140)
        );
        $flags = implode(', ', array_values(array_filter((array)($row['risk_flags'] ?? []), 'is_string')));
        if ($flags !== '') {
            echo '   risk: ' . (string)($row['risk_severity'] ?? 'info') . ' | ' . $flags . "\n";
        }
        $i++;
    }
    echo "\n";
}

/** @param array<int,array<string,mixed>> $rows */
function render_risky(array $rows): void
{
    echo "Risky queries\n";
    if ($rows === []) {
        echo "- none\n\n";
        return;
    }

    $i = 1;
    foreach (array_slice($rows, 0, 20) as $row) {
        $flags = implode(', ', array_values(array_filter((array)($row['risk_flags'] ?? []), 'is_string')));
        echo sprintf(
            "%d. %s | %s | %s\n",
            $i,
            (string)($row['risk_severity'] ?? 'info'),
            $flags !== '' ? $flags : 'no flags',
            shorten((string)($row['sample_query'] ?? $row['fingerprint'] ?? ''), 140)
        );
        $recommendation = trim((string)($row['recommendation'] ?? ''));
        if ($recommendation !== '') {
            echo '   ' . shorten($recommendation, 180) . "\n";
        }
        $i++;
    }
    echo "\n";
}

function fmt_ms(float $value): string
{
    return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') . ' ms';
}

function shorten(string $value, int $max): string
{
    $value = preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
    return strlen($value) > $max ? substr($value, 0, $max - 3) . '...' : $value;
}
