<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Policy;

use Testkit\Core\Common\Paths;
use Testkit\Core\DbProfiling\InstrumentationContext;

final class MysqlQueryPolicyConfig
{
    public const SCHEMA_VERSION = 'mysql-query-policy-v1';
    public const MODE_REPORT_ONLY = 'report_only';

    /** @return array<string,mixed> */
    public static function fromEnv(): array
    {
        $file = self::envString('TESTKIT_DB_PROFILE_POLICY_FILE');
        $mode = self::envString('TESTKIT_DB_PROFILE_POLICY_MODE', self::MODE_REPORT_ONLY);
        $report = self::envString(
            'TESTKIT_DB_PROFILE_POLICY_REPORT_PATH',
            Paths::reportsRoot() . '/mysql_policy_latest.json'
        );
        $history = self::envString(
            'TESTKIT_DB_PROFILE_POLICY_HISTORY_PATH',
            Paths::historyRoot() . '/mysql_policy'
        );

        return [
            'enabled' => $file !== '',
            'file' => $file,
            'mode' => $mode,
            'max_results' => self::envInt('TESTKIT_DB_PROFILE_POLICY_MAX_RESULTS', 500, 1, 5000),
            'output' => [
                'report_path' => Paths::normalize($report),
                'history_path' => Paths::normalize($history),
            ],
        ];
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    public static function publicConfig(array $config): array
    {
        return [
            'enabled' => (bool)($config['enabled'] ?? false),
            'mode' => (string)($config['mode'] ?? self::MODE_REPORT_ONLY),
            'file' => InstrumentationContext::normalizePath((string)($config['file'] ?? '')),
            'max_results' => (int)($config['max_results'] ?? 500),
            'output' => [
                'report_path' => InstrumentationContext::normalizePath((string)($config['output']['report_path'] ?? '')),
                'history_path' => InstrumentationContext::normalizePath((string)($config['output']['history_path'] ?? '')),
            ],
        ];
    }

    public static function disabledResult(): array
    {
        return [
            'enabled' => false,
            'mode' => self::MODE_REPORT_ONLY,
            'schema_version' => self::SCHEMA_VERSION,
            'policy_set_id' => '',
            'policy_file' => '',
            'policy_file_hash' => '',
            'loaded_policies' => 0,
            'applicable_policies' => 0,
            'unused_policies' => [],
            'evaluated_budgets' => 0,
            'passed_budgets' => 0,
            'violated_budgets' => 0,
            'insufficient_data_budgets' => 0,
            'status_counts' => [],
            'results' => [],
            'effective_policies' => [],
            'conflicts' => [],
            'warnings' => [],
        ];
    }

    private static function envString(string $key, string $default = ''): string
    {
        $value = getenv($key);
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    private static function envInt(string $key, int $default, int $min, int $max): int
    {
        $value = getenv($key);
        if (!is_string($value) || preg_match('/^\d+$/', trim($value)) !== 1) {
            return $default;
        }
        return max($min, min($max, (int)$value));
    }
}
