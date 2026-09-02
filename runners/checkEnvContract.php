<?php
declare(strict_types=1);

function testkit_env_contract_error(string $message, int $exit = 2): never
{
    fwrite(STDERR, "Env contract error: {$message}\n");
    exit($exit);
}

/** @param array<int,string> $argv @return array{config:string,result_json:?string} */
function testkit_env_contract_parse_args(array $argv): array
{
    $args = array_values(array_slice($argv, 1));
    $resultJson = null;
    $positional = [];

    for ($i = 0, $count = count($args); $i < $count; $i++) {
        $arg = $args[$i];
        if ($arg === '--help' || $arg === '-h' || $arg === 'help') {
            echo "Usage: testkit-env-contract <contract.php> [--result-json <path>]\n";
            exit(0);
        }
        if ($arg === '--result-json') {
            if ($resultJson !== null || !isset($args[$i + 1]) || trim((string)$args[$i + 1]) === '') {
                testkit_env_contract_error('--result-json requires one non-empty path.');
            }
            $resultJson = (string)$args[++$i];
            continue;
        }
        if (str_starts_with($arg, '--')) {
            testkit_env_contract_error("unsupported option {$arg}.");
        }
        $positional[] = $arg;
    }

    if (count($positional) !== 1) {
        testkit_env_contract_error('expected exactly one contract path.');
    }

    return ['config' => $positional[0], 'result_json' => $resultJson];
}

function testkit_env_contract_absolute(string $path, string $projectRoot): string
{
    if ($path !== '' && $path[0] === '/') {
        return $path;
    }
    return rtrim($projectRoot, '/') . '/' . $path;
}

function testkit_env_contract_under_root(string $path, string $projectRoot): bool
{
    $root = rtrim(str_replace('\\', '/', $projectRoot), '/');
    $normalized = str_replace('\\', '/', $path);
    return $normalized === $root || str_starts_with($normalized, $root . '/');
}

function testkit_env_contract_existing_under_root(string $path, string $projectRoot): ?string
{
    $resolved = realpath($path);
    if ($resolved === false || !testkit_env_contract_under_root($resolved, $projectRoot)) {
        return null;
    }
    return $resolved;
}

/** @return array<string,string> */
function testkit_env_contract_parse_file(string $path): array
{
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        testkit_env_contract_error('could not read env file.', 1);
    }

    $values = [];
    foreach ($lines as $index => $line) {
        if ($index === 0) {
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
        }
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        if (str_starts_with($trimmed, 'export ')) {
            $trimmed = ltrim(substr($trimmed, 7));
        }

        $equals = strpos($trimmed, '=');
        if ($equals === false) {
            $key = trim($trimmed);
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) !== 1) {
                testkit_env_contract_error('invalid env key at line ' . ($index + 1) . '.', 1);
            }
            $values[$key] = '';
            continue;
        }

        $key = trim(substr($trimmed, 0, $equals));
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) !== 1) {
            testkit_env_contract_error('invalid env key at line ' . ($index + 1) . '.', 1);
        }

        $raw = trim(substr($trimmed, $equals + 1));
        if (strlen($raw) >= 2 && (($raw[0] === '"' && $raw[strlen($raw) - 1] === '"')
            || ($raw[0] === "'" && $raw[strlen($raw) - 1] === "'"))) {
            $quote = $raw[0];
            $raw = substr($raw, 1, -1);
            if ($quote === '"') {
                $raw = strtr($raw, [
                    '\\n' => "\n",
                    '\\r' => "\r",
                    '\\t' => "\t",
                    '\\"' => '"',
                    '\\\\' => '\\',
                ]);
            }
        } else {
            $raw = preg_replace('/[[:space:]]+#.*$/', '', $raw) ?? $raw;
            $raw = rtrim($raw);
        }
        $values[$key] = $raw;
    }

    return $values;
}

/** @return array<string,string> */
function testkit_env_contract_process_env(): array
{
    $raw = getenv();
    $values = [];
    if (!is_array($raw)) {
        return $values;
    }
    foreach ($raw as $key => $value) {
        if (is_string($key) && is_string($value)) {
            $values[$key] = $value;
        }
    }
    return $values;
}

/** @param array<string,string> $values @param array<string,mixed> $check */
function testkit_env_contract_evaluate(array $values, array $check): bool
{
    $key = trim((string)($check['key'] ?? ''));
    $assertion = trim((string)($check['assert'] ?? ''));
    $exists = array_key_exists($key, $values);
    $actual = $exists ? $values[$key] : null;

    return match ($assertion) {
        'present' => $exists && trim((string)$actual) !== '',
        'absent' => !$exists,
        'equals' => $exists && is_string($check['value'] ?? null)
            && hash_equals((string)$check['value'], (string)$actual),
        'one_of' => $exists && is_array($check['values'] ?? null)
            && in_array((string)$actual, array_map('strval', $check['values']), true),
        default => false,
    };
}

