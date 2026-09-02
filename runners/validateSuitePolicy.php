<?php
declare(strict_types=1);

function testkit_policy_fail(string $message): never
{
    fwrite(STDERR, "Suite policy error: {$message}\n");
    exit(2);
}

/** @param array<int,string> $argv @return array{config:string,suite:string,list:bool,allow_persistent:bool} */
function testkit_policy_parse_args(array $argv): array
{
    $args = array_values(array_slice($argv, 1));
    $list = false;
    $allowPersistent = false;
    $positional = [];

    for ($i = 0, $count = count($args); $i < $count; $i++) {
        $arg = $args[$i];
        if ($arg === '--allow-persistent') {
            $allowPersistent = true;
            continue;
        }
        if ($arg === '--list') {
            $list = true;
            continue;
        }
        if ($arg === '--result-json') {
            if (!isset($args[$i + 1]) || trim((string)$args[$i + 1]) === '') {
                testkit_policy_fail('--result-json requires a non-empty path.');
            }
            $i++;
            continue;
        }
        if ($arg === '--help' || $arg === '-h' || $arg === 'help') {
            exit(0);
        }
        if (str_starts_with($arg, '--')) {
            continue;
        }
        $positional[] = $arg;
    }

    if (count($positional) < 1 || count($positional) > 2) {
        testkit_policy_fail('expected <config.php> [suite].');
    }

    return [
        'config' => $positional[0],
        'suite' => $positional[1] ?? 'all',
        'list' => $list,
        'allow_persistent' => $allowPersistent,
    ];
}

function testkit_policy_absolute_path(string $path, string $projectRoot): string
{
    if ($path !== '' && $path[0] === '/') {
        return $path;
    }
    return rtrim($projectRoot, '/') . '/' . $path;
}

/** @return array<string,mixed> */
function testkit_policy_load_config(string $path, string $projectRoot): array
{
    $resolved = testkit_policy_absolute_path($path, $projectRoot);
    if (!is_file($resolved)) {
        exit(0);
    }

    $config = require $resolved;
    if (!is_array($config) || !is_array($config['suites'] ?? null)) {
        exit(0);
    }
    return $config;
}

/** @param array<string,mixed> $config @return array<string,array<string,mixed>> */
function testkit_policy_index(array $config): array
{
    $indexed = [];
    foreach ($config['suites'] as $suite) {
        if (!is_array($suite)) {
            continue;
        }
        $key = trim((string)($suite['key'] ?? ''));
        if ($key !== '') {
            $indexed[$key] = $suite;
        }
    }
    return $indexed;
}

/** @param array<string,mixed> $suite */
function testkit_policy_validate_suite(string $key, array $suite): void
{
    $allowedRisk = ['safe', 'disposable', 'persistent', 'hardware'];
    $risk = trim((string)($suite['risk'] ?? ''));
    if (!in_array($risk, $allowedRisk, true)) {
        testkit_policy_fail("suite {$key} must declare risk=safe|disposable|persistent|hardware.");
    }

    if (isset($suite['requires'])) {
        if (!is_array($suite['requires'])) {
            testkit_policy_fail("suite {$key} requires must be an array.");
        }
        foreach ($suite['requires'] as $requirement) {
            if (!is_string($requirement) || trim($requirement) === '') {
                testkit_policy_fail("suite {$key} has an invalid requires entry.");
            }
        }
    }

    if (isset($suite['exclusive']) && !is_bool($suite['exclusive'])) {
        testkit_policy_fail("suite {$key} exclusive must be boolean.");
    }

    if ($risk !== 'persistent' || !array_key_exists('commands', $suite)) {
        return;
    }

    $cleanup = $suite['cleanup'] ?? null;
    if (!is_array($cleanup)) {
        testkit_policy_fail("persistent suite {$key} must declare cleanup metadata.");
    }
    if (($cleanup['strategy'] ?? null) !== 'self') {
        testkit_policy_fail("persistent suite {$key} cleanup.strategy must be self in policy v1.");
    }
    if (($cleanup['guaranteed'] ?? null) !== true) {
        testkit_policy_fail("persistent suite {$key} cleanup.guaranteed must be true.");
    }
    if (trim((string)($cleanup['description'] ?? '')) === '') {
        testkit_policy_fail("persistent suite {$key} cleanup.description must be non-empty.");
    }
}

/**
 * @param array<string,array<string,mixed>> $suites
 * @param array<int,string> $stack
 * @param array<string,bool> $seen
 * @return array<int,string>
 */
function testkit_policy_selected_persistent(string $key, array $suites, array $stack = [], array &$seen = []): array
{
    if (!isset($suites[$key]) || isset($seen[$key]) || in_array($key, $stack, true)) {
        return [];
    }

    $seen[$key] = true;
    $suite = $suites[$key];
    $persistent = [];
    if ((string)($suite['risk'] ?? '') === 'persistent') {
        $persistent[] = $key;
    }

    if (!is_array($suite['suites'] ?? null)) {
        return $persistent;
    }

    $stack[] = $key;
    foreach ($suite['suites'] as $child) {
        if (!is_string($child) || $child === '') {
            continue;
        }
        foreach (testkit_policy_selected_persistent($child, $suites, $stack, $seen) as $found) {
            if (!in_array($found, $persistent, true)) {
                $persistent[] = $found;
            }
        }
    }
    return $persistent;
}

$parsed = testkit_policy_parse_args($argv);
$projectRoot = trim((string)getenv('TESTKIT_PROJECT_ROOT'));
if ($projectRoot === '') {
    $projectRoot = getcwd() ?: '.';
}
$projectRoot = rtrim($projectRoot, '/');

$config = testkit_policy_load_config($parsed['config'], $projectRoot);
$version = (int)($config['suite_policy_version'] ?? 0);
if ($version === 0) {
    exit(0);
}
if ($version !== 1) {
    testkit_policy_fail("unsupported suite_policy_version={$version}.");
}

$suites = testkit_policy_index($config);
foreach ($suites as $key => $suite) {
    testkit_policy_validate_suite($key, $suite);
}

if ($parsed['list']) {
    exit(0);
}

if (!isset($suites[$parsed['suite']])) {
    exit(0);
}

$seen = [];
$persistent = testkit_policy_selected_persistent($parsed['suite'], $suites, [], $seen);
if ($persistent !== [] && !$parsed['allow_persistent']) {
    testkit_policy_fail(
        'persistent execution requires explicit --allow-persistent; selected: ' . implode(', ', $persistent)
    );
}

exit(0);
