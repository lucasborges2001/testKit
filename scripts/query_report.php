<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/dbprofiling/bootstrap.php';

use Testkit\Core\Common\Paths;
use Testkit\Core\DbProfiling\MysqlInstrumentationAudit;
use Testkit\Core\DbProfiling\MysqlProfileConfig;

$path = tk_query_report_resolve_path($argv);
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
$contractError = MysqlInstrumentationAudit::contractError($report);
if ($contractError !== null) {
    fwrite(STDERR, "Invalid MySQL profile contract: {$contractError}\n");
    exit(1);
}

$audit = MysqlInstrumentationAudit::analyze($report);
$summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
$rankings = is_array($report['rankings'] ?? null) ? $report['rankings'] : [];
$coverage = is_array($audit['coverage'] ?? null) ? $audit['coverage'] : [];
$facts = is_array($coverage['facts'] ?? null) ? $coverage['facts'] : [];
$calculable = is_array($coverage['calculable'] ?? null) ? $coverage['calculable'] : [];
$unknown = is_array($coverage['unknown'] ?? null) ? $coverage['unknown'] : [];
$connections = is_array($report['connections'] ?? null) ? $report['connections'] : [];
$findings = is_array($audit['findings'] ?? null) ? $audit['findings'] : [];
$explain = is_array($report['explain'] ?? null)
    ? $report['explain']
    : ['enabled' => false, 'attempted' => 0, 'analyzed' => 0, 'skipped' => 0, 'failed' => 0, 'findings' => []];

echo "MySQL Query Profile\n";
echo str_repeat('=', 78) . "\n\n";

echo "Run metadata\n";
echo '- Report: ' . Paths::relativeToRepo($path) . "\n";
echo '- Contract: v' . (int)($report['report_version'] ?? 1)
    . ' / ' . (string)($report['schema_version'] ?? 'legacy-v1')
    . ' / instrumentation ' . (string)($report['instrumentation_version'] ?? 'legacy') . "\n";
echo '- Run: ' . (string)($report['run_id'] ?? '') . "\n";
echo '- Meta run: ' . (string)($report['meta_run_id'] ?? '') . "\n";
echo '- Suite: ' . (string)($report['suite_id'] ?? '') . "\n";
echo '- Profile enabled: ' . (!empty($report['profile_enabled']) ? 'yes' : 'no') . "\n";
echo '- Window: ' . (string)($report['started_at'] ?? '') . ' -> ' . (string)($report['finished_at'] ?? '') . "\n\n";

echo "Instrumentation summary\n";
echo '- Status: ' . (string)($audit['status'] ?? 'unknown') . "\n";
echo '- Captured queries: ' . (int)($facts['captured_queries'] ?? $summary['total_queries'] ?? 0) . "\n";
echo '- Unique fingerprints: ' . (int)($facts['captured_unique_fingerprints'] ?? $summary['unique_fingerprints'] ?? 0) . "\n";
echo '- Instrumented connections: ' . (int)($facts['instrumented_connections'] ?? 0) . "\n";
echo '- Connections with queries: ' . (int)($facts['connections_with_queries'] ?? 0) . "\n";
echo '- Total DB time: ' . tk_query_report_fmt_ms((float)($summary['total_db_time_ms'] ?? 0.0)) . "\n";
echo '- Slow: ' . (int)($summary['slow_count'] ?? 0)
    . ' | Hotspots: ' . (int)($summary['hotspot_count'] ?? 0)
    . ' | N+1: ' . (int)($summary['n_plus_one_candidates'] ?? 0) . "\n\n";

echo "Observable coverage\n";
echo '- Overall capture coverage: ' . (string)($unknown['overall_capture_coverage_status'] ?? 'unknown') . "\n";
echo '- Reason: ' . (string)($unknown['reason'] ?? 'No independent denominator available.') . "\n\n";

echo "Connections observed\n";
if ($connections === []) {
    echo "- none\n\n";
} else {
    foreach ($connections as $connection) {
        if (!is_array($connection)) {
            continue;
        }
        $caps = is_array($connection['capture_capabilities'] ?? null) ? $connection['capture_capabilities'] : [];
        echo sprintf(
            "- %s | %s | queries=%d prepared=%d tx=%d | query=%s exec=%s prepare_execute=%s transactions=%s\n",
            (string)($connection['connection_id'] ?? ''),
            (string)($connection['adapter'] ?? 'unknown'),
            (int)($connection['query_count'] ?? 0),
            (int)($connection['prepared_statement_count'] ?? 0),
            (int)($connection['transaction_count'] ?? 0),
            !empty($caps['query']) ? 'yes' : 'no',
            !empty($caps['exec']) ? 'yes' : 'no',
            !empty($caps['prepare_execute']) ? 'yes' : 'no',
            !empty($caps['transactions']) ? 'yes' : 'no'
        );
    }
    echo "\n";
}