/** @param array<string,mixed> $payload */
function testkit_env_contract_write_json(string $path, string $projectRoot, array $payload): void
{
    $resolved = testkit_env_contract_absolute($path, $projectRoot);
    $directory = dirname($resolved);
    $resolvedDirectory = realpath($directory);
    if ($resolvedDirectory === false || !is_dir($resolvedDirectory)) {
        testkit_env_contract_error("result directory does not exist: {$directory}.");
    }
    if (!testkit_env_contract_under_root($resolvedDirectory, $projectRoot)) {
        testkit_env_contract_error('--result-json must stay inside TESTKIT_PROJECT_ROOT.');
    }
    $resolved = rtrim($resolvedDirectory, '/') . '/' . basename($resolved);

    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        testkit_env_contract_error('could not encode result JSON.');
    }
    $temp = tempnam($resolvedDirectory, '.testkit-env-contract-');
    if ($temp === false) {
        testkit_env_contract_error('could not create result temp file.');
    }
    try {
        @chmod($temp, 0600);
        if (file_put_contents($temp, $encoded . PHP_EOL) === false || !@rename($temp, $resolved)) {
            testkit_env_contract_error('could not publish result JSON.');
        }
        @chmod($resolved, 0600);
    } finally {
        if (is_file($temp)) {
            @unlink($temp);
        }
    }
}

$parsed = testkit_env_contract_parse_args($argv);
$projectRoot = trim((string)getenv('TESTKIT_PROJECT_ROOT'));
if ($projectRoot === '') {
    $projectRoot = getcwd() ?: '.';
}
$projectRoot = realpath($projectRoot) ?: testkit_env_contract_error('TESTKIT_PROJECT_ROOT could not be resolved.');
$projectRoot = rtrim($projectRoot, '/');

$contractPath = testkit_env_contract_absolute($parsed['config'], $projectRoot);
$contractPath = testkit_env_contract_existing_under_root($contractPath, $projectRoot);
if ($contractPath === null || !is_file($contractPath)) {
    testkit_env_contract_error('contract must be a file inside TESTKIT_PROJECT_ROOT.');
}
$contract = require $contractPath;
if (!is_array($contract) || !is_array($contract['source'] ?? null) || !is_array($contract['checks'] ?? null)) {
    testkit_env_contract_error('contract must declare source and checks arrays.');
}

$source = $contract['source'];
$type = trim((string)($source['type'] ?? ''));
$sourceLabel = $type;
if ($type === 'file') {
    $sourcePath = trim((string)($source['path'] ?? ''));
    if ($sourcePath === '') {
        testkit_env_contract_error('file source requires a path.');
    }
    $resolvedSource = testkit_env_contract_absolute($sourcePath, $projectRoot);
    $resolvedSource = testkit_env_contract_existing_under_root($resolvedSource, $projectRoot);
    if ($resolvedSource === null || !is_file($resolvedSource)) {
        testkit_env_contract_error('env source must be a file inside TESTKIT_PROJECT_ROOT.', 1);
    }
    $values = testkit_env_contract_parse_file($resolvedSource);
    $sourceLabel = 'file:' . $sourcePath;
} elseif ($type === 'process') {
    $values = testkit_env_contract_process_env();
    $sourceLabel = 'process';
} else {
    testkit_env_contract_error('source.type must be file or process.');
}

$results = [];
$failed = 0;
foreach ($contract['checks'] as $index => $check) {
    if (!is_array($check)) {
        testkit_env_contract_error('every check must be an array.');
    }
    $key = trim((string)($check['key'] ?? ''));
    $assertion = trim((string)($check['assert'] ?? ''));
    $sensitive = (bool)($check['sensitive'] ?? false);
    if ($key === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) !== 1) {
        testkit_env_contract_error('check #' . ($index + 1) . ' has an invalid key.');
    }
    if (!in_array($assertion, ['present', 'absent', 'equals', 'one_of'], true)) {
        testkit_env_contract_error("check {$key} has unsupported assertion {$assertion}.");
    }
    if ($sensitive && !in_array($assertion, ['present', 'absent'], true)) {
        testkit_env_contract_error(
            "sensitive check {$key} may only assert present or absent; never store secret expected values."
        );
    }
    if ($assertion === 'equals' && !is_string($check['value'] ?? null)) {
        testkit_env_contract_error("check {$key} equals requires string value.");
    }
    if ($assertion === 'one_of') {
        $allowed = $check['values'] ?? null;
        if (!is_array($allowed) || $allowed === []) {
            testkit_env_contract_error("check {$key} one_of requires non-empty values.");
        }
        foreach ($allowed as $allowedValue) {
            if (!is_string($allowedValue)) {
                testkit_env_contract_error("check {$key} one_of values must be strings.");
            }
        }
    }

    $pass = testkit_env_contract_evaluate($values, $check);
    if (!$pass) {
        $failed++;
    }
    $results[] = [
        'key' => $key,
        'assert' => $assertion,
        'sensitive' => $sensitive,
        'status' => $pass ? 'PASS' : 'FAIL',
    ];
    echo ($pass ? 'PASS' : 'FAIL') . " {$key} {$assertion}\n";
}

$payload = [
    'schema' => 1,
    'runner' => 'envContract',
    'source' => $sourceLabel,
    'status' => $failed === 0 ? 'PASS' : 'FAIL',
    'exit_code' => $failed === 0 ? 0 : 1,
    'summary' => [
        'checks' => count($results),
        'passed' => count($results) - $failed,
        'failed' => $failed,
    ],
    'checks' => $results,
];

if ($parsed['result_json'] !== null) {
    testkit_env_contract_write_json($parsed['result_json'], $projectRoot, $payload);
}

exit($failed === 0 ? 0 : 1);
