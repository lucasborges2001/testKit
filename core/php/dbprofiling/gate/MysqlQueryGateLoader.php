<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Gate;

final class MysqlQueryGateLoader
{
    private const MAX_FILE_BYTES = 2097152;
    private const MAX_RULES = 500;
    private const MAX_SELECTOR_VALUES = 100;

    private const ROOT_KEYS = ['schema_version', 'gate'];
    private const GATE_KEYS = ['id', 'description', 'mode', 'defaults', 'stability', 'rules', 'outputs', 'baseline_approval'];
    private const DEFAULT_KEYS = ['minimum_severity', 'on_incompatible_context', 'on_insufficient_data', 'on_invalid_embedded_evidence'];
    private const STABILITY_KEYS = ['temporal', 'structural'];
    private const STABILITY_SPEC_KEYS = ['required_runs', 'required_confirmations', 'minimum_sample_count', 'maximum_age_hours'];
    private const RULE_KEYS = ['id', 'description', 'selectors', 'decision', 'allow_structural_only', 'stability_type'];
    private const SELECTOR_KEYS = [
        'category', 'subcategory', 'source', 'severity', 'confidence', 'module_id', 'scenario_id',
        'suite_id', 'test_id', 'query_identity', 'policy_id', 'metric', 'plan_flag', 'source_finding_id',
    ];
    private const OUTPUT_KEYS = ['json', 'junit', 'sarif', 'summary', 'github_annotations', 'github_step_summary'];
    private const APPROVAL_KEYS = [
        'enabled', 'minimum_policy_severity', 'minimum_sample_count', 'minimum_successful_runs',
        'require_full_compatibility', 'require_source_commit', 'require_dataset_identity', 'require_environment_identity',
    ];

