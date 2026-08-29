<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/php/common/Env.php';
require_once $root . '/core/php/common/AgentMode.php';
require_once $root . '/core/php/reporting/UI.php';
require_once $root . '/core/php/sqlstatic/SqlStaticConsoleReporter.php';

use Testkit\Core\SqlStatic\SqlStaticConsoleReporter;

$report = [
    'scanned_files' => 2,
    'sql_candidates' => 3,
    'extracted_queries' => 2,
    'unresolved_candidates' => 1,
    'coverage_status' => 'partial',
    'summary' => ['findings' => 2, 'warn' => 1, 'watch' => 1],
    'findings' => [
        ['severity' => 'warn', 'confidence' => 'high', 'rule_id' => 'select_star', 'path' => 'src/a.php', 'line' => 2],
        ['severity' => 'watch', 'confidence' => 'medium', 'rule_id' => 'offset_pagination', 'path' => 'src/a.php', 'line' => 3],
    ],
    'coverage_findings' => [
        ['rule_id' => 'dynamic_sql_unresolved', 'reason' => 'parameter_passthrough', 'path' => 'src/a.php', 'line' => 4],
    ],
    'delta' => ['status' => 'not_compared', 'gate_enabled' => false],
];

$errors = [];
$assert = static function (bool $ok, string $message) use (&$errors): void {
    if (!$ok) $errors[] = $message;
};

putenv('NO_COLOR');
putenv('TESTKIT_MODE');
$normal = SqlStaticConsoleReporter::compact($report);
$assert(str_contains($normal, "\033["), 'normal compact output must use UI ANSI');
$assert(str_contains($normal, 'OK') && str_contains($normal, 'sql-static-audit'), 'compact output semantic label');
$assert(!str_contains($normal, 'PASS=') && !str_contains($normal, 'FAIL='), 'queries/findings are not tests');

putenv('NO_COLOR=1');
$plain = SqlStaticConsoleReporter::human($report);
$assert(!str_contains($plain, "\033["), 'NO_COLOR human output must be plain');
$assert(str_contains($plain, 'WARN/HIGH select_star src/a.php:2'), 'human finding line');
$assert(str_contains($plain, 'COVERAGE parameter_passthrough src/a.php:4'), 'human coverage line');

putenv('NO_COLOR');
putenv('TESTKIT_MODE=agent');
$agent = SqlStaticConsoleReporter::compact($report);
$assert(!str_contains($agent, "\033["), 'agent output must be plain');

$json = json_encode($report, JSON_THROW_ON_ERROR);
$assert(!str_contains($json, "\033["), 'JSON must never contain ANSI');
putenv('TESTKIT_MODE');

if ($errors !== []) {
    fwrite(STDERR, "SQL static console tests failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}
echo "SQL static console PASS\n";
