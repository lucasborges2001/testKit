<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Baseline;

use Testkit\Core\Common\Paths;
use Testkit\Core\DbProfiling\InstrumentationContext;

final class MysqlQueryBaselineReporter
{
    /** @param array<string,mixed> $profile @param array<string,mixed>|null $config */
    public static function evaluate(array $profile, ?array $config = null): array
    {
        $config ??= MysqlQueryBaselineConfig::fromEnv();
        if (!(bool)($config['enabled'] ?? false)) {
            return MysqlQueryBaselineConfig::disabledResult();
        }
        if (($config['mode'] ?? '') !== MysqlQueryBaselineConfig::MODE_REPORT_ONLY) {
            throw new MysqlQueryBaselineException(
                'Only report_only baseline mode is supported.',
                '$.mode',
                'unsupported_baseline_mode'
            );
        }
        $baseline = MysqlQueryBaselineLoader::load((string)($config['file'] ?? ''));
        return MysqlQueryBaselineComparator::compare(
            $profile,
            $baseline,
            (int)($config['max_results'] ?? 5000)
        );
    }

    /** @param array<string,mixed> $comparison @param array<string,mixed>|null $config */
    public static function writeArtifacts(array $comparison, ?array $config = null): void
    {
        if (($comparison['schema_version'] ?? '') !== MysqlQueryBaselineConfig::COMPARISON_SCHEMA_VERSION) {
            return;
        }
        $config ??= MysqlQueryBaselineConfig::fromEnv();
        $reportPath = (string)($config['output']['report_path'] ?? '');
        if ($reportPath !== '') {
            self::writeJsonAtomic($reportPath, self::publicComparison($comparison));
        }
        $historyPath = (string)($config['output']['history_path'] ?? '');
        if ($historyPath !== '') {
            $comparisonId = preg_replace(
                '/[^A-Za-z0-9._-]+/',
                '_',
                (string)($comparison['comparison_id'] ?? 'comparison')
            ) ?: 'comparison';
            self::writeJsonAtomic(
                rtrim($historyPath, '/\\')
                . '/mysql_comparison_'
                . gmdate('Ymd_His')
                . '_'
                . self::token(8)
                . '_'
                . substr($comparisonId, 0, 80)
                . '.json',
                self::publicComparison($comparison)
            );
        }
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $comparison @return array<string,mixed> */
    public static function attachToProfile(array $profile, array $comparison): array
    {
        $summaries = is_array($comparison['_query_summaries'] ?? null)
            ? $comparison['_query_summaries']
            : [];
        if (isset($profile['queries']) && is_array($profile['queries'])) {
            $currentRows = MysqlQueryBaselineBuilder::extractQueries($profile);
            $identityByFingerprint = [];
            foreach ($currentRows as $row) {
                if (is_array($row)) {
                    $identityByFingerprint[(string)($row['fingerprint'] ?? '')] = (string)($row['identity'] ?? '');
                }
            }
            foreach ($profile['queries'] as &$query) {
                if (!is_array($query)) {
                    continue;
                }
                $fingerprint = \Testkit\Core\DbProfiling\SqlFingerprint::fingerprint(
                    (string)($query['fingerprint'] ?? $query['sample_sql'] ?? '')
                );
                $identity = $identityByFingerprint[$fingerprint] ?? '';
                $summary = is_array($summaries[$identity] ?? null)
                    ? $summaries[$identity]
                    : [
                        'baseline_status' => 'not_comparable',
                        'baseline_metric_regressions' => 0,
                        'baseline_plan_status' => 'insufficient_data',
                    ];
                $query['baseline_status'] = (string)$summary['baseline_status'];
                $query['baseline_metric_regressions'] = (int)$summary['baseline_metric_regressions'];
                $query['baseline_plan_status'] = (string)$summary['baseline_plan_status'];
            }
            unset($query);
        }

        unset($comparison['_query_summaries']);
        $config = MysqlQueryBaselineConfig::fromEnv();
        $reportPath = InstrumentationContext::normalizePath(
            (string)($config['output']['report_path'] ?? '')
        );
        $profile['baseline_comparison'] = [
            'enabled' => true,
            'mode' => MysqlQueryBaselineConfig::MODE_REPORT_ONLY,
            'schema_version' => MysqlQueryBaselineConfig::COMPARISON_SCHEMA_VERSION,
            'comparison_id' => (string)($comparison['comparison_id'] ?? ''),
            'status' => self::overallStatus($comparison),
            'baseline' => is_array($comparison['baseline'] ?? null) ? $comparison['baseline'] : [],
            'current' => is_array($comparison['current'] ?? null) ? $comparison['current'] : [],
            'compatibility' => is_array($comparison['compatibility'] ?? null)
                ? $comparison['compatibility']
                : [],
            'summary' => is_array($comparison['summary'] ?? null) ? $comparison['summary'] : [],
            'report_path' => $reportPath,
            'top_findings' => self::topFindings($comparison, 20),
            'warnings' => array_values((array)($comparison['warnings'] ?? [])),
            'limitations' => array_slice((array)($comparison['limitations'] ?? []), 0, 10),
        ];
        return $profile;
    }

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    public static function attachDisabled(array $profile): array
    {
        $profile['baseline_comparison'] = MysqlQueryBaselineConfig::disabledResult();
        return $profile;
    }

    /** @return array<string,mixed> */
    public static function invalidComparison(MysqlQueryBaselineException $e): array
    {
        return [
            'enabled' => true,
            'mode' => MysqlQueryBaselineConfig::MODE_REPORT_ONLY,
            'schema_version' => MysqlQueryBaselineConfig::COMPARISON_SCHEMA_VERSION,
            'status' => 'invalid_baseline',
            'compatibility' => [
                'status' => 'incompatible',
                'comparison_scope' => 'none',
                'timing_comparable' => false,
                'warnings' => [],
                'reasons' => [$e->errorCode()],
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
            'results' => [[
                'status' => 'invalid_baseline',
                'error_code' => $e->errorCode(),
                'evidence_path' => $e->jsonPath(),
                'message' => InstrumentationContext::sanitizeText($e->getMessage(), 240),
            ]],
            'report_path' => InstrumentationContext::normalizePath(
                (string)(getenv('TESTKIT_DB_PROFILE_BASELINE_REPORT_PATH') ?: '')
            ),
            'top_findings' => [],
        ];
    }

    /** @param array<string,mixed> $comparison @return array<string,mixed> */
    public static function publicComparison(array $comparison): array
    {
        unset($comparison['_query_summaries']);
        return $comparison;
    }

    /** @param array<string,mixed> $comparison */
    private static function overallStatus(array $comparison): string
    {
        $compatibility = (string)($comparison['compatibility']['status'] ?? '');
        if ($compatibility === 'incompatible') {
            return 'incompatible_context';
        }
        $summary = is_array($comparison['summary'] ?? null) ? $comparison['summary'] : [];
        if ((int)($summary['regressed'] ?? 0) > 0) {
            return 'regressed';
        }
        if ((int)($summary['plan_changed'] ?? 0) > 0) {
            return 'plan_changed';
        }
        if ((int)($summary['improved'] ?? 0) > 0) {
            return 'improved';
        }
        if ((int)($summary['insufficient_data'] ?? 0) > 0) {
            return 'insufficient_data';
        }
        return 'unchanged';
    }

    /** @param array<string,mixed> $comparison @return array<int,array<string,mixed>> */
    private static function topFindings(array $comparison, int $limit): array
    {
        $out = [];
        foreach ((array)($comparison['queries'] ?? []) as $query) {
            if (!is_array($query)) {
                continue;
            }
            $status = (string)($query['overall_status'] ?? '');
            if (!in_array($status, ['regressed', 'plan_changed', 'improved', 'insufficient_data'], true)) {
                continue;
            }
            $out[] = [
                'identity' => (string)($query['identity'] ?? ''),
                'status' => $status,
                'metric_regressions' => count(array_filter(
                    (array)($query['metric_results'] ?? []),
                    static fn(array $metric): bool => ($metric['status'] ?? '') === 'regressed'
                )),
                'plan_status' => (string)($query['plan_result']['status'] ?? ''),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $payload */
    public static function writeJsonAtomic(string $path, array $payload): void
    {
        Paths::ensureDir(dirname($path));
        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $tmp = $path . '.tmp.' . getmypid() . '.' . self::token(10);
        $handle = fopen($tmp, 'xb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Unable to create temporary comparison artifact.');
        }
        try {
            if (fwrite($handle, $json . PHP_EOL) === false) {
                throw new \RuntimeException('Unable to write comparison artifact.');
            }
            if (!fflush($handle)) {
                throw new \RuntimeException('Unable to flush comparison artifact.');
            }
            if (function_exists('fsync')) {
                @fsync($handle);
            }
        } finally {
            fclose($handle);
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to publish comparison artifact.');
        }
    }

    private static function token(int $length): string
    {
        try {
            return substr(bin2hex(random_bytes(max(1, (int)ceil($length / 2)))), 0, $length);
        } catch (\Throwable) {
            return substr(hash('sha256', uniqid('', true)), 0, $length);
        }
    }
}
