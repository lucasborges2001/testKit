<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Policy;

use Testkit\Core\Common\Paths;
use Testkit\Core\DbProfiling\InstrumentationContext;

final class MysqlQueryPolicyReporter
{
    /** @param array<string,mixed> $profile @param array<string,mixed>|null $config @return array<string,mixed> */
    public static function evaluate(array $profile, ?array $config = null): array
    {
        $config ??= MysqlQueryPolicyConfig::fromEnv();
        if (!(bool)($config['enabled'] ?? false)) {
            return MysqlQueryPolicyConfig::disabledResult();
        }
        if (($config['mode'] ?? '') !== MysqlQueryPolicyConfig::MODE_REPORT_ONLY) {
            throw new MysqlQueryPolicyException('Only report_only mode is supported.', '$.policy_set.mode', 'unsupported_policy_mode');
        }
        $policy = MysqlQueryPolicyLoader::load((string)($config['file'] ?? ''));
        return MysqlQueryPolicyEvaluator::evaluate($profile, $policy, (int)($config['max_results'] ?? 500));
    }

    /** @param array<string,mixed> $evaluation @param array<string,mixed>|null $config */
    public static function writeArtifacts(array $evaluation, ?array $config = null): void
    {
        if (empty($evaluation['enabled'])) {
            return;
        }
        $config ??= MysqlQueryPolicyConfig::fromEnv();
        $payload = [
            'artifact_version' => 1,
            'schema_version' => MysqlQueryPolicyConfig::SCHEMA_VERSION,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'policy_evaluation' => $evaluation,
        ];
        $reportPath = (string)($config['output']['report_path'] ?? '');
        if ($reportPath !== '') {
            self::writeJsonAtomic($reportPath, $payload);
        }
        $historyPath = (string)($config['output']['history_path'] ?? '');
        if ($historyPath !== '') {
            self::writeJsonAtomic(
                rtrim($historyPath, '/\\') . '/mysql_policy_' . gmdate('Ymd_His') . '_' . self::token(6) . '.json',
                $payload
            );
        }
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $evaluation @return array<string,mixed> */
    public static function attachToProfile(array $profile, array $evaluation): array
    {
        $summaries = is_array($evaluation['query_summaries'] ?? null) ? $evaluation['query_summaries'] : [];
        if (isset($profile['queries']) && is_array($profile['queries'])) {
            foreach ($profile['queries'] as $index => &$query) {
                if (!is_array($query)) {
                    continue;
                }
                $summary = is_array($summaries[$index] ?? null) ? $summaries[$index] : [
                    'policy_status' => 'not_applicable',
                    'applied_policy_ids' => [],
                    'violations_count' => 0,
                ];
                $query['policy_status'] = (string)$summary['policy_status'];
                $query['applied_policy_ids'] = array_values((array)$summary['applied_policy_ids']);
                $query['violations_count'] = (int)$summary['violations_count'];
            }
            unset($query);
        }
        unset($evaluation['query_summaries']);
        $profile['policy_evaluation'] = $evaluation;
        return $profile;
    }

    /** @return array<string,mixed> */
    public static function invalidEvaluation(MysqlQueryPolicyException $e): array
    {
        return [
            'enabled' => true,
            'mode' => MysqlQueryPolicyConfig::MODE_REPORT_ONLY,
            'schema_version' => MysqlQueryPolicyConfig::SCHEMA_VERSION,
            'policy_set_id' => '',
            'policy_file' => InstrumentationContext::normalizePath((string)(getenv('TESTKIT_DB_PROFILE_POLICY_FILE') ?: '')),
            'policy_file_hash' => '',
            'loaded_policies' => 0,
            'applicable_policies' => 0,
            'unused_policies' => [],
            'evaluated_budgets' => 0,
            'passed_budgets' => 0,
            'violated_budgets' => 0,
            'insufficient_data_budgets' => 0,
            'status_counts' => ['invalid_policy' => 1],
            'results' => [[
                'policy_id' => '',
                'budget_key' => '',
                'status' => 'invalid_policy',
                'severity' => 'error',
                'selector' => [],
                'matched_by' => 'contract_validation',
                'actual' => null,
                'expected' => null,
                'operator' => '',
                'delta' => null,
                'unit' => '',
                'evidence_path' => $e->jsonPath(),
                'message' => InstrumentationContext::sanitizeText($e->getMessage(), 240),
                'error_code' => $e->errorCode(),
            ]],
            'effective_policies' => [],
            'conflicts' => [],
            'warnings' => [],
        ];
    }

    /** @param array<string,mixed> $payload */
    private static function writeJsonAtomic(string $path, array $payload): void
    {
        Paths::ensureDir(dirname($path));
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $tmp = $path . '.tmp.' . getmypid() . '.' . self::token(8);
        if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write policy artifact.');
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to publish policy artifact.');
        }
    }

    private static function token(int $length): string
    {
        try {
            return substr(bin2hex(random_bytes(max(1, (int)ceil($length / 2)))), 0, $length);
        } catch (\Throwable) {
            return substr(sha1(uniqid('', true)), 0, $length);
        }
    }
}
