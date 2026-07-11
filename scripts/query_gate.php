#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/php/dbprofiling/bootstrap.php';

use Testkit\Core\DbProfiling\Gate\MysqlQueryGateArtifactWriter;
use Testkit\Core\DbProfiling\Gate\MysqlQueryGateConfig;
use Testkit\Core\DbProfiling\Gate\MysqlQueryGateException;
use Testkit\Core\DbProfiling\Gate\MysqlQueryGateReporter;

/** @return array<string,mixed> */
function sqlGateArgs(array $argv): array
{
    $out = ['comparison' => []];
    $booleans = ['help', 'github-annotations'];
    $values = ['profile', 'policy-report', 'comparison', 'evidence', 'gate', 'allowlist', 'mode', 'format', 'json', 'junit', 'sarif', 'summary', 'top'];
    $forbidden = ['accept', 'promote', 'update-baseline'];
    for ($i = 1, $count = count($argv); $i < $count; $i++) {
        $arg = (string)$argv[$i];
        if (!str_starts_with($arg, '--')) {
            throw new MysqlQueryGateException('Unexpected positional argument.', '$.cli', 'unexpected_cli_argument');
        }
        $raw = substr($arg, 2);
        [$key, $value] = str_contains($raw, '=') ? explode('=', $raw, 2) : [$raw, null];
        if (in_array($key, $forbidden, true)) {
            throw new MysqlQueryGateException('Automatic baseline acceptance is not supported.', '$.cli.' . $key, 'forbidden_gate_cli_option');
        }
        if (in_array($key, $booleans, true)) {
            if ($value !== null) {
                throw new MysqlQueryGateException('Boolean CLI option does not accept a value.', '$.cli.' . $key, 'invalid_cli_boolean_value');
            }
            $out[$key] = true;
            continue;
        }
        if (!in_array($key, $values, true)) {
            throw new MysqlQueryGateException('Unknown CLI option.', '$.cli.' . $key, 'unknown_gate_cli_option');
        }
        if ($value === null) {
            if ($i + 1 >= $count || str_starts_with((string)$argv[$i + 1], '--')) {
                throw new MysqlQueryGateException('CLI option requires a value.', '$.cli.' . $key, 'missing_cli_option_value');
            }
            $value = (string)$argv[++$i];
        }
        if ($key === 'comparison') {
            $out['comparison'][] = $value;
        } else {
            $out[$key] = $value;
        }
    }
    return $out;
}

function sqlGateUsage(): void
{
    echo <<<TXT
SQL query gate

Usage:
  php scripts/query_gate.php --profile <profile.json> --gate <gate.json> [options]

Options:
  --profile <path>
  --policy-report <path>
  --comparison <path>       Repeatable, maximum 20
  --evidence <path>
  --gate <path>
  --allowlist <path>
  --mode off|report|warn|fail
  --format human|json
  --json <path>
  --junit <path>
  --sarif <path>
  --summary <path>
  --github-annotations
  --top <1..100>
  --help

Exit codes: 0 evaluated/no block, 2 operational, 3 invalid contract, 4 incompatible input, 5 blocked.
No option can accept, promote, or update a baseline.
TXT;
}

/** @return array<string,mixed> */
function sqlGateLoad(string $path): array
{
    $payload = MysqlQueryGateArtifactWriter::loadJson($path, 10485760);
    $payload['_artifact_path'] = $path;
    $payload['_artifact_hash'] = MysqlQueryGateArtifactWriter::fileHash($path);
    return $payload;
}

/** @param array<string,mixed> $report */
function sqlGatePrintHuman(array $report, int $top): void
{
    $summary = (array)($report['summary'] ?? []);
    $decision = (array)($report['decision'] ?? []);
    echo "SQL query gate\n";
    echo 'Gate: ' . (string)($report['gate_id'] ?? '') . "\n";
    echo 'Mode: ' . (string)($report['mode'] ?? 'off') . "\n";
    echo 'Decision: ' . strtoupper((string)($decision['status'] ?? 'disabled')) . "\n";
    echo 'Exit code: ' . (int)($decision['exit_code'] ?? 0) . "\n\n";
    echo 'Inputs: profile=' . substr((string)($report['inputs']['profile_hash'] ?? ''), 0, 12)
        . ' policy=' . substr((string)($report['inputs']['policy_hash'] ?? ''), 0, 12)
        . ' baseline=' . substr((string)($report['inputs']['baseline_hash'] ?? ''), 0, 12) . "\n";
    echo 'Compatibility: comparisons=' . count((array)($report['inputs']['comparison_hashes'] ?? [])) . "\n";
    echo 'Stability: confirmed=' . (int)($report['stability']['confirmed'] ?? 0)
        . ' pending=' . (int)($summary['pending_stability'] ?? 0) . "\n";
    echo 'Blocking: ' . (int)($summary['blocking'] ?? 0) . "\n";
    echo 'Warnings: ' . (int)($summary['warnings'] ?? 0) . "\n";
    echo 'Observed: ' . (int)($summary['observed'] ?? 0) . "\n";
    echo 'Suppressed: ' . (int)($summary['suppressed'] ?? 0) . "\n";
    echo 'Pending stability: ' . (int)($summary['pending_stability'] ?? 0) . "\n";
    echo 'Expired allowlist: ' . count((array)($report['allowlist']['expired'] ?? [])) . "\n";
    echo "\nFindings\n";
    foreach (array_slice((array)($report['findings'] ?? []), 0, $top) as $finding) {
        if (!is_array($finding)) {
            continue;
        }
        echo '  [' . strtoupper((string)($finding['decision_effective'] ?? 'observe')) . '] '
            . (string)($finding['category'] ?? '') . ' '
            . (string)($finding['query_identity'] ?? 'global') . "\n";
        echo '    ' . MysqlQueryGateArtifactWriter::sanitizeText((string)($finding['message'] ?? ''), 300) . "\n";
        if (isset($finding['stability'])) {
            echo '    stability=' . (string)($finding['stability_status'] ?? '')
                . ' confirmations=' . (int)($finding['stability']['confirmations'] ?? 0)
                . '/' . (int)($finding['stability']['required_confirmations'] ?? 0)
                . ' runs=' . (int)($finding['stability']['runs_observed'] ?? 0)
                . '/' . (int)($finding['stability']['required_runs'] ?? 0) . "\n";
        }
    }
    echo "\nOutputs\n";
    foreach ((array)($report['outputs'] ?? []) as $name => $path) {
        if (is_string($path) && $path !== '') {
            echo '  ' . $name . ': ' . $path . "\n";
        }
    }
    $limitations = (array)($report['limitations'] ?? []);
    if ($limitations !== []) {
        echo "\nLimitations\n";
        foreach ($limitations as $limitation) {
            echo '  - ' . MysqlQueryGateArtifactWriter::sanitizeText((string)$limitation, 300) . "\n";
        }
    }
}

