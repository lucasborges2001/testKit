<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Gate;

final class MysqlQueryGateFinding
{
    /** @var array<string,array{rule_id:string,description:string,severity:string,confidence:string}> */
    private const CATEGORIES = [
        'instrumentation.integrity' => ['rule_id' => 'TKSQL1001', 'description' => 'Instrumentation integrity issue', 'severity' => 'warning', 'confidence' => 'high'],
        'instrumentation.bypass' => ['rule_id' => 'TKSQL1002', 'description' => 'SQL instrumentation bypass', 'severity' => 'error', 'confidence' => 'high'],
        'policy.violation' => ['rule_id' => 'TKSQL2001', 'description' => 'SQL policy violation', 'severity' => 'warning', 'confidence' => 'high'],
        'baseline.temporal_regression' => ['rule_id' => 'TKSQL3001', 'description' => 'Temporal SQL regression', 'severity' => 'warning', 'confidence' => 'medium'],
        'baseline.structural_regression' => ['rule_id' => 'TKSQL3002', 'description' => 'Structural SQL regression', 'severity' => 'warning', 'confidence' => 'high'],
        'baseline.plan_regression' => ['rule_id' => 'TKSQL3003', 'description' => 'Query plan regression', 'severity' => 'error', 'confidence' => 'high'],
        'baseline.new_query' => ['rule_id' => 'TKSQL3004', 'description' => 'New query identity', 'severity' => 'warning', 'confidence' => 'high'],
        'baseline.removed_query' => ['rule_id' => 'TKSQL3005', 'description' => 'Removed query identity', 'severity' => 'info', 'confidence' => 'high'],
        'baseline.incompatible_context' => ['rule_id' => 'TKSQL3006', 'description' => 'Baseline context is incompatible', 'severity' => 'warning', 'confidence' => 'high'],
        'evidence.insufficient' => ['rule_id' => 'TKSQL4001', 'description' => 'Insufficient SQL evidence', 'severity' => 'warning', 'confidence' => 'high'],
        'evidence.invalid' => ['rule_id' => 'TKSQL4002', 'description' => 'Invalid embedded SQL evidence', 'severity' => 'error', 'confidence' => 'high'],
        'allowlist.expired' => ['rule_id' => 'TKSQL5001', 'description' => 'Expired SQL gate allowlist entry', 'severity' => 'warning', 'confidence' => 'high'],
        'allowlist.invalid' => ['rule_id' => 'TKSQL5002', 'description' => 'Invalid SQL gate allowlist', 'severity' => 'error', 'confidence' => 'high'],
        'baseline.approval_ineligible' => ['rule_id' => 'TKSQL6001', 'description' => 'Current run is not eligible for baseline approval', 'severity' => 'warning', 'confidence' => 'high'],
    ];

