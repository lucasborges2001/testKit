#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/php/dbprofiling/bootstrap.php';

use Testkit\Core\DbProfiling\Gate\MysqlQueryBaselineApprovalEvaluator;
use Testkit\Core\DbProfiling\Gate\MysqlQueryGateArtifactWriter;
use Testkit\Core\DbProfiling\Gate\MysqlQueryGateConfig;
use Testkit\Core\DbProfiling\Gate\MysqlQueryGateException;

function approvalUsage(): void
{
    echo <<<TXT
MySQL query baseline approval eligibility

Usage:
  php scripts/query_baseline_approval.php --gate-report <gate.json> [--comparison <comparison.json>] [--profile <profile.json>]

Options:
  --gate-report <path>  Required
  --comparison <path>
  --profile <path>
  --format human|json
  --json <path>
  --help

This command is report-only. It never creates, accepts, promotes, or replaces a baseline.
TXT;
}

/** @return array<string,string|bool> */
function approvalArgs(array $argv): array
{
    $out = [];
    $allowed = ['gate-report', 'comparison', 'profile', 'format', 'json', 'help'];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = (string)$argv[$i];
        if (!str_starts_with($arg, '--')) {
            throw new MysqlQueryGateException('Unexpected positional argument.', '$.cli', 'unexpected_cli_argument');
        }
        $raw = substr($arg, 2);
        [$key, $value] = str_contains($raw, '=') ? explode('=', $raw, 2) : [$raw, null];
        if (in_array($key, ['accept', 'promote', 'update-baseline'], true)) {
            throw new MysqlQueryGateException('Automatic baseline acceptance is not supported.', '$.cli.' . $key, 'forbidden_approval_cli_option');
        }
        if (!in_array($key, $allowed, true)) {
            throw new MysqlQueryGateException('Unknown CLI option.', '$.cli.' . $key, 'unknown_approval_cli_option');
        }
        if ($key === 'help') {
            if ($value !== null) {
                throw new MysqlQueryGateException('Boolean CLI option does not accept a value.', '$.cli.help', 'invalid_cli_boolean_value');
            }
            $out[$key] = true;
            continue;
        }
        if ($value === null) {
            if ($i + 1 >= count($argv) || str_starts_with((string)$argv[$i + 1], '--')) {
                throw new MysqlQueryGateException('CLI option requires a value.', '$.cli.' . $key, 'missing_cli_option_value');
            }
            $value = (string)$argv[++$i];
        }
        $out[$key] = $value;
    }
    return $out;
}

try {
    $args = approvalArgs($argv);
    if (!empty($args['help'])) {
        approvalUsage();
        exit(0);
    }
    $gatePath = (string)($args['gate-report'] ?? '');
    if ($gatePath === '') {
        throw new MysqlQueryGateException('A gate report is required.', '$.cli.gate-report', 'gate_report_required');
    }
    $gate = MysqlQueryGateArtifactWriter::loadJson($gatePath);
    $comparison = isset($args['comparison']) ? MysqlQueryGateArtifactWriter::loadJson((string)$args['comparison']) : [];
    $profile = isset($args['profile']) ? MysqlQueryGateArtifactWriter::loadJson((string)$args['profile']) : [];
    $report = MysqlQueryBaselineApprovalEvaluator::evaluate($gate, $comparison, $profile);
    if (isset($args['json'])) {
        MysqlQueryGateArtifactWriter::writeJson((string)$args['json'], $report);
    }
    $format = strtolower((string)($args['format'] ?? 'human'));
    if ($format === 'json') {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    } elseif ($format === 'human') {
        echo "MySQL baseline approval eligibility\n";
        echo 'Status: ' . strtoupper((string)($report['status'] ?? 'insufficient_evidence')) . "\n";
        echo 'Eligible: ' . (!empty($report['eligible']) ? 'yes' : 'no') . "\n";
        echo 'Reason: ' . (string)($report['reason'] ?? '') . "\n";
        foreach ((array)($report['checks'] ?? []) as $check) {
            if (is_array($check)) {
                echo '  [' . strtoupper((string)($check['status'] ?? '')) . '] '
                    . (string)($check['id'] ?? '') . ' — ' . (string)($check['reason'] ?? '') . "\n";
            }
        }
        echo "No baseline was created or modified.\n";
    } else {
        throw new MysqlQueryGateException('Unsupported output format.', '$.cli.format', 'unsupported_approval_output_format');
    }
    exit(0);
} catch (MysqlQueryGateException $e) {
    fwrite(STDERR, 'ERROR[' . $e->errorCode() . '] ' . $e->jsonPath() . ': '
        . MysqlQueryGateArtifactWriter::sanitizeText($e->getMessage(), 300) . PHP_EOL);
    exit($e->exitCode());
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR[approval_unhandled] ' . MysqlQueryGateArtifactWriter::sanitizeText($e->getMessage(), 300) . PHP_EOL);
    exit(MysqlQueryGateConfig::EXIT_OPERATIONAL);
}
