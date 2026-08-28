<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/php/sqlstatic/bootstrap.php';

use Testkit\Core\SqlStatic\SqlStaticAuditor;

$errors = [];
$tmp = sys_get_temp_dir() . '/testkit_sql_static_' . getmypid() . '_' . substr(sha1(uniqid('', true)), 0, 8);
@mkdir($tmp . '/src', 0775, true);
@mkdir($tmp . '/testkit', 0775, true);

function sql_static_assert(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

file_put_contents($tmp . '/src/repository.php', <<<'PHP_SOURCE'
<?php
const USER_FIELDS = 'id, email';
$a = "SELECT * FROM users WHERE YEAR(created_at) = 2026";
$b = 'SELECT id FROM products';
$c = "SELECT id FROM users WHERE email LIKE '%@example.com'";
$d = 'SELECT id, name FROM users WHERE created_at >= ? AND created_at < ?';
$e = "SELECT " . USER_FIELDS . " FROM users WHERE id = ?";
PHP_SOURCE
);
file_put_contents($tmp . '/src/report.sql', "SELECT id FROM audit_log;\nSELECT COUNT(*) FROM audit_log;\n");
file_put_contents($tmp . '/testkit/ignored.php', "<?php \$sql = 'SELECT * FROM ignored';\n");

$projectionOnly = Testkit\Core\SqlStatic\SqlRuleSet::analyze('SELECT YEAR(created_at) AS year_value FROM users WHERE id = 1');
$projectionRules = array_column($projectionOnly, 'ruleId');
sql_static_assert(!in_array('non_sargable_predicate', $projectionRules, true), 'projection function must not be treated as predicate', $errors);

$report = SqlStaticAuditor::audit($tmp, ['src']);
sql_static_assert(($report['schema_version'] ?? '') === SqlStaticAuditor::SCHEMA, 'schema version', $errors);
sql_static_assert((int)($report['scanned_files'] ?? 0) === 2, 'two source files scanned', $errors);
sql_static_assert((int)($report['extracted_queries'] ?? 0) === 7, 'seven SELECT queries extracted', $errors);

$rules = [];
$encoded = json_encode($report, JSON_UNESCAPED_SLASHES);
foreach ((array)($report['findings'] ?? []) as $finding) {
    if (is_array($finding)) {
        $rules[] = (string)($finding['rule_id'] ?? '');
    }
}
sql_static_assert(in_array('select_star', $rules, true), 'SELECT * detected', $errors);
sql_static_assert(in_array('unbounded_select', $rules, true), 'unbounded SELECT detected', $errors);
sql_static_assert(in_array('non_sargable_predicate', $rules, true), 'non-sargable predicate detected', $errors);
sql_static_assert(in_array('leading_wildcard_like', $rules, true), 'leading wildcard LIKE detected', $errors);
sql_static_assert(!str_contains((string)$encoded, '@example.com'), 'report redacts SQL literals', $errors);
sql_static_assert(($report['summary']['gate_enabled'] ?? true) === false, 'report-only contract', $errors);

$script = $root . '/scripts/sql_static_audit.php';
$jsonPath = $tmp . '/out/report.json';
$command = escapeshellarg(PHP_BINARY)
    . ' ' . escapeshellarg($script)
    . ' --root=' . escapeshellarg($tmp)
    . ' --path=src --format=json --json=' . escapeshellarg($jsonPath);
$output = [];
$exitCode = 0;
exec($command . ' 2>&1', $output, $exitCode);
$cliReport = json_decode(implode("\n", $output), true);
sql_static_assert($exitCode === 0, 'CLI findings do not fail audit', $errors);
sql_static_assert(is_array($cliReport), 'CLI JSON decodes', $errors);
sql_static_assert(is_file($jsonPath), 'CLI writes JSON artifact', $errors);

$invalidOutput = [];
$invalidExit = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --root=' . escapeshellarg($tmp) . ' --path=missing 2>&1',
    $invalidOutput,
    $invalidExit
);
sql_static_assert($invalidExit === 2, 'missing path uses operational exit 2', $errors);

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iterator as $fileInfo) {
    $fileInfo->isDir() ? @rmdir($fileInfo->getPathname()) : @unlink($fileInfo->getPathname());
}
@rmdir($tmp);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . PHP_EOL);
    }
    exit(1);
}

echo "SQL static audit PASS\n";
