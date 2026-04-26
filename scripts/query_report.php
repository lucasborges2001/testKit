<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/dbprofiling/bootstrap.php';

use Testkit\Core\Common\Paths;
use Testkit\Core\DbProfiling\MysqlProfileConfig;

$path = resolve_report_path($argv);

if (!is_file($path)) {
    echo "MySQL Query Profile\n\n";
    echo 'Report: ' . Paths::relativeToRepo($path) . "\n";
    echo "No profile report found.\n";
    echo "Run:\n";
    echo "  TESTKIT_DB_PROFILE=1 php runTest.php back-php\n";
    echo "  php scripts/query_report.php\n";
    exit(0);
}

$raw = file_get_contents($path);
$report = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($report)) {
    fwrite(STDERR, "Invalid MySQL profile report: {$path}\n");
    exit(1);
}

$summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
$rankings = is_array($report['rankings'] ?? null) ? $report['rankings'] : [];
$recommendations = is_array($report['recommendations'] ?? null) ? $report['recommendations'] : [];
$explain = is_array($report['explain'] ?? null) ? $report['explain'] : ['enabled' => false, 'attempted' => 0, 'analyzed' => 0, 'skipped' => 0, 'failed' => 0, 'findings' => []];

echo "MySQL Query Profile\n";
echo str_repeat('=', 72) . "\n\n";
echo 'Report: ' . Paths::relativeToRepo($path) . "\n";
echo 'Run: ' . (string)($report['run_id'] ?? '') . "\n";
echo 'Engine: ' . (string)($report['engine'] ?? 'mysql') . "\n";
echo 'Profile enabled: ' . (!empty($report['profile_enabled']) ? 'yes' : 'no') . "\n";
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

render_explain($explain);

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

/** @param array<int,string> $argv */
function resolve_report_path(array $argv): string
{
    $config = MysqlProfileConfig::fromEnv();
    $default = (string)($config['output']['report_path'] ?? (Paths::reportsRoot() . '/mysql_profile_latest.json'));
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
            shorten((string)($row['sample_sql'] ?? $row['fingerprint'] ?? ''), 140)
        );
        $i++;
    }
    echo "\n";
}

/** @param array<string,mixed> $explain */
function render_explain(array $explain): void
{
    echo "Explain analysis\n";
    if (empty($explain['enabled'])) {
        echo "- disabled. Enable with TESTKIT_DB_PROFILE_EXPLAIN=1.\n\n";
        return;
    }

    echo '- Attempted: ' . (int)($explain['attempted'] ?? 0) . "\n";
    echo '- Analyzed: ' . (int)($explain['analyzed'] ?? 0) . "\n";
    echo '- Skipped: ' . (int)($explain['skipped'] ?? 0) . "\n";
    echo '- Failed: ' . (int)($explain['failed'] ?? 0) . "\n";

    $findings = is_array($explain['findings'] ?? null) ? $explain['findings'] : [];
    if ($findings === []) {
        echo "- no findings\n\n";
        return;
    }

    $i = 1;
    foreach (array_slice($findings, 0, 20) as $finding) {
        if (!is_array($finding)) {
            continue;
        }
        $flags = implode(', ', array_values(array_filter((array)($finding['flags'] ?? []), 'is_string')));
        $status = (string)($finding['explain_status'] ?? 'skipped');
        $suffix = $status === 'skipped'
            ? ' skip=' . (string)($finding['skip_reason'] ?? '')
            : ($status === 'failed' ? ' error=' . shorten((string)($finding['error'] ?? ''), 80) : '');
        echo sprintf(
            "%d. %s | %s | %s%s\n",
            $i,
            (string)($finding['severity'] ?? 'info'),
            $status,
            $flags !== '' ? $flags : 'no flags',
            $suffix
        );
        echo '   ' . shorten((string)($finding['sample_sql'] ?? $finding['fingerprint'] ?? ''), 140) . "\n";
        $recommendation = trim((string)($finding['recommendation'] ?? ''));
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
