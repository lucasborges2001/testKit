<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/dbprofiling/bootstrap.php';

use Testkit\Core\Common\Paths;
use Testkit\Core\DbProfiling\MysqlInstrumentationAudit;
use Testkit\Core\DbProfiling\MysqlProfileConfig;

$path = tk_mysql_audit_resolve_path($argv);
if (!is_file($path)) {
    fwrite(STDERR, "Instrumentation report not found: {$path}\n");
    exit(2);
}

$raw = file_get_contents($path);
$report = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($report)) {
    fwrite(STDERR, "Invalid JSON report: {$path}\n");
    exit(2);
}

$contractError = MysqlInstrumentationAudit::contractError($report);
if ($contractError !== null) {
    fwrite(STDERR, "Corrupt instrumentation contract: {$contractError}\n");
    exit(3);
}

$audit = MysqlInstrumentationAudit::analyze($report);
$coverage = is_array($audit['coverage'] ?? null) ? $audit['coverage'] : [];
$facts = is_array($coverage['facts'] ?? null) ? $coverage['facts'] : [];
$calc = is_array($coverage['calculable'] ?? null) ? $coverage['calculable'] : [];
$unknown = is_array($coverage['unknown'] ?? null) ? $coverage['unknown'] : [];

echo "MySQL Instrumentation Audit\n";
echo str_repeat('=', 78) . "\n\n";
echo 'Report: ' . Paths::relativeToRepo($path) . "\n";
echo 'Run: ' . (string)($audit['run_id'] ?? '') . "\n";
echo 'Contract: ' . (string)($audit['schema_version'] ?? '') . "\n";
echo 'Diagnostic status: ' . (string)($audit['status'] ?? 'unknown') . "\n\n";

echo "Observable coverage\n";
echo '- Captured queries: ' . (int)($facts['captured_queries'] ?? 0) . "\n";
echo '- Unique fingerprints: ' . (int)($facts['captured_unique_fingerprints'] ?? 0) . "\n";
echo '- Instrumented connections: ' . (int)($facts['instrumented_connections'] ?? 0) . "\n";
echo '- Overall capture coverage: ' . (string)($unknown['overall_capture_coverage_status'] ?? 'unknown') . "\n";
echo '- Reason: ' . (string)($unknown['reason'] ?? '') . "\n\n";

echo "Context completeness\n";
foreach ($calc as $key => $value) {
    echo '- ' . $key . ': ' . ($value === null ? 'n/a' : number_format((float)$value, 2) . '%') . "\n";
}
echo "\n";

echo "Capture methods\n";
$methods = is_array($audit['capture_methods'] ?? null) ? $audit['capture_methods'] : [];
if ($methods === []) {
    echo "- none\n";
} else {
    foreach ($methods as $method => $count) {
        echo '- ' . $method . ': ' . (int)$count . "\n";
    }
}
echo "\n";

echo "Partial connections\n";
$partials = is_array($audit['partial_connections'] ?? null) ? $audit['partial_connections'] : [];
if ($partials === []) {
    echo "- none\n";
} else {
    foreach ($partials as $connection) {
        echo '- ' . (string)($connection['connection_id'] ?? '')
            . ' adapter=' . (string)($connection['adapter'] ?? 'unknown')
            . ' capabilities=' . json_encode($connection['capture_capabilities'] ?? [], JSON_UNESCAPED_SLASHES)
            . "\n";
    }
}
echo "\n";

echo "Signals and bypass findings\n";
$findings = is_array($audit['findings'] ?? null) ? $audit['findings'] : [];
if ($findings === []) {
    echo "- none\n";
} else {
    foreach ($findings as $finding) {
        if (!is_array($finding)) {
            continue;
        }
        echo '- [' . (string)($finding['severity'] ?? 'watch') . '] '
            . (string)($finding['code'] ?? 'unknown') . ': '
            . (string)($finding['message'] ?? '') . "\n";
    }
}
echo "\n";

echo "Actionable recommendations\n";
$recommendations = is_array($audit['recommendations'] ?? null) ? $audit['recommendations'] : [];
if ($recommendations === []) {
    echo "- none\n";
} else {
    foreach ($recommendations as $recommendation) {
        echo '- ' . (string)($recommendation['code'] ?? 'general') . ': '
            . (string)($recommendation['recommendation'] ?? '') . "\n";
    }
}
echo "\n";

// Diagnostic warnings intentionally do not fail the command.
exit(0);

/** @param array<int,string> $argv */
function tk_mysql_audit_resolve_path(array $argv): string
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
