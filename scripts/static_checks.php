#!/usr/bin/env php
<?php
declare(strict_types=1);

$testkitRoot = dirname(__DIR__);
$targetRoot = getenv('TESTKIT_STATIC_ROOT');
$targetRoot = is_string($targetRoot) && $targetRoot !== '' ? rtrim($targetRoot, '/\\') : $testkitRoot;

require_once $testkitRoot . '/core/php/common/Env.php';
require_once $testkitRoot . '/core/php/common/AgentMode.php';
require_once $testkitRoot . '/core/php/reporting/UI.php';
require_once $testkitRoot . '/core/php/reporting/CompactBatchReporter.php';

use Testkit\Core\Reporting\CompactBatchReporter;

$selector = strtolower(trim((string)($argv[1] ?? 'all')));
if (!in_array($selector, ['all', 'php', 'bash', 'node'], true)) {
    fwrite(STDERR, "Uso: php scripts/static_checks.php [all|php|bash|node]\n");
    exit(2);
}

$definitions = [
    'php' => [
        'label' => 'PHP lint',
        'files' => static_php_files($targetRoot),
        'command' => static fn(string $file): string => escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file),
        'rerun' => static fn(string $file): string => 'php -l ' . relative_path($targetRoot, $file),
    ],
    'bash' => [
        'label' => 'Bash syntax',
        'files' => static_bash_files($targetRoot),
        'command' => static fn(string $file): string => 'bash -n ' . escapeshellarg($file),
        'rerun' => static fn(string $file): string => 'bash -n ' . relative_path($targetRoot, $file),
    ],
    'node' => [
        'label' => 'Node syntax',
        'files' => static_node_files($targetRoot),
        'command' => static fn(string $file): string => 'node --check ' . escapeshellarg($file),
        'rerun' => static fn(string $file): string => 'node --check ' . relative_path($targetRoot, $file),
    ],
];

$selected = $selector === 'all' ? ['php', 'bash', 'node'] : [$selector];
$checks = [];
$failed = false;
foreach ($selected as $key) {
    $definition = $definitions[$key];
    $check = run_static_check(
        (string)$definition['label'],
        $definition['files'],
        $definition['command'],
        $definition['rerun'],
        $targetRoot
    );
    $checks[] = $check;
    CompactBatchReporter::printCheck($check);
    $failed = $failed || (int)$check['failed'] > 0;
}
CompactBatchReporter::printSummary($checks);
exit($failed ? 1 : 0);

/** @return array<int,string> */
function static_php_files(string $root): array
{
    $files = [];
    if (!is_dir($root)) {
        return $files;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $path = str_replace('\\', '/', $file->getPathname());
        if (str_contains('/' . ltrim($path, '/'), '/vendor/')) {
            continue;
        }
        $files[] = $file->getPathname();
    }
    sort($files, SORT_STRING);
    return $files;
}

/** @return array<int,string> */
function static_bash_files(string $root): array
{
    $files = [];
    foreach (['bin', 'scripts', 'lib'] as $dir) {
        $base = $root . DIRECTORY_SEPARATOR . $dir;
        if (!is_dir($base)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $name = $file->getFilename();
            if (!str_ends_with($name, '.sh') && $name !== 'testkit') {
                continue;
            }
            $files[] = $file->getPathname();
        }
    }
    sort($files, SORT_STRING);
    return array_values(array_unique($files));
}

/** @return array<int,string> */
function static_node_files(string $root): array
{
    $files = [];
    foreach (['runners', 'utils', 'tests/fixtures/browser'] as $dir) {
        $base = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir);
        if (!is_dir($base)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'mjs') {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files, SORT_STRING);
    return $files;
}

/**
 * @param array<int,string> $files
 * @param callable(string):string $commandBuilder
 * @param callable(string):string $rerunBuilder
 * @return array<string,mixed>
 */
function run_static_check(string $label, array $files, callable $commandBuilder, callable $rerunBuilder, string $root): array
{
    $started = microtime(true);
    $passed = 0;
    $failures = [];
    foreach ($files as $file) {
        $output = [];
        $exitCode = 0;
        exec($commandBuilder($file) . ' 2>&1', $output, $exitCode);
        if ($exitCode === 0) {
            $passed++;
            continue;
        }
        $failures[] = [
            'label' => relative_path($root, $file),
            'exit_code' => $exitCode,
            'output' => implode("\n", $output),
            'rerun' => $rerunBuilder($file),
        ];
    }
    return [
        'label' => $label,
        'total' => count($files),
        'passed' => $passed,
        'failed' => count($failures),
        'skipped' => 0,
        'duration_ms' => (int)round((microtime(true) - $started) * 1000),
        'failures' => $failures,
    ];
}

function relative_path(string $root, string $path): string
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $path = str_replace('\\', '/', $path);
    $prefix = $root . '/';
    return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
}