try {
    $args = sqlGateArgs($argv);
    if (!empty($args['help'])) {
        sqlGateUsage();
        exit(0);
    }
    $runtime = MysqlQueryGateConfig::fromEnv(isset($args['mode']) ? (string)$args['mode'] : null);
    foreach ([
        'gate' => 'file',
        'allowlist' => 'allowlist_file',
        'evidence' => 'evidence_file',
        'mode' => 'mode_override',
    ] as $cli => $key) {
        if (isset($args[$cli])) {
            $runtime[$key] = (string)$args[$cli];
        }
    }
    $runtime['enabled'] = (string)($runtime['file'] ?? '') !== '';
    if (!$runtime['enabled']) {
        throw new MysqlQueryGateException('A gate config is required.', '$.cli.gate', 'gate_file_required');
    }
    if (isset($args['github-annotations'])) {
        $runtime['github_annotations'] = true;
    }
    $top = 20;
    if (isset($args['top'])) {
        if (!ctype_digit((string)$args['top']) || (int)$args['top'] < 1 || (int)$args['top'] > 100) {
            throw new MysqlQueryGateException('Top must be an integer from 1 to 100.', '$.cli.top', 'invalid_gate_top');
        }
        $top = (int)$args['top'];
    }
    $format = strtolower((string)($args['format'] ?? 'human'));
    if (!in_array($format, ['human', 'json'], true)) {
        throw new MysqlQueryGateException('Unsupported output format.', '$.cli.format', 'unsupported_gate_output_format');
    }
    foreach (['json' => 'report_path', 'junit' => 'junit_path', 'sarif' => 'sarif_path', 'summary' => 'summary_path'] as $cli => $pathKey) {
        if (isset($args[$cli])) {
            $runtime['output'][$pathKey] = (string)$args[$cli];
        }
    }

    $profile = isset($args['profile']) ? sqlGateLoad((string)$args['profile']) : [
        'report_version' => 2,
        'schema_version' => 'mysql-query-profile-report-v2',
        'profile_enabled' => false,
        'queries' => [],
        'instrumentation' => ['findings' => []],
        'policy_evaluation' => ['enabled' => false],
        'baseline_comparison' => ['enabled' => false],
        'comparison_context' => [],
        'run_id' => 'comparison-only',
        'suite_id' => '',
    ];
    $policy = isset($args['policy-report']) ? sqlGateLoad((string)$args['policy-report']) : [];
    $comparisonPaths = (array)($args['comparison'] ?? []);
    if (count($comparisonPaths) > 20) {
        throw new MysqlQueryGateException('At most 20 comparison artifacts are supported.', '$.cli.comparison', 'too_many_comparisons');
    }
    $comparisons = array_map(static fn(string $path): array => sqlGateLoad($path), $comparisonPaths);
    $report = MysqlQueryGateReporter::evaluate($profile, $runtime, $policy, $comparisons);
    foreach (['json', 'junit', 'sarif', 'summary'] as $key) {
        if (isset($args[$key])) {
            $report['config']['outputs'][$key] = true;
        }
    }
    if (isset($args['github-annotations'])) {
        $report['config']['outputs']['github_annotations'] = true;
    }
    MysqlQueryGateReporter::writeArtifacts($report, $runtime, $top);

    if ($format === 'json') {
        echo json_encode(MysqlQueryGateArtifactWriter::sanitizeRecursive($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    } else {
        sqlGatePrintHuman($report, $top);
    }
    exit((int)($report['decision']['exit_code'] ?? MysqlQueryGateConfig::EXIT_OK));
} catch (MysqlQueryGateException $e) {
    fwrite(STDERR, 'ERROR[' . $e->errorCode() . '] ' . $e->jsonPath() . ': '
        . MysqlQueryGateArtifactWriter::sanitizeText($e->getMessage(), 300) . PHP_EOL);
    exit($e->exitCode());
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR[gate_unhandled] ' . MysqlQueryGateArtifactWriter::sanitizeText($e->getMessage(), 300) . PHP_EOL);
    exit(MysqlQueryGateConfig::EXIT_OPERATIONAL);
}
