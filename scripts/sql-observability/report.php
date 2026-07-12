#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/reporting.php';

function sqlobsReportUsage(): void
{
    echo <<<'TXT'
Testkit SQL observability reporting

Usage:
  php scripts/sql-observability/report.php validate --root <dir> [--config <file>] [--manifest <file> ...] [--history-manifest <file> ...]
  php scripts/sql-observability/report.php build --root <dir> --output <dir> [--config <file>] [--manifest <file> ...] [--history-manifest <file> ...] [--generated-at <UTC>] [--fail-on-blocked]
  php scripts/sql-observability/report.php inspect --report <file>

Exit codes:
  0 generated or valid
  2 operational error
  3 invalid configuration or schema
  4 incomplete, incompatible or invalid evidence (validate only)
  5 consolidated state blocked when --fail-on-blocked is explicit
TXT;
    echo PHP_EOL;
}

/** @return array<string,mixed> */
function sqlobsReportArgs(array $argv): array
{
    $out = ['manifest' => [], 'history-manifest' => []];
    $flags = ['fail-on-blocked'];
    $values = ['root','output','config','report','manifest','history-manifest','generated-at'];
    for ($i = 2; $i < count($argv); $i++) {
        $arg = (string)$argv[$i];
        if ($arg === '--help' || $arg === '-h') {
            $out['help'] = true;
            continue;
        }
        if (!str_starts_with($arg, '--')) {
            throw new SqlObsReportException('Unexpected positional argument: ' . $arg, 3);
        }
        $raw = substr($arg, 2);
        [$key, $value] = str_contains($raw, '=') ? explode('=', $raw, 2) : [$raw, null];
        if (in_array($key, $flags, true)) {
            if ($value !== null) {
                throw new SqlObsReportException('Flag does not accept a value: --' . $key, 3);
            }
            $out[$key] = true;
            continue;
        }
        if (!in_array($key, $values, true)) {
            throw new SqlObsReportException('Unknown option --' . $key, 3);
        }
        if ($value === null) {
            if (!isset($argv[$i + 1]) || str_starts_with((string)$argv[$i + 1], '--')) {
                throw new SqlObsReportException('Missing value for --' . $key, 3);
            }
            $value = (string)$argv[++$i];
        }
        if (in_array($key, ['manifest','history-manifest'], true)) {
            $out[$key][] = $value;
        } else {
            $out[$key] = $value;
        }
    }
    return $out;
}

function sqlobsReportAbsolute(string $path): string
{
    if ($path === '') {
        return '';
    }
    if (str_starts_with($path, '/')) {
        return $path;
    }
    return getcwd() . '/' . ltrim($path, './');
}

try {
    $operation = (string)($argv[1] ?? '');
    if ($operation === '' || in_array($operation, ['help','--help','-h'], true)) {
        sqlobsReportUsage();
        exit(0);
    }
    $args = sqlobsReportArgs($argv);
    if (!empty($args['help'])) {
        sqlobsReportUsage();
        exit(0);
    }

    if ($operation === 'inspect') {
        $report = sqlobsReportAbsolute((string)($args['report'] ?? ''));
        if ($report === '') {
            throw new SqlObsReportException('--report is required.', 3);
        }
        echo json_encode(SqlObsReporting::inspectReport($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }

    if (!in_array($operation, ['validate','build'], true)) {
        throw new SqlObsReportException('Unknown operation: ' . $operation, 3);
    }

    $root = sqlobsReportAbsolute((string)($args['root'] ?? ''));
    if ($root === '') {
        throw new SqlObsReportException('--root is required.', 3);
    }
    $configPath = sqlobsReportAbsolute((string)($args['config'] ?? 'config/sql-observability/reporting.json'));
    $config = SqlObsReporting::loadConfig($configPath);
    $manifests = array_map('sqlobsReportAbsolute', (array)$args['manifest']);
    if ($manifests === []) {
        $manifests = SqlObsReporting::discoverManifests($root);
    }
    $history = array_map('sqlobsReportAbsolute', (array)$args['history-manifest']);
    $generatedAt = (string)($args['generated-at'] ?? getenv('SQLOBS_REPORT_NOW') ?: gmdate('Y-m-d\TH:i:s\Z'));
    $built = SqlObsReporting::build($manifests, $history, $config, $root, $generatedAt);

    if ($operation === 'validate') {
        $result = [
            'schema_version' => SqlObsReporting::REPORT_SCHEMA,
            'status' => $built['validation_status'],
            'selected_manifests' => count($manifests),
            'data_quality' => $built['report']['data_quality'],
            'overall_status' => $built['report']['overall_status'],
        ];
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(in_array($built['validation_status'], ['healthy','healthy_with_warnings'], true) ? 0 : 4);
    }

    $output = sqlobsReportAbsolute((string)($args['output'] ?? ''));
    if ($output === '') {
        throw new SqlObsReportException('--output is required for build.', 3);
    }
    $files = SqlObsReporting::writeOutputs($built, $config, $output);
    $validation = SqlObsReporting::validateFinalOutput($output);
    echo json_encode([
        'schema_version' => SqlObsReporting::REPORT_SCHEMA,
        'report_id' => $built['report']['report_id'],
        'overall_status' => $built['report']['overall_status'],
        'output' => $output,
        'files' => array_map('basename', $files),
        'manifest_validation' => $validation,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (!empty($args['fail-on-blocked']) && $built['report']['overall_status'] === 'blocked') {
        exit(5);
    }
    exit(0);
} catch (SqlObsReportException $e) {
    fwrite(STDERR, 'ERROR[sqlobs_report] ' . $e->getMessage() . PHP_EOL);
    exit($e->processExitCode());
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR[sqlobs_report_operational] ' . $e->getMessage() . PHP_EOL);
    exit(2);
}