    /** @return array<string,mixed> */
    public static function load(string $path): array
    {
        $payload = MysqlQueryGateArtifactWriter::loadJson($path, self::MAX_FILE_BYTES);
        $validated = self::validate($payload);
        $validated['_file'] = MysqlQueryGateFinding::safePath($path);
        $validated['_file_hash'] = MysqlQueryGateArtifactWriter::fileHash($path);
        return $validated;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public static function validate(array $payload): array
    {
        self::assertKnownKeys($payload, self::ROOT_KEYS, '$');
        self::same(MysqlQueryGateConfig::SCHEMA_VERSION, $payload['schema_version'] ?? null, '$.schema_version', 'unsupported_gate_schema');
        $gate = self::object($payload['gate'] ?? null, '$.gate');
        self::assertKnownKeys($gate, self::GATE_KEYS, '$.gate');

        $id = self::id($gate['id'] ?? null, '$.gate.id');
        $description = self::string($gate['description'] ?? '', '$.gate.description', 500, true);
        $mode = strtolower(self::string($gate['mode'] ?? MysqlQueryGateConfig::MODE_OFF, '$.gate.mode', 20));
        if (!in_array($mode, MysqlQueryGateConfig::modes(), true)) {
            throw self::invalid('Unsupported gate mode.', '$.gate.mode', 'invalid_gate_enum');
        }

        $defaultsInput = isset($gate['defaults']) ? self::object($gate['defaults'], '$.gate.defaults') : [];
        self::assertKnownKeys($defaultsInput, self::DEFAULT_KEYS, '$.gate.defaults');
        $defaults = [
            'minimum_severity' => self::enum($defaultsInput['minimum_severity'] ?? 'warning', ['info', 'warning', 'error'], '$.gate.defaults.minimum_severity'),
            'on_incompatible_context' => self::enum($defaultsInput['on_incompatible_context'] ?? 'report', ['report', 'ignore', 'error'], '$.gate.defaults.on_incompatible_context'),
            'on_insufficient_data' => self::enum($defaultsInput['on_insufficient_data'] ?? 'report', ['report', 'ignore', 'error'], '$.gate.defaults.on_insufficient_data'),
            'on_invalid_embedded_evidence' => self::enum($defaultsInput['on_invalid_embedded_evidence'] ?? 'error', ['report', 'error'], '$.gate.defaults.on_invalid_embedded_evidence'),
        ];

        $stabilityInput = isset($gate['stability']) ? self::object($gate['stability'], '$.gate.stability') : [];
        self::assertKnownKeys($stabilityInput, self::STABILITY_KEYS, '$.gate.stability');
        $stability = [
            'temporal' => self::stabilitySpec(
                isset($stabilityInput['temporal']) ? self::object($stabilityInput['temporal'], '$.gate.stability.temporal') : [],
                '$.gate.stability.temporal',
                ['required_runs' => 3, 'required_confirmations' => 2, 'minimum_sample_count' => 20, 'maximum_age_hours' => 168]
            ),
            'structural' => self::stabilitySpec(
                isset($stabilityInput['structural']) ? self::object($stabilityInput['structural'], '$.gate.stability.structural') : [],
                '$.gate.stability.structural',
                ['required_runs' => 1, 'required_confirmations' => 1, 'minimum_sample_count' => 0, 'maximum_age_hours' => 168]
            ),
        ];

        $rulesInput = $gate['rules'] ?? [];
        if (!is_array($rulesInput) || !self::isList($rulesInput)) {
            throw self::invalid('Gate rules must be a list.', '$.gate.rules', 'invalid_gate_rules');
        }
        if (count($rulesInput) > self::MAX_RULES) {
            throw self::invalid('Gate rules exceed the maximum.', '$.gate.rules', 'too_many_gate_rules');
        }
        $rules = [];
        $ids = [];
        $signatures = [];
        foreach ($rulesInput as $index => $ruleInput) {
            $path = '$.gate.rules[' . $index . ']';
            $rule = self::object($ruleInput, $path);
            self::assertKnownKeys($rule, self::RULE_KEYS, $path);
            $ruleId = self::id($rule['id'] ?? null, $path . '.id');
            if (isset($ids[$ruleId])) {
                throw self::invalid('Duplicate gate rule id.', $path . '.id', 'duplicate_gate_rule_id');
            }
            $ids[$ruleId] = true;
            $selectors = self::selectors($rule['selectors'] ?? [], $path . '.selectors', true);
            $decision = self::enum($rule['decision'] ?? 'observe', ['observe', 'warn', 'block'], $path . '.decision');
            $stabilityType = self::enum($rule['stability_type'] ?? 'auto', ['auto', 'temporal', 'structural', 'none'], $path . '.stability_type');
            $normalized = [
                'id' => $ruleId,
                'description' => self::string($rule['description'] ?? '', $path . '.description', 500, true),
                'selectors' => $selectors,
                'decision' => $decision,
                'allow_structural_only' => self::boolean($rule['allow_structural_only'] ?? false, $path . '.allow_structural_only'),
                'stability_type' => $stabilityType,
                'precedence_rank' => self::precedenceRank($selectors),
            ];
            $signature = self::selectorSignature($selectors);
            if (isset($signatures[$signature]) && $signatures[$signature]['decision'] !== $decision) {
                throw self::invalid(
                    'Rules with identical selectors have incompatible decisions.',
                    $path . '.decision',
                    'gate_rule_precedence_conflict'
                );
            }
            $signatures[$signature] = ['decision' => $decision, 'id' => $ruleId];
            $rules[] = $normalized;
        }
        usort($rules, static fn(array $a, array $b): int => strcmp((string)$a['id'], (string)$b['id']));

        $outputsInput = isset($gate['outputs']) ? self::object($gate['outputs'], '$.gate.outputs') : [];
        self::assertKnownKeys($outputsInput, self::OUTPUT_KEYS, '$.gate.outputs');
        $outputs = [];
        foreach (self::OUTPUT_KEYS as $key) {
            $outputs[$key] = self::boolean($outputsInput[$key] ?? ($key !== 'github_annotations' && $key !== 'github_step_summary'), '$.gate.outputs.' . $key);
        }

        $approvalInput = isset($gate['baseline_approval']) ? self::object($gate['baseline_approval'], '$.gate.baseline_approval') : [];
        self::assertKnownKeys($approvalInput, self::APPROVAL_KEYS, '$.gate.baseline_approval');
        $approval = [
            'enabled' => self::boolean($approvalInput['enabled'] ?? true, '$.gate.baseline_approval.enabled'),
            'minimum_policy_severity' => self::enum($approvalInput['minimum_policy_severity'] ?? 'error', ['info', 'warning', 'error'], '$.gate.baseline_approval.minimum_policy_severity'),
            'minimum_sample_count' => self::int($approvalInput['minimum_sample_count'] ?? 20, 0, 100000000, '$.gate.baseline_approval.minimum_sample_count'),
            'minimum_successful_runs' => self::int($approvalInput['minimum_successful_runs'] ?? 1, 1, 20, '$.gate.baseline_approval.minimum_successful_runs'),
            'require_full_compatibility' => self::boolean($approvalInput['require_full_compatibility'] ?? true, '$.gate.baseline_approval.require_full_compatibility'),
            'require_source_commit' => self::boolean($approvalInput['require_source_commit'] ?? true, '$.gate.baseline_approval.require_source_commit'),
            'require_dataset_identity' => self::boolean($approvalInput['require_dataset_identity'] ?? true, '$.gate.baseline_approval.require_dataset_identity'),
            'require_environment_identity' => self::boolean($approvalInput['require_environment_identity'] ?? true, '$.gate.baseline_approval.require_environment_identity'),
        ];

        return [
            'schema_version' => MysqlQueryGateConfig::SCHEMA_VERSION,
            'gate' => [
                'id' => $id,
                'description' => $description,
                'mode' => $mode,
                'defaults' => $defaults,
                'stability' => $stability,
                'rules' => $rules,
                'outputs' => $outputs,
                'baseline_approval' => $approval,
            ],
        ];
    }

    /** @param array<string,mixed> $input @param array<string,int> $defaults @return array<string,int> */
    private static function stabilitySpec(array $input, string $path, array $defaults): array
    {
        self::assertKnownKeys($input, self::STABILITY_SPEC_KEYS, $path);
        $runs = self::int($input['required_runs'] ?? $defaults['required_runs'], 1, 20, $path . '.required_runs');
        $confirmations = self::int($input['required_confirmations'] ?? $defaults['required_confirmations'], 1, 20, $path . '.required_confirmations');
        if ($confirmations > $runs) {
            throw self::invalid('required_confirmations must be <= required_runs.', $path . '.required_confirmations', 'invalid_stability_confirmations');
        }
        return [
            'required_runs' => $runs,
            'required_confirmations' => $confirmations,
            'minimum_sample_count' => self::int($input['minimum_sample_count'] ?? $defaults['minimum_sample_count'], 0, 100000000, $path . '.minimum_sample_count'),
            'maximum_age_hours' => self::int($input['maximum_age_hours'] ?? $defaults['maximum_age_hours'], 1, 720, $path . '.maximum_age_hours'),
        ];
    }

    /** @return array<string,array<int,string>> */
    public static function selectors(mixed $input, string $path, bool $allowEmpty): array
    {
        $selectors = self::object($input, $path);
        self::assertKnownKeys($selectors, self::SELECTOR_KEYS, $path);
        $normalized = [];
        foreach ($selectors as $key => $value) {
            $values = is_array($value) ? $value : [$value];
            if (!self::isList($values) || count($values) > self::MAX_SELECTOR_VALUES) {
                throw self::invalid('Selector values must be a bounded list.', $path . '.' . $key, 'invalid_gate_selector');
            }
            $items = [];
            foreach ($values as $index => $item) {
                $items[] = self::string($item, $path . '.' . $key . '[' . $index . ']', 300);
            }
            $items = array_values(array_unique(array_filter($items, static fn(string $item): bool => $item !== '')));
            if ($items === []) {
                throw self::invalid('Selector may not be empty.', $path . '.' . $key, 'empty_gate_selector');
            }
            sort($items, SORT_STRING);
            $normalized[(string)$key] = $items;
        }
        ksort($normalized, SORT_STRING);
        if (!$allowEmpty && $normalized === []) {
            throw self::invalid('At least one selector is required.', $path, 'empty_gate_selectors');
        }
        return $normalized;
    }

    /** @param array<string,array<int,string>> $selectors */
    public static function precedenceRank(array $selectors): int
    {
        $weights = [
            'query_identity' => 1000,
            'policy_id' => 900,
            'test_id' => 80,
            'suite_id' => 70,
            'scenario_id' => 60,
            'module_id' => 50,
            'metric' => 40,
            'plan_flag' => 40,
            'subcategory' => 30,
            'category' => 20,
            'severity' => 10,
            'confidence' => 5,
            'source' => 3,
            'source_finding_id' => 2,
        ];
        $rank = 0;
        foreach (array_keys($selectors) as $key) {
            $rank += $weights[$key] ?? 1;
        }
        return $rank;
    }

    /** @param array<string,array<int,string>> $selectors */
    private static function selectorSignature(array $selectors): string
    {
        return hash('sha256', (string)json_encode($selectors, JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string,mixed> $object @param array<int,string> $allowed */
    private static function assertKnownKeys(array $object, array $allowed, string $path): void
    {
        foreach (array_keys($object) as $key) {
            if (!in_array((string)$key, $allowed, true)) {
                throw self::invalid('Unknown key.', $path . '.' . $key, 'unknown_gate_key');
            }
        }
    }

    /** @return array<string,mixed> */
    private static function object(mixed $value, string $path): array
    {
        if (!is_array($value) || self::isList($value)) {
            throw self::invalid('Expected JSON object.', $path, 'invalid_gate_object');
        }
        return $value;
    }

    private static function string(mixed $value, string $path, int $max, bool $allowEmpty = false): string
    {
        if (!is_string($value)) {
            throw self::invalid('Expected string.', $path, 'invalid_gate_string');
        }
        $value = trim($value);
        if (!$allowEmpty && $value === '') {
            throw self::invalid('String may not be empty.', $path, 'empty_gate_string');
        }
        if (strlen($value) > $max) {
            throw self::invalid('String exceeds maximum length.', $path, 'gate_string_too_long');
        }
        return $value;
    }

    private static function id(mixed $value, string $path): string
    {
        $value = self::string($value, $path, 160);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $value) !== 1) {
            throw self::invalid('Invalid identifier.', $path, 'invalid_gate_identifier');
        }
        return strtolower($value);
    }

    private static function enum(mixed $value, array $allowed, string $path): string
    {
        $value = strtolower(self::string($value, $path, 80));
        if (!in_array($value, $allowed, true)) {
            throw self::invalid('Unsupported enum value.', $path, 'invalid_gate_enum');
        }
        return $value;
    }

    private static function boolean(mixed $value, string $path): bool
    {
        if (!is_bool($value)) {
            throw self::invalid('Expected boolean.', $path, 'invalid_gate_boolean');
        }
        return $value;
    }

    private static function int(mixed $value, int $min, int $max, string $path): int
    {
        if (!is_int($value)) {
            throw self::invalid('Expected integer.', $path, 'invalid_gate_integer');
        }
        if ($value < $min || $value > $max) {
            throw self::invalid('Integer is outside the allowed range.', $path, 'gate_integer_out_of_range');
        }
        return $value;
    }

    private static function same(mixed $expected, mixed $actual, string $path, string $code): void
    {
        if ($actual !== $expected) {
            throw self::invalid('Unsupported schema version.', $path, $code);
        }
    }

    private static function invalid(string $message, string $path, string $code): MysqlQueryGateException
    {
        return new MysqlQueryGateException($message, $path, $code, MysqlQueryGateConfig::EXIT_INVALID_CONTRACT);
    }

    /** @param array<mixed> $value */
    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}
