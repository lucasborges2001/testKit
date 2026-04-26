<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/bootstrap.php';

use Testkit\Core\Common\Paths;
use Testkit\Core\DbProfiling\MysqlProfileConfig;

$config = MysqlProfileConfig::fromEnv();
$path = (string)($config['output']['report_path'] ?? (Paths::reportsRoot() . '/mysql_profile_latest.json'));

if (!is_file($path)) {
    echo "MySQL Query Profile\n\n";
    echo "No profile report found.\n";
    echo "Run:\n";
    echo "  TESTKIT_DB_PROFILE=1 php runTest.php back-php\n";
    echo "  php scripts/query_report.php\n";
    exit(0);
}

$report = json_decode((string)file_get_contents($path), true);
if (!is_array($report)) {
    fwrite(STDERR, "Invalid MySQL profile report: {$path}\n");
    exit(1);
}

$summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
$rankings = is_array($report['rankings'] ?? null) ? $report['rankings'] : [];
$recommendations = is_array($report['recommendations'] ?? null) ? $report['recommendations'] : [];

echo "MySQL Query Profile\n";
echo str_repeat('=', 72) . "\n\n";
echo 'Report: ' . Paths::relativeToRepo($path) . "\n";
echo 'Run: ' . (string)($report['run_id'] ?? '') . "\n";
echo 'Window: ' . (string)($report['started_at'] ?? '') . ' -> ' . (string)($report['finished_at'] ?? '') . "\n\n";

echo "Summary\n";
echo '- Total queries: ' . (int)($summary['total_queries'] ?? 0) . "\n";
echo '- Unique fingerprints: ' . (int)($summary['unique_fingerprints'] ?? 0) . "\n";
echo '- Total DB time: ' . fmt_ms((float)($summary['total_db_time_ms'] ?? 0.0)) . "\n";
echo '- Slow queries: ' . (int)($summary['slow_count'] ?? 0) . "\n";
echo '- Hotspots: ' . (int)($summary['hotspot_count'] ?? 0) . "\n";
echo '- N+1 candidates: ' . (int)($summary['n_plus_one_candidates'] ?? 0) . "\n\n";

render_ranking('Top by max latency', is_array($rankings['by_max_ms'] ?? null) ? $rankings['by_max_ms'] : []);
render_ranking('Top by total time', is_array($rankings['by_total_ms'] ?? null) ? $rankings['by_total_ms'] : []);
render_ranking('Top by calls', is_array($rankings['by_calls'] ?? null) ? $rankings['by_calls'] : []);

$nPlusOne = array_values(array_filter(
    is_array($report['queries'] ?? null) ? $report['queries'] : [],
    static fn(array $row): bool => ($row['classification'] ?? '') === 'n_plus_one_candidate'
));
render_ranking('N+1 candidates', $nPlusOne);

echo "Recommendations\n";
if ($recommendations === []) {
    echo "- No immediate recommendations. Use this run as baseline.\n";
} else {
    $i = 1;
    foreach (array_slice($recommendations, 0, 20) as $item) {
        if (!is_array($item)) {
            continue;
        }
        echo $i . '. [' . (string)($item['classification'] ?? 'watch') . '] ' . (string)($item['recommendation'] ?? '') . "\n";
        echo '   ' . shorten((string)($item['fingerprint'] ?? ''), 140) . "\n";
        $i++;
    }
}

echo "\n";

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
            shorten((string)($row['sample_sql'] ?? $row['fingerprint'] ?? ''), 140)
        );
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
