#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/dbprofiling/bootstrap.php';

use Testkit\Core\Common\Paths;
use Testkit\Core\DbProfiling\MysqlProfileConfig;
use Testkit\Core\DbProfiling\Baseline\MysqlQueryBaselineConfig;
use Testkit\Core\DbProfiling\Baseline\MysqlQueryBaselineComparator;
use Testkit\Core\DbProfiling\Baseline\MysqlQueryBaselineException;
use Testkit\Core\DbProfiling\Baseline\MysqlQueryBaselineLoader;
use Testkit\Core\DbProfiling\Baseline\MysqlQueryBaselineReporter;

try {
    $args = tk_comparison_args($argv);
    if ($args['help']) {
        tk_comparison_help();
        exit(0);
    }
    $currentPath = $args['current'] !== ''
        ? Paths::normalize($args['current'])
        : (string)(MysqlProfileConfig::fromEnv()['output']['report_path'] ?? Paths::reportsRoot() . '/mysql_profile_latest.json');
    $baselinePath = $args['baseline'] !== ''
        ? Paths::normalize($args['baseline'])
        : (string)(MysqlQueryBaselineConfig::fromEnv()['file'] ?? '');
    if (!is_file($currentPath)) {
        throw new RuntimeException('Current profile not found: ' . Paths::relativeToRepo($currentPath), 2);
    }
    if ($baselinePath === '' || !is_file($baselinePath)) {
        throw new RuntimeException('Baseline not found: ' . Paths::relativeToRepo($baselinePath), 2);
    }

    $raw = file_get_contents($currentPath);
    if (!is_string($raw)) {
        throw new RuntimeException('Current profile cannot be read.', 2);
    }
    try {
        $current = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new MysqlQueryBaselineException(
            'Invalid current profile JSON: ' . $e->getMessage(),
            '$',
            'current_profile_incompatible'
        );
    }
    if (!is_array($current)) {
        throw new MysqlQueryBaselineException(
            'Current profile root must be an object.',
            '$',
            'current_profile_incompatible'
        );
    }

    $baseline = MysqlQueryBaselineLoader::load($baselinePath);
    $config = MysqlQueryBaselineConfig::fromEnv();
    $comparison = MysqlQueryBaselineComparator::compare(
        $current,
        $baseline,
        (int)($config['max_results'] ?? 5000)
    );

    if ($args['json'] !== '') {
        MysqlQueryBaselineReporter::writeJsonAtomic(
            Paths::normalize($args['json']),
            MysqlQueryBaselineReporter::publicComparison($comparison)
        );
    }

    if ($args['format'] === 'json') {
        echo json_encode(
            MysqlQueryBaselineReporter::publicComparison($comparison),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    } else {
        tk_comparison_human($comparison, (int)$args['top'], (bool)$args['show-unchanged']);
    }
    exit(0);
} catch (MysqlQueryBaselineException $e) {
    fwrite(
        STDERR,
        'Comparison contract error: '
        . $e->getMessage()
        . ' at '
        . $e->jsonPath()
        . ' ['
        . $e->errorCode()
        . ']'
        . PHP_EOL
    );
    exit($e->errorCode() === 'current_profile_incompatible' ? 4 : 3);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, 'Comparison failed: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

/** @param array<int,string> $argv @return array<string,mixed> */
function tk_comparison_args(array $argv): array
{
    $out = [
        'current' => '', 'baseline' => '', 'format' => 'human', 'json' => '',
        'top' => 50, 'show-unchanged' => false, 'help' => false,
    ];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = (string)$argv[$i];
        if ($arg === '--help' || $arg === '-h') {
            $out['help'] = true;
            continue;
        }
        if ($arg === '--show-unchanged') {
            $out['show-unchanged'] = true;
            continue;
        }
        foreach (['current', 'baseline', 'format', 'json', 'top'] as $key) {
            $flag = '--' . $key;
            if ($arg === $flag) {
                if (!isset($argv[$i + 1])) {
                    throw new RuntimeException('Missing value for ' . $flag, 2);
                }
                $out[$key] = (string)$argv[++$i];
                continue 2;
            }
            if (str_starts_with($arg, $flag . '=')) {
                $out[$key] = substr($arg, strlen($flag) + 1);
                continue 2;
            }
        }
        throw new RuntimeException('Unknown option: ' . $arg, 2);
    }
    if (!in_array($out['format'], ['human', 'json'], true)) {
        throw new RuntimeException('--format must be human or json.', 2);
    }
    if (!preg_match('/^\d+$/', (string)$out['top'])) {
        throw new RuntimeException('--top must be a positive integer.', 2);
    }
    $out['top'] = max(1, min(5000, (int)$out['top']));
    return $out;
}

function tk_comparison_help(): void
{
    echo "MySQL Query Baseline Comparison\n\n";
    echo "Usage:\n";
    echo "  php scripts/query_comparison_report.php [options]\n\n";
    echo "Options:\n";
    echo "  --current <path>        Current mysql-query-profile report\n";
    echo "  --baseline <path>       mysql-query-baseline-v1 file\n";
    echo "  --format human|json\n";
    echo "  --json <path>           Atomically write comparison JSON\n";
    echo "  --top <n>\n";
    echo "  --show-unchanged\n";
    echo "  --help\n";
}

/** @param array<string,mixed> $comparison */
function tk_comparison_human(array $comparison, int $top, bool $showUnchanged): void
{
    echo "SQL baseline comparison\n";
    echo str_repeat('=', 78) . "\n";
    echo 'Baseline: ' . (string)($comparison['baseline']['id'] ?? '') . PHP_EOL;
    echo 'Current run: ' . (string)($comparison['current']['run_id'] ?? '') . PHP_EOL;
    echo 'Compatibility: ' . (string)($comparison['compatibility']['status'] ?? '') . PHP_EOL;
    echo 'Scope: ' . (string)($comparison['compatibility']['comparison_scope'] ?? '') . PHP_EOL;
    echo "\nSummary\n";
    foreach (['matched', 'regressed', 'improved', 'unchanged', 'plan_changed', 'new', 'removed', 'insufficient_data'] as $key) {
        echo '  ' . $key . ': ' . (int)($comparison['summary'][$key] ?? 0) . PHP_EOL;
    }

    echo "\nGlobal deltas\n";
    foreach ((array)($comparison['global_metrics'] ?? []) as $metric) {
        if (!is_array($metric)) {
            continue;
        }
        echo sprintf(
            "  %s: %s -> %s (delta=%s, pct=%s, status=%s)\n",
            (string)($metric['metric'] ?? ''),
            tk_comparison_scalar($metric['baseline'] ?? null),
            tk_comparison_scalar($metric['current'] ?? null),
            tk_comparison_scalar($metric['delta'] ?? null),
            tk_comparison_scalar($metric['delta_pct'] ?? null),
            (string)($metric['status'] ?? '')
        );
    }

    $groups = [
        'Top regressions' => 'regressed',
        'Improvements' => 'improved',
        'Plan changes' => 'plan_changed',
        'Insufficient data' => 'insufficient_data',
    ];
    foreach ($groups as $title => $status) {
        echo "\n" . $title . "\n";
        $shown = 0;
        foreach ((array)($comparison['queries'] ?? []) as $query) {
            if (!is_array($query) || ($query['overall_status'] ?? '') !== $status) {
                continue;
            }
            echo '  ' . (string)($query['identity'] ?? '') . PHP_EOL;
            foreach ((array)($query['metric_results'] ?? []) as $metric) {
                if (!is_array($metric) || !in_array((string)($metric['status'] ?? ''), ['regressed', 'improved'], true)) {
                    continue;
                }
                echo sprintf(
                    "    %s: %s -> %s (delta=%s, pct=%s)\n",
                    (string)($metric['metric'] ?? ''),
                    tk_comparison_scalar($metric['baseline'] ?? null),
                    tk_comparison_scalar($metric['current'] ?? null),
                    tk_comparison_scalar($metric['delta'] ?? null),
                    tk_comparison_scalar($metric['delta_pct'] ?? null)
                );
            }
            $planStatus = (string)($query['plan_result']['status'] ?? '');
            if ($planStatus !== '' && $planStatus !== 'unchanged') {
                echo '    plan: ' . $planStatus . PHP_EOL;
            }
            if (++$shown >= $top) {
                break;
            }
        }
        if ($shown === 0) {
            echo "  none\n";
        }
    }

    echo "\nNew queries\n";
    tk_comparison_identity_list((array)($comparison['new_queries'] ?? []), $top);
    echo "\nRemoved queries\n";
    tk_comparison_identity_list((array)($comparison['removed_queries'] ?? []), $top);

    if ($showUnchanged) {
        echo "\nUnchanged\n";
        $rows = array_values(array_filter(
            (array)($comparison['queries'] ?? []),
            static fn(mixed $row): bool => is_array($row) && ($row['overall_status'] ?? '') === 'unchanged'
        ));
        tk_comparison_identity_list($rows, $top);
    }

    echo "\nLimitations\n";
    foreach ((array)($comparison['limitations'] ?? []) as $limitation) {
        echo '  - ' . (string)$limitation . PHP_EOL;
    }
}

/** @param array<int,mixed> $rows */
function tk_comparison_identity_list(array $rows, int $top): void
{
    $shown = 0;
    foreach ($rows as $row) {
        if (is_array($row)) {
            echo '  - ' . (string)($row['identity'] ?? '') . PHP_EOL;
            if (++$shown >= $top) {
                break;
            }
        }
    }
    if ($shown === 0) {
        echo "  none\n";
    }
}

function tk_comparison_scalar(mixed $value): string
{
    if ($value === null) {
        return 'n/a';
    }
    if (is_float($value)) {
        return rtrim(rtrim(sprintf('%.3f', $value), '0'), '.');
    }
    return is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
}
