#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/sqlstatic/bootstrap.php';

use Testkit\Core\SqlStatic\SqlBaselineComparator;
use Testkit\Core\SqlStatic\SqlStaticAuditor;
use Testkit\Core\SqlStatic\SqlStaticConsoleReporter;

function sql_static_usage(): string
{
    return <<<'TXT'
Usage: php scripts/sql_static_audit.php [options]
  --root=PATH             Host/project root. Defaults to TESTKIT_PROJECT_ROOT or cwd.
  --path=REL              Path to audit relative to root. Repeatable. Defaults to .
  --exclude=REL           Extra excluded path prefix. Repeatable.
  --format=human|compact|json
  --json=PATH             Persist the same report as JSON.
  --baseline=PATH         Compare findings with a previous JSON report.
  --help

Findings and deltas are report-only and never execute SQL. Exit 0 means the audit
ran, including when findings exist. Exit 2 means invalid input or operational error.
TXT;
}

/** @return array<string,mixed> */
function sql_static_options(array $argv): array
{
    $root = getenv('TESTKIT_PROJECT_ROOT') ?: getcwd();
    $options = ['root' => $root, 'paths' => [], 'excludes' => [], 'format' => 'human', 'json' => '', 'baseline' => ''];
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
        foreach (['root', 'format', 'json', 'baseline'] as $name) {
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

function sql_static_read_baseline(string $path): array
{
    if ($path === '' || !is_file($path)) {
        throw new InvalidArgumentException('SQL static baseline file does not exist.');
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('SQL static baseline must be valid JSON.');
    }
    return $decoded;
}

function sql_static_compact(array $report): string
{
    return SqlStaticConsoleReporter::compact($report);
}

function sql_static_human(array $report): string
{
    return SqlStaticConsoleReporter::human($report);
}

function sql_static_main(array $argv): int
{
    try {
        $options = sql_static_options($argv);
        if (($options['help'] ?? false) === true) {
            echo sql_static_usage() . PHP_EOL;
            return 0;
        }
        $report = SqlStaticAuditor::audit((string)$options['root'], (array)$options['paths'], (array)$options['excludes']);
        if ((string)$options['baseline'] !== '') {
            $report['delta'] = SqlBaselineComparator::compare($report, sql_static_read_baseline((string)$options['baseline']));
        }
        sql_static_write_json((string)$options['json'], $report);
        echo match ($options['format']) {
            'json' => json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            'compact' => sql_static_compact($report),
            default => sql_static_human($report),
        };
        return 0;
    } catch (Throwable $exception) {
        fwrite(STDERR, 'SQL static audit error: ' . $exception->getMessage() . PHP_EOL);
        return 2;
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(sql_static_main($argv));
}
