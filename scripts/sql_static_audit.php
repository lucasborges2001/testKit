#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/sqlstatic/bootstrap.php';

use Testkit\Core\SqlStatic\SqlStaticAuditor;

function sql_static_usage(): string
{
    return <<<'TXT'
Usage: php scripts/sql_static_audit.php [options]
  --root=PATH             Host/project root. Defaults to TESTKIT_PROJECT_ROOT or cwd.
  --path=REL              Path to audit relative to root. Repeatable. Defaults to .
  --exclude=REL           Extra excluded path prefix. Repeatable.
  --format=human|compact|json
  --json=PATH             Persist the same report as JSON.
  --help

Findings are report-only and never execute SQL. Exit 0 means the audit ran,
including when findings exist. Exit 2 means invalid input or operational error.
TXT;
}

/** @return array<string,mixed> */
function sql_static_options(array $argv): array
{
    $root = getenv('TESTKIT_PROJECT_ROOT') ?: getcwd();
    $options = ['root' => $root, 'paths' => [], 'excludes' => [], 'format' => 'human', 'json' => ''];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help') {
            $options['help'] = true;
            continue;
        }
        if (str_starts_with($argument, '--path=')) {
            $options['paths'][] = substr($argument, 7);
            continue;
        }
        if (str_starts_with($argument, '--exclude=')) {
            $options['excludes'][] = substr($argument, 10);
            continue;
        }
        foreach (['root', 'format', 'json'] as $name) {
            $prefix = '--' . $name . '=';
            if (str_starts_with($argument, $prefix)) {
                $options[$name] = substr($argument, strlen($prefix));
                continue 2;
            }
        }
        throw new InvalidArgumentException('Unknown SQL static audit option: ' . $argument);
    }
    if (!in_array($options['format'], ['human', 'compact', 'json'], true)) {
        throw new InvalidArgumentException('Invalid --format value.');
    }
    return $options;
}

function sql_static_write_json(string $path, array $report): void
{
    if ($path === '') {
        return;
    }
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create SQL static audit report directory.');
    }
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || file_put_contents($path, $json . PHP_EOL) === false) {
        throw new RuntimeException('Unable to write SQL static audit report.');
    }
}

function sql_static_compact(array $report): string
{
    $summary = (array)($report['summary'] ?? []);
    return sprintf(
        'SQL static audit files=%d queries=%d findings=%d warn=%d watch=%d',
        (int)($report['scanned_files'] ?? 0),
        (int)($report['extracted_queries'] ?? 0),
        (int)($summary['findings'] ?? 0),
        (int)($summary['warn'] ?? 0),
        (int)($summary['watch'] ?? 0)
    ) . PHP_EOL;
}

function sql_static_human(array $report): string
{
    $lines = [rtrim(sql_static_compact($report)), str_repeat('=', 72)];
    foreach ((array)($report['findings'] ?? []) as $finding) {
        if (!is_array($finding)) {
            continue;
        }
        $lines[] = sprintf(
            '[%s/%s] %s :: %s:%d',
            strtoupper((string)$finding['severity']),
            strtoupper((string)$finding['confidence']),
            (string)$finding['rule_id'],
            (string)$finding['path'],
            (int)$finding['line']
        );
        $lines[] = '  ' . (string)$finding['sample_sql'];
        $lines[] = '  ' . (string)$finding['recommendation'];
    }
    if (($report['findings'] ?? []) === []) {
        $lines[] = 'No findings.';
    }
    return implode(PHP_EOL, $lines) . PHP_EOL;
}

function sql_static_main(array $argv): int
{
    try {
        $options = sql_static_options($argv);
        if (($options['help'] ?? false) === true) {
            echo sql_static_usage() . PHP_EOL;
            return 0;
        }
        $report = SqlStaticAuditor::audit(
            (string)$options['root'],
            (array)$options['paths'],
            (array)$options['excludes']
        );
        sql_static_write_json((string)$options['json'], $report);
        if ($options['format'] === 'json') {
            echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        } elseif ($options['format'] === 'compact') {
            echo sql_static_compact($report);
        } else {
            echo sql_static_human($report);
        }
        return 0;
    } catch (Throwable $exception) {
        fwrite(STDERR, 'SQL static audit error: ' . $exception->getMessage() . PHP_EOL);
        return 2;
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(sql_static_main($argv));
}