echo "Capture methods\n";
$methods = is_array($audit['capture_methods'] ?? null) ? $audit['capture_methods'] : [];
if ($methods === []) {
    echo "- none\n\n";
} else {
    foreach ($methods as $method => $count) {
        echo '- ' . $method . ': ' . (int)$count . "\n";
    }
    echo "\n";
}

echo "Context completeness\n";
foreach ([
    'source_context_coverage_pct' => 'source',
    'caller_context_coverage_pct' => 'caller',
    'test_context_coverage_pct' => 'test',
    'connection_context_coverage_pct' => 'connection',
    'module_context_coverage_pct' => 'module',
    'scenario_context_coverage_pct' => 'scenario',
] as $key => $label) {
    $value = $calculable[$key] ?? null;
    echo '- ' . $label . ': ' . ($value === null ? 'n/a' : number_format((float)$value, 2) . '%') . "\n";
}
echo "\n";

echo "Instrumentation warnings\n";
if ($findings === []) {
    echo "- none\n\n";
} else {
    foreach ($findings as $finding) {
        if (!is_array($finding)) {
            continue;
        }
        echo sprintf(
            "- [%s] %s: %s\n",
            (string)($finding['severity'] ?? 'watch'),
            (string)($finding['code'] ?? 'unknown'),
            (string)($finding['message'] ?? '')
        );
        $recommendation = trim((string)($finding['recommendation'] ?? ''));
        if ($recommendation !== '') {
            echo '  Recommendation: ' . $recommendation . "\n";
        }
    }
    echo "\n";
}

tk_query_report_render_ranking('Top latency', (array)($rankings['by_max_ms'] ?? []));
tk_query_report_render_ranking('Top cumulative cost', (array)($rankings['by_total_ms'] ?? []));
tk_query_report_render_ranking('Top calls', (array)($rankings['by_calls'] ?? []));

$nPlusOne = array_values(array_filter(
    is_array($report['queries'] ?? null) ? $report['queries'] : [],
    static fn(mixed $row): bool => is_array($row) && ($row['classification'] ?? '') === 'n_plus_one_candidate'
));
tk_query_report_render_ranking('N+1 candidates', $nPlusOne);
tk_query_report_render_explain($explain);

echo "Limitations\n";
$limitations = array_values(array_filter((array)($report['limitations'] ?? []), 'is_string'));
if ($limitations === []) {
    echo "- none declared\n";
} else {
    foreach ($limitations as $limitation) {
        echo '- ' . $limitation . "\n";
    }
}
echo "\n";

/** @param array<int,string> $argv */
function tk_query_report_resolve_path(array $argv): string
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
function tk_query_report_render_ranking(string $title, array $rows): void
{
    echo $title . "\n";
    if ($rows === []) {
        echo "- none\n\n";
        return;
    }
    $i = 1;
    foreach (array_slice($rows, 0, 20) as $row) {
        if (!is_array($row)) {
            continue;
        }
        echo sprintf(
            "%d. %s total | %s max | %s p95 | %d calls | %s | %s\n",
            $i,
            tk_query_report_fmt_ms((float)($row['total_ms'] ?? 0.0)),
            tk_query_report_fmt_ms((float)($row['max_ms'] ?? 0.0)),
            tk_query_report_fmt_ms((float)($row['p95_ms'] ?? 0.0)),
            (int)($row['calls'] ?? 0),
            (string)($row['classification'] ?? 'ok'),
            tk_query_report_shorten((string)($row['sample_sql'] ?? $row['fingerprint'] ?? ''), 140)
        );
        $i++;
    }
    echo "\n";
}

/** @param array<string,mixed> $explain */
function tk_query_report_render_explain(array $explain): void
{
    echo "EXPLAIN summary\n";
    if (empty($explain['enabled'])) {
        echo "- disabled. Enable with TESTKIT_DB_PROFILE_EXPLAIN=1.\n\n";
        return;
    }
    echo '- Attempted: ' . (int)($explain['attempted'] ?? 0) . "\n";
    echo '- Analyzed: ' . (int)($explain['analyzed'] ?? 0) . "\n";
    echo '- Skipped: ' . (int)($explain['skipped'] ?? 0) . "\n";
    echo '- Failed: ' . (int)($explain['failed'] ?? 0) . "\n\n";
}

function tk_query_report_fmt_ms(float $value): string
{
    return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') . ' ms';
}

function tk_query_report_shorten(string $value, int $max): string
{
    $value = preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
    return strlen($value) > $max ? substr($value, 0, $max - 3) . '...' : $value;
}
