<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/dbprofiling/bootstrap.php';

use Testkit\Core\Common\Paths;
use Testkit\Core\DbProfiling\MysqlProfileConfig;
use Testkit\Core\DbProfiling\Policy\MysqlQueryPolicyConfig;
use Testkit\Core\DbProfiling\Policy\MysqlQueryPolicyException;
use Testkit\Core\DbProfiling\Policy\MysqlQueryPolicyLoader;
use Testkit\Core\DbProfiling\Policy\MysqlQueryPolicyEvaluator;
use Testkit\Core\DbProfiling\Policy\MysqlQueryPolicyReporter;

try {
    $args = tk_policy_args($argv);
    if ($args['help']) {
        tk_policy_help();
        exit(0);
    }
    $profilePath = $args['profile'] !== ''
        ? Paths::normalize($args['profile'])
        : (string)(MysqlProfileConfig::fromEnv()['output']['report_path'] ?? Paths::reportsRoot() . '/mysql_profile_latest.json');
    $policyPath = $args['policy'] !== ''
        ? Paths::normalize($args['policy'])
        : (string)(MysqlQueryPolicyConfig::fromEnv()['file'] ?? '');
    if (!is_file($profilePath)) {
        throw new RuntimeException('Profile report not found: ' . Paths::relativeToRepo($profilePath), 2);
    }
    if ($policyPath === '') {
        throw new MysqlQueryPolicyException('No policy file configured.', '$', 'policy_file_not_configured');
    }
    $raw = file_get_contents($profilePath);
    if (!is_string($raw)) {
        throw new RuntimeException('Profile report cannot be read.', 2);
    }
    try {
        $profile = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('Invalid profile JSON: ' . $e->getMessage(), 2);
    }
    if (!is_array($profile)) {
        throw new RuntimeException('Profile root must be an object.', 2);
    }
    $policy = MysqlQueryPolicyLoader::load($policyPath);
    try {
        $policyRuntimeConfig = MysqlQueryPolicyConfig::fromEnv();
        $evaluation = MysqlQueryPolicyEvaluator::evaluate($profile, $policy, (int)($policyRuntimeConfig['max_results'] ?? 500));
    } catch (MysqlQueryPolicyException $e) {
        if ($e->errorCode() === 'profile_incompatible') {
            fwrite(STDERR, 'Incompatible profile: ' . $e->getMessage() . ' at ' . $e->jsonPath() . PHP_EOL);
            exit(4);
        }
        throw $e;
    }
    if ($args['json'] !== '') {
        MysqlQueryPolicyReporter::writeArtifacts($evaluation, [
            'enabled' => true,
            'file' => $policyPath,
            'mode' => MysqlQueryPolicyConfig::MODE_REPORT_ONLY,
            'max_results' => (int)($policyRuntimeConfig['max_results'] ?? 500),
            'output' => ['report_path' => Paths::normalize($args['json']), 'history_path' => ''],
        ]);
    }
    if ($args['format'] === 'json') {
        echo json_encode($evaluation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    } else {
        tk_policy_human($evaluation, (bool)$args['show_passed'], (bool)$args['show_unused'], (int)$args['top']);
    }
    exit(0);
} catch (MysqlQueryPolicyException $e) {
    fwrite(STDERR, 'Invalid policy: ' . $e->getMessage() . ' at ' . $e->jsonPath() . ' [' . $e->errorCode() . ']' . PHP_EOL);
    exit(in_array($e->errorCode(), ['policy_file_not_found', 'policy_file_unreadable'], true) ? 2 : 3);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit($e->getCode() === 2 ? 2 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, 'Policy evaluation failed: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

/** @param array<int,string> $argv @return array<string,mixed> */
function tk_policy_args(array $argv): array
{
    $out = [
        'profile' => '', 'policy' => '', 'format' => 'human', 'json' => '',
        'show_passed' => false, 'show_unused' => false, 'top' => 50, 'help' => false,
    ];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = (string)$argv[$i];
        if ($arg === '--help' || $arg === '-h') {
            $out['help'] = true;
            continue;
        }
        if ($arg === '--show-passed') {
            $out['show_passed'] = true;
            continue;
        }
        if ($arg === '--show-unused') {
            $out['show_unused'] = true;
            continue;
        }
        foreach (['profile', 'policy', 'format', 'json', 'top'] as $key) {
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

function tk_policy_help(): void
{
    echo "MySQL Query Policy Report\n\n";
    echo "Usage:\n";
    echo "  php scripts/query_policy_report.php [options]\n\n";
    echo "Options:\n";
    echo "  --profile <path>       MySQL profile report (v1 or v2)\n";
    echo "  --policy <path>        mysql-query-policy-v1 JSON file\n";
    echo "  --format human|json    Output format\n";
    echo "  --json <path>          Atomically write JSON artifact\n";
    echo "  --show-passed          Include passing budgets in human output\n";
    echo "  --show-unused          Include unused policies in human output\n";
    echo "  --top <n>              Maximum results displayed/evaluated\n";
    echo "  --help                  Show this help\n";
}

/** @param array<string,mixed> $evaluation */
function tk_policy_human(array $evaluation, bool $showPassed, bool $showUnused, int $top): void
{
    echo "MySQL Query Policy Report\n";
    echo str_repeat('=', 78) . "\n\n";
    echo 'Policy set: ' . (string)($evaluation['policy_set_id'] ?? '') . "\n";
    echo 'Mode: ' . (string)($evaluation['mode'] ?? '') . "\n";
    echo 'Profile schema: ' . (string)($evaluation['profile_schema_version'] ?? '') . "\n";
    echo 'Policies loaded: ' . (int)($evaluation['loaded_policies'] ?? 0) . "\n";
    echo 'Policies applicable: ' . (int)($evaluation['applicable_policies'] ?? 0) . "\n";
    echo 'Policies unused: ' . count((array)($evaluation['unused_policies'] ?? [])) . "\n";
    echo 'Budgets evaluated: ' . (int)($evaluation['evaluated_budgets'] ?? 0) . "\n";
    echo 'Pass: ' . (int)($evaluation['passed_budgets'] ?? 0) . "\n";
    echo 'Violations: ' . (int)($evaluation['violated_budgets'] ?? 0) . "\n";
    echo 'Insufficient data: ' . (int)($evaluation['insufficient_data_budgets'] ?? 0) . "\n\n";

    echo "Top violations\n";
    $violations = array_values(array_filter(
        (array)($evaluation['results'] ?? []),
        static fn(mixed $result): bool => is_array($result) && ($result['status'] ?? '') === 'violation'
    ));
    $severityRank = ['error' => 3, 'warning' => 2, 'info' => 1];
    usort($violations, static function (array $a, array $b) use ($severityRank): int {
        $severity = (($severityRank[(string)($b['severity'] ?? 'info')] ?? 0)
            <=> ($severityRank[(string)($a['severity'] ?? 'info')] ?? 0));
        if ($severity !== 0) {
            return $severity;
        }
        $aDelta = is_numeric($a['delta'] ?? null) ? (float)$a['delta'] : 0.0;
        $bDelta = is_numeric($b['delta'] ?? null) ? (float)$b['delta'] : 0.0;
        return $bDelta <=> $aDelta;
    });
    $shown = 0;
    foreach ($violations as $result) {
        echo sprintf(
            "- [%s] %s | %s | actual=%s expected=%s | %s\n",
            (string)($result['severity'] ?? 'warning'),
            (string)($result['policy_id'] ?? ''),
            (string)($result['budget_key'] ?? ''),
            tk_policy_scalar($result['actual'] ?? null),
            tk_policy_scalar($result['expected'] ?? null),
            (string)($result['fingerprint'] ?? $result['matched_by'] ?? '')
        );
        if (++$shown >= $top) {
            break;
        }
    }
    if ($shown === 0) {
        echo "- none\n";
    }
    echo "\n";

    if ($showPassed) {
        echo "Passing budgets\n";
        foreach ((array)($evaluation['results'] ?? []) as $result) {
            if (is_array($result) && ($result['status'] ?? '') === 'pass') {
                echo '- ' . (string)($result['policy_id'] ?? '') . ' | ' . (string)($result['budget_key'] ?? '') . "\n";
            }
        }
        echo "\n";
    }
    if ($showUnused) {
        echo "Unused policies\n";
        foreach ((array)($evaluation['unused_policies'] ?? []) as $unused) {
            if (is_array($unused)) {
                echo '- ' . (string)($unused['policy_id'] ?? '') . ' | ' . (string)($unused['status'] ?? '') . "\n";
            }
        }
        echo "\n";
    }
    echo 'Policy conflicts: ' . count((array)($evaluation['conflicts'] ?? [])) . "\n";
}

function tk_policy_scalar(mixed $value): string
{
    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    }
    if ($value === null) {
        return 'n/a';
    }
    return is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
}