    private const NON_SUPPRESSIBLE = [
        'evidence.invalid',
        'allowlist.invalid',
        'security.secret_leakage',
        'security.path_traversal',
        'artifact.write_error',
    ];

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public static function make(array $data): array
    {
        $category = self::identifier((string)($data['category'] ?? 'evidence.invalid'), 100);
        $definition = self::definition($category);
        $severity = self::severity((string)($data['severity'] ?? $definition['severity']));
        $confidence = self::confidence((string)($data['confidence'] ?? $definition['confidence']));
        $location = self::normalizeLocation(is_array($data['location'] ?? null) ? $data['location'] : []);
        $identityParts = [
            $category,
            (string)($data['source_finding_id'] ?? ''),
            (string)($data['query_identity'] ?? ''),
            (string)($data['policy_id'] ?? ''),
            (string)($data['metric'] ?? ''),
            (string)($data['plan_flag'] ?? ''),
            (string)($data['suite_id'] ?? ''),
        ];
        $findingId = 'sqlgate_' . substr(hash('sha256', implode('|', $identityParts)), 0, 24);

        return [
            'finding_id' => $findingId,
            'rule_id' => $definition['rule_id'],
            'category' => $category,
            'subcategory' => self::identifier((string)($data['subcategory'] ?? ''), 120),
            'source' => self::identifier((string)($data['source'] ?? ''), 80),
            'source_artifact' => self::safePath((string)($data['source_artifact'] ?? '')),
            'source_finding_id' => self::identifier((string)($data['source_finding_id'] ?? ''), 160),
            'query_identity' => self::identifier((string)($data['query_identity'] ?? ''), 220),
            'query_id' => self::identifier((string)($data['query_id'] ?? ''), 160),
            'fingerprint_hash' => self::hashValue((string)($data['fingerprint_hash'] ?? '')),
            'policy_id' => self::identifier((string)($data['policy_id'] ?? ''), 160),
            'module_id' => self::identifier((string)($data['module_id'] ?? ''), 160),
            'scenario_id' => self::identifier((string)($data['scenario_id'] ?? ''), 160),
            'suite_id' => self::identifier((string)($data['suite_id'] ?? ''), 160),
            'test_id' => self::safePath((string)($data['test_id'] ?? '')),
            'metric' => self::identifier((string)($data['metric'] ?? ''), 120),
            'plan_flag' => self::identifier((string)($data['plan_flag'] ?? ''), 120),
            'severity' => $severity,
            'confidence' => $confidence,
            'stability_type' => in_array((string)($data['stability_type'] ?? ''), ['temporal', 'structural', 'none'], true)
                ? (string)$data['stability_type']
                : 'none',
            'stability_status' => (string)($data['stability_status'] ?? 'not_required'),
            'decision_requested' => 'observe',
            'decision_effective' => 'observe',
            'message' => MysqlQueryGateArtifactWriter::sanitizeText((string)($data['message'] ?? $definition['description']), 500),
            'evidence' => MysqlQueryGateArtifactWriter::sanitizeRecursive(is_array($data['evidence'] ?? null) ? $data['evidence'] : []),
            'location' => $location,
            'suppressed' => false,
            'suppression' => null,
        ];
    }

    /** @return array{rule_id:string,description:string,severity:string,confidence:string} */
    public static function definition(string $category): array
    {
        return self::CATEGORIES[$category] ?? [
            'rule_id' => 'TKSQL9999',
            'description' => 'SQL gate finding',
            'severity' => 'warning',
            'confidence' => 'medium',
        ];
    }

    /** @return array<string,array{rule_id:string,description:string,severity:string,confidence:string}> */
    public static function categoryDefinitions(): array
    {
        return self::CATEGORIES;
    }

    public static function isSuppressible(array $finding): bool
    {
        return !in_array((string)($finding['category'] ?? ''), self::NON_SUPPRESSIBLE, true);
    }

    /** @param array<string,mixed> $location @return array<string,mixed> */
    private static function normalizeLocation(array $location): array
    {
        $path = self::safePath((string)($location['path'] ?? ''));
        if ($path === '') {
            return [];
        }
        $line = is_numeric($location['line'] ?? null) ? (int)$location['line'] : 0;
        return [
            'path' => $path,
            'line' => $line > 0 && $line <= 10000000 ? $line : null,
        ];
    }

    public static function safePath(string $value): string
    {
        $value = trim(str_replace('\\', '/', $value));
        if ($value === '') {
            return '';
        }
        $line = '';
        if (preg_match('/^(.*):(\d+)$/', $value, $match) === 1 && preg_match('/^[A-Za-z]:\//', $value) !== 1) {
            $value = (string)$match[1];
            $line = ':' . (string)$match[2];
        }
        $path = MysqlQueryGateArtifactWriter::safeRelativePath($value);
        return $path === '' ? '' : substr($path, 0, 300) . $line;
    }

    private static function identifier(string $value, int $max): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._:@\/-]+/', '_', $value) ?? '';
        return substr(trim($value, '_'), 0, $max);
    }

    private static function severity(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === 'warn') {
            $value = 'warning';
        }
        return in_array($value, ['info', 'warning', 'error'], true) ? $value : 'warning';
    }

    private static function confidence(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['low', 'medium', 'high'], true) ? $value : 'medium';
    }

    private static function hashValue(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : '';
    }
}
