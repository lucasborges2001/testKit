<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Baseline;

use Testkit\Core\Common\Paths;
use Testkit\Core\DbProfiling\InstrumentationContext;

final class MysqlQueryBaselineConfig
{
    public const BASELINE_SCHEMA_VERSION = 'mysql-query-baseline-v1';
    public const COMPARISON_SCHEMA_VERSION = 'mysql-query-comparison-report-v1';
    public const MODE_REPORT_ONLY = 'report_only';

    /** @return array<string,mixed> */
    public static function fromEnv(): array
    {
        $file = self::envString('TESTKIT_DB_PROFILE_BASELINE_FILE');
        $mode = strtolower(self::envString('TESTKIT_DB_PROFILE_BASELINE_MODE', self::MODE_REPORT_ONLY));
        $reportPath = self::envString(
            'TESTKIT_DB_PROFILE_BASELINE_REPORT_PATH',
            Paths::reportsRoot() . '/mysql_comparison_latest.json'
        );
        $historyPath = self::envString(
            'TESTKIT_DB_PROFILE_BASELINE_HISTORY_PATH',
            Paths::historyRoot() . '/mysql_comparison'
        );

        return [
            'enabled' => $file !== '',
            'file' => Paths::normalize($file),
            'mode' => $mode,
            'max_results' => self::envInt('TESTKIT_DB_PROFILE_BASELINE_MAX_RESULTS', 5000, 1, 5000),
            'output' => [
                'report_path' => Paths::normalize($reportPath),
                'history_path' => Paths::normalize($historyPath),
            ],
            'comparison_context' => self::comparisonContextFromEnv(),
        ];
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    public static function publicConfig(array $config): array
    {
        return [
            'enabled' => (bool)($config['enabled'] ?? false),
            'file' => InstrumentationContext::normalizePath((string)($config['file'] ?? '')),
            'mode' => (string)($config['mode'] ?? self::MODE_REPORT_ONLY),
            'max_results' => (int)($config['max_results'] ?? 5000),
            'output' => [
                'report_path' => InstrumentationContext::normalizePath(
                    (string)($config['output']['report_path'] ?? '')
                ),
                'history_path' => InstrumentationContext::normalizePath(
                    (string)($config['output']['history_path'] ?? '')
                ),
            ],
        ];
    }

    /** @return array<string,string> */
    public static function comparisonContextFromEnv(): array
    {
        $repository = self::envFirst(['TESTKIT_DB_PROFILE_REPOSITORY', 'GITHUB_REPOSITORY']);
        $commitSha = self::envFirst(['TESTKIT_DB_PROFILE_COMMIT_SHA', 'GITHUB_SHA', 'CI_COMMIT_SHA']);
        $branch = self::envFirst(['TESTKIT_DB_PROFILE_BRANCH', 'GITHUB_REF_NAME', 'CI_COMMIT_REF_NAME']);
        $suite = self::envFirst(['TESTKIT_DB_PROFILE_SUITE_ID', 'TEST_SUITE_ID', 'TEST_SUITE']);

        return self::sanitizeComparisonContext([
            'repository' => $repository,
            'commit_sha' => $commitSha,
            'branch' => $branch,
            'engine' => 'mysql',
            'engine_version' => self::envString('TESTKIT_DB_PROFILE_ENGINE_VERSION'),
            'dataset_id' => self::envString('TESTKIT_DB_PROFILE_DATASET_ID'),
            'dataset_version' => self::envString('TESTKIT_DB_PROFILE_DATASET_VERSION'),
            'dataset_hash' => self::envString('TESTKIT_DB_PROFILE_DATASET_HASH'),
            'environment_id' => self::envString('TESTKIT_DB_PROFILE_ENVIRONMENT_ID'),
            'suite_id' => $suite,
            'runtime_id' => self::envString('TESTKIT_DB_PROFILE_RUNTIME_ID'),
        ]);
    }

    /** @param array<string,mixed> $context @return array<string,string> */
    public static function sanitizeComparisonContext(array $context): array
    {
        $out = [];
        $limits = [
            'repository' => 240,
            'commit_sha' => 64,
            'branch' => 160,
            'engine' => 40,
            'engine_version' => 80,
            'dataset_id' => 160,
            'dataset_version' => 160,
            'dataset_hash' => 64,
            'environment_id' => 160,
            'suite_id' => 160,
            'runtime_id' => 160,
        ];
        foreach ($limits as $key => $limit) {
            $value = trim((string)($context[$key] ?? ''));
            if ($value === '') {
                $out[$key] = '';
                continue;
            }
            if ($key === 'commit_sha' || $key === 'dataset_hash') {
                $value = strtolower(preg_replace('/[^a-f0-9]/i', '', $value) ?? '');
            } elseif ($key === 'repository') {
                $value = preg_replace('#[^A-Za-z0-9._/-]+#', '_', $value) ?? '';
            } else {
                $value = self::safeIdentifier($value, $limit);
            }
            $out[$key] = substr($value, 0, $limit);
        }
        $out['engine'] = strtolower($out['engine'] !== '' ? $out['engine'] : 'mysql');
        return $out;
    }

    /** @return array<string,mixed> */
    public static function disabledResult(): array
    {
        return [
            'enabled' => false,
            'mode' => self::MODE_REPORT_ONLY,
            'schema_version' => self::COMPARISON_SCHEMA_VERSION,
            'status' => 'not_evaluated',
            'compatibility' => [
                'status' => 'insufficient_metadata',
                'comparison_scope' => 'none',
                'timing_comparable' => false,
                'warnings' => [],
                'reasons' => ['baseline_not_configured'],
            ],
            'summary' => [
                'matched' => 0,
                'new' => 0,
                'removed' => 0,
                'regressed' => 0,
                'improved' => 0,
                'unchanged' => 0,
                'plan_changed' => 0,
                'insufficient_data' => 0,
            ],
            'report_path' => '',
            'top_findings' => [],
        ];
    }

    private static function safeIdentifier(string $value, int $limit): string
    {
        $value = trim($value);
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', '', $value) ?? '';
        if (
            preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', $value) === 1
            || preg_match('/\b(?:mysql|pgsql|sqlsrv):/i', $value) === 1
            || preg_match('/\b(?:bearer\s+)[A-Za-z0-9._-]+/i', $value) === 1
        ) {
            return 'redacted';
        }
        $value = preg_replace('/[^A-Za-z0-9._:\/-]+/', '_', $value) ?? '';
        return substr(trim($value, '_'), 0, max(1, $limit));
    }

    private static function envString(string $key, string $default = ''): string
    {
        $value = getenv($key);
        return !is_string($value) || trim($value) === '' ? $default : trim($value);
    }

    /** @param array<int,string> $keys */
    private static function envFirst(array $keys): string
    {
        foreach ($keys as $key) {
            $value = self::envString($key);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private static function envInt(string $key, int $default, int $min, int $max): int
    {
        $value = getenv($key);
        if (!is_string($value) || preg_match('/^-?\d+$/', trim($value)) !== 1) {
            return $default;
        }
        return max($min, min($max, (int)$value));
    }
}
