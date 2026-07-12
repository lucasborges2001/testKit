#!/usr/bin/env php
<?php
declare(strict_types=1);

final class SqlObsHostException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $jsonPath = '$',
        public readonly string $errorCodeId = 'invalid_contract',
        public readonly int $exitStatus = 3
    ) {
        parent::__construct($message);
    }
}

final class SqlObsHostConfig
{
    public const HOST_SCHEMA = 'testkit-sql-observability-host-v1';
    public const DATASET_SCHEMA = 'testkit-sql-observability-dataset-v1';
    private const MODES = ['off', 'report', 'warn', 'fail'];
    private const BASELINE_STATUSES = ['ready', 'pending_mysql_bootstrap'];
    private const MAX_FILE_BYTES = 2_097_152;
    private const HOST_KEYS = ['schema_version', 'project', 'defaults', 'events', 'scenarios'];
    private const PROJECT_KEYS = ['repository'];
    private const DEFAULT_KEYS = [
        'repetitions', 'required_confirmations', 'timeout_minutes',
        'artifact_retention_days', 'environment_id', 'engine', 'engine_version',
    ];
    private const EVENT_KEYS = ['pull_request', 'push_main', 'schedule', 'workflow_dispatch'];
    private const EVENT_VALUE_KEYS = ['gate_mode'];
    private const SCENARIO_KEYS = [
        'id', 'enabled', 'label', 'module_id', 'scenario_id', 'test_file', 'test_match',
        'dataset', 'policy_file', 'baseline_file', 'baseline_status', 'gate_file',
        'allowlist_file', 'repetitions', 'required_confirmations', 'timeout_minutes',
    ];
    private const DATASET_KEYS = [
        'schema_version', 'dataset_id', 'dataset_version', 'description', 'engine',
        'engine_version', 'schema_file', 'seed_file', 'schema_sha256', 'seed_sha256',
        'dataset_hash', 'expected_tables', 'safety',
    ];
    private const SAFETY_KEYS = ['database_name_prefix', 'allowed_hosts', 'requires_app_env'];

    public static function repoRoot(): string
    {
        $envRoot = getenv('TK_REPO_ROOT') ?: getenv('TESTKIT_PROJECT_ROOT');
        $root = is_string($envRoot) && trim($envRoot) !== '' ? realpath(trim($envRoot)) : realpath((string)getcwd());
        if ($root === false) {
            throw new SqlObsHostException('Unable to resolve repository root.', '$', 'repo_root_unresolved', 2);
        }
        return self::slash($root);
    }

    /** @return array<string,mixed> */
    public static function loadHost(string $path): array
    {
        $root = self::repoRoot();
        $absolute = self::resolveExisting($root, $path, '$.config');
        $input = self::loadJson($absolute, self::MAX_FILE_BYTES, '$');
        self::known($input, self::HOST_KEYS, '$');
        self::expect($input['schema_version'] ?? null, self::HOST_SCHEMA, '$.schema_version');

        $project = self::object($input['project'] ?? null, '$.project');
        self::known($project, self::PROJECT_KEYS, '$.project');
        $repository = self::boundedId($project['repository'] ?? null, '$.project.repository', 240, '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#');

        $defaults = self::object($input['defaults'] ?? null, '$.defaults');
        self::known($defaults, self::DEFAULT_KEYS, '$.defaults');
        $normalizedDefaults = [
            'repetitions' => self::integer($defaults['repetitions'] ?? null, '$.defaults.repetitions', 1, 5),
            'required_confirmations' => self::integer($defaults['required_confirmations'] ?? null, '$.defaults.required_confirmations', 1, 5),
            'timeout_minutes' => self::integer($defaults['timeout_minutes'] ?? null, '$.defaults.timeout_minutes', 1, 60),
            'artifact_retention_days' => self::integer($defaults['artifact_retention_days'] ?? null, '$.defaults.artifact_retention_days', 1, 90),
            'environment_id' => self::identifier($defaults['environment_id'] ?? null, '$.defaults.environment_id', 160),
            'engine' => self::identifier($defaults['engine'] ?? null, '$.defaults.engine', 40),
            'engine_version' => self::engineVersion($defaults['engine_version'] ?? null, '$.defaults.engine_version'),
        ];
        if ($normalizedDefaults['required_confirmations'] > $normalizedDefaults['repetitions']) {
            throw new SqlObsHostException('required_confirmations cannot exceed repetitions.', '$.defaults.required_confirmations');
        }
        if ($normalizedDefaults['engine'] !== 'mysql') {
            throw new SqlObsHostException('Only mysql is supported.', '$.defaults.engine');
        }

        $events = self::object($input['events'] ?? null, '$.events');
        self::known($events, self::EVENT_KEYS, '$.events');
        $normalizedEvents = [];
        foreach (self::EVENT_KEYS as $event) {
            $row = self::object($events[$event] ?? null, '$.events.' . $event);
            self::known($row, self::EVENT_VALUE_KEYS, '$.events.' . $event);
            $normalizedEvents[$event] = ['gate_mode' => self::mode($row['gate_mode'] ?? null, '$.events.' . $event . '.gate_mode')];
        }

        $scenariosInput = $input['scenarios'] ?? null;
        if (!is_array($scenariosInput) || !array_is_list($scenariosInput) || count($scenariosInput) < 1 || count($scenariosInput) > 20) {
            throw new SqlObsHostException('scenarios must be a list with 1..20 entries.', '$.scenarios');
        }
        $seen = [];
        $scenarios = [];
        foreach ($scenariosInput as $index => $raw) {
            $pathBase = '$.scenarios[' . $index . ']';
            $row = self::object($raw, $pathBase);
            self::known($row, self::SCENARIO_KEYS, $pathBase);
            $id = self::identifier($row['id'] ?? null, $pathBase . '.id', 80);
            if (isset($seen[$id])) {
                throw new SqlObsHostException('Duplicate scenario id.', $pathBase . '.id');
            }
            $seen[$id] = true;
            $repetitions = self::integer($row['repetitions'] ?? $normalizedDefaults['repetitions'], $pathBase . '.repetitions', 1, 5);
            $confirmations = self::integer($row['required_confirmations'] ?? $normalizedDefaults['required_confirmations'], $pathBase . '.required_confirmations', 1, 5);
            if ($confirmations > $repetitions) {
                throw new SqlObsHostException('required_confirmations cannot exceed repetitions.', $pathBase . '.required_confirmations');
            }
            $baselineStatus = self::enum($row['baseline_status'] ?? 'ready', self::BASELINE_STATUSES, $pathBase . '.baseline_status');
            $datasetRel = self::relativePath($row['dataset'] ?? null, $pathBase . '.dataset');
            $policyRel = self::relativePath($row['policy_file'] ?? null, $pathBase . '.policy_file');
            $baselineRel = self::relativePath($row['baseline_file'] ?? null, $pathBase . '.baseline_file');
            $gateRel = self::relativePath($row['gate_file'] ?? null, $pathBase . '.gate_file');
            $allowRel = self::relativePath($row['allowlist_file'] ?? null, $pathBase . '.allowlist_file');
            $testFile = self::relativePath($row['test_file'] ?? $row['test_match'] ?? null, $pathBase . '.test_file');

            self::resolveExisting($root, $datasetRel, $pathBase . '.dataset');
            self::resolveExisting($root, $policyRel, $pathBase . '.policy_file');
            self::resolveExisting($root, $gateRel, $pathBase . '.gate_file');
            self::resolveExisting($root, $allowRel, $pathBase . '.allowlist_file');
            self::resolveExisting($root, $testFile, $pathBase . '.test_file');
            $baselineAbsolute = self::resolveCandidate($root, $baselineRel, $pathBase . '.baseline_file');
            if ($baselineStatus === 'ready' && !is_file($baselineAbsolute)) {
                throw new SqlObsHostException('A ready baseline file must exist.', $pathBase . '.baseline_file');
            }
            if ($baselineStatus === 'pending_mysql_bootstrap' && is_file($baselineAbsolute)) {
                throw new SqlObsHostException('baseline_status must be ready when the baseline file exists.', $pathBase . '.baseline_status');
            }

            $dataset = self::loadDataset($datasetRel);
            self::assertReferencedSchema($root, $policyRel, 'mysql-query-policy-v1', $pathBase . '.policy_file');
            self::assertReferencedSchema($root, $gateRel, 'mysql-query-gate-v1', $pathBase . '.gate_file');
            self::assertReferencedSchema($root, $allowRel, 'mysql-query-gate-allowlist-v1', $pathBase . '.allowlist_file');

            $scenarios[] = [
                'id' => $id,
                'enabled' => self::boolean($row['enabled'] ?? null, $pathBase . '.enabled'),
                'label' => self::string($row['label'] ?? null, $pathBase . '.label', 160),
                'module_id' => self::identifier($row['module_id'] ?? null, $pathBase . '.module_id', 160),
                'scenario_id' => self::identifier($row['scenario_id'] ?? null, $pathBase . '.scenario_id', 160),
                'test_file' => $testFile,
                'test_match' => $testFile,
                'dataset' => $datasetRel,
                'dataset_contract' => $dataset,
                'policy_file' => $policyRel,
                'baseline_file' => $baselineRel,
                'baseline_status' => $baselineStatus,
                'gate_file' => $gateRel,
                'allowlist_file' => $allowRel,
                'repetitions' => $repetitions,
                'required_confirmations' => $confirmations,
                'timeout_minutes' => self::integer($row['timeout_minutes'] ?? $normalizedDefaults['timeout_minutes'], $pathBase . '.timeout_minutes', 1, 60),
            ];
        }

        return [
            'schema_version' => self::HOST_SCHEMA,
            'config_file' => self::relativeTo($root, $absolute),
            'project' => ['repository' => $repository],
            'defaults' => $normalizedDefaults,
            'events' => $normalizedEvents,
            'scenarios' => $scenarios,
            'status' => self::hostStatus($scenarios),
        ];
    }

    /** @return array<string,mixed> */
    public static function loadDataset(string $manifestPath): array
    {
        $root = self::repoRoot();
        $absolute = self::resolveExisting($root, $manifestPath, '$.dataset');
        $input = self::loadJson($absolute, self::MAX_FILE_BYTES, '$');
        self::known($input, self::DATASET_KEYS, '$');
        self::expect($input['schema_version'] ?? null, self::DATASET_SCHEMA, '$.schema_version');
        $dir = dirname($absolute);
        $datasetId = self::identifier($input['dataset_id'] ?? null, '$.dataset_id', 80);
        $datasetVersion = self::identifier($input['dataset_version'] ?? null, '$.dataset_version', 80);
        $schemaRel = self::filename($input['schema_file'] ?? null, '$.schema_file');
        $seedRel = self::filename($input['seed_file'] ?? null, '$.seed_file');
        $schema = self::resolveExisting($dir, $schemaRel, '$.schema_file');
        $seed = self::resolveExisting($dir, $seedRel, '$.seed_file');
        $schemaHash = self::sha($schema);
        $seedHash = self::sha($seed);
        self::expectHash($input['schema_sha256'] ?? null, $schemaHash, '$.schema_sha256');
        self::expectHash($input['seed_sha256'] ?? null, $seedHash, '$.seed_sha256');

        $tables = self::stringList($input['expected_tables'] ?? null, '$.expected_tables', 1, 50, 80);
        $safety = self::object($input['safety'] ?? null, '$.safety');
        self::known($safety, self::SAFETY_KEYS, '$.safety');
        $prefix = self::identifier($safety['database_name_prefix'] ?? null, '$.safety.database_name_prefix', 40);
        if (in_array($prefix, ['mysql', 'information_schema', 'performance_schema', 'sys'], true)) {
            throw new SqlObsHostException('Database prefix cannot be reserved.', '$.safety.database_name_prefix');
        }
        $hosts = self::stringList($safety['allowed_hosts'] ?? null, '$.safety.allowed_hosts', 1, 20, 100);
        foreach ($hosts as $i => $host) {
            if (preg_match('/^[A-Za-z0-9._-]+$/', $host) !== 1) {
                throw new SqlObsHostException('Invalid allowed host.', '$.safety.allowed_hosts[' . $i . ']');
            }
        }
        self::expect($safety['requires_app_env'] ?? null, 'test', '$.safety.requires_app_env');

        $engine = self::identifier($input['engine'] ?? null, '$.engine', 40);
        $engineVersion = self::engineVersion($input['engine_version'] ?? null, '$.engine_version');
        if ($engine !== 'mysql') {
            throw new SqlObsHostException('Only mysql datasets are supported.', '$.engine');
        }

        $materialInput = $input;
        unset($materialInput['dataset_hash']);
        $material = self::canonicalJson($materialInput) . "\n" . $schemaHash . "\n" . $seedHash . "\n" . $engine . ':' . self::majorMinor($engineVersion);
        $datasetHash = hash('sha256', $material);
        self::expectHash($input['dataset_hash'] ?? null, $datasetHash, '$.dataset_hash');
        self::assertSqlSafety($schema, $seed);

        return [
            'schema_version' => self::DATASET_SCHEMA,
            'manifest_file' => self::relativeTo($root, $absolute),
            'dataset_id' => $datasetId,
            'dataset_version' => $datasetVersion,
            'description' => self::string($input['description'] ?? null, '$.description', 500),
            'engine' => $engine,
            'engine_version' => $engineVersion,
            'schema_file' => self::relativeTo($root, $schema),
            'seed_file' => self::relativeTo($root, $seed),
            'schema_sha256' => $schemaHash,
            'seed_sha256' => $seedHash,
            'dataset_hash' => $datasetHash,
            'expected_tables' => $tables,
            'safety' => [
                'database_name_prefix' => $prefix,
                'allowed_hosts' => $hosts,
                'requires_app_env' => 'test',
            ],
        ];
    }

    /** @param array<int,array<string,mixed>> $scenarios */
    private static function hostStatus(array $scenarios): array
    {
        $pending = array_values(array_map(
            static fn(array $s): string => (string)$s['id'],
            array_filter($scenarios, static fn(array $s): bool => $s['baseline_status'] !== 'ready')
        ));
        return [
            'valid' => true,
            'complete' => $pending === [],
            'baseline_pending_scenarios' => $pending,
            'state' => $pending === [] ? 'ready' : 'partial_baseline_pending',
        ];
    }

    private static function assertSqlSafety(string $schema, string $seed): void
    {
        $schemaText = (string)file_get_contents($schema);
        $seedText = (string)file_get_contents($seed);
        if (preg_match('/\b(DROP|TRUNCATE|DELETE|ALTER|RENAME|GRANT|REVOKE|LOAD\s+DATA|OUTFILE|DUMPFILE)\b/i', $schemaText) === 1) {
            throw new SqlObsHostException('Dataset schema contains a forbidden destructive statement.', '$.schema_file');
        }
        if (preg_match('/\b(DROP|TRUNCATE|DELETE|ALTER|RENAME|GRANT|REVOKE|LOAD\s+DATA|OUTFILE|DUMPFILE)\b/i', $seedText) === 1) {
            throw new SqlObsHostException('Dataset seed contains a forbidden destructive statement.', '$.seed_file');
        }
        $sensitive = '/(?:BEGIN\s+(?:RSA|OPENSSH)\s+PRIVATE\s+KEY|AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9_]{20,}|mysql:\/\/[^@\s]+@)/i';
        if (preg_match($sensitive, $schemaText . "\n" . $seedText) === 1) {
            throw new SqlObsHostException('Dataset appears to contain a secret.', '$.dataset');
        }
    }

    private static function assertReferencedSchema(string $root, string $path, string $schema, string $jsonPath): void
    {
        $absolute = self::resolveExisting($root, $path, $jsonPath);
        $payload = self::loadJson($absolute, self::MAX_FILE_BYTES, $jsonPath);
        self::expect($payload['schema_version'] ?? null, $schema, $jsonPath . '.schema_version');
    }

    /** @return array<string,mixed> */
    private static function loadJson(string $path, int $maxBytes, string $jsonPath): array
    {
        $size = filesize($path);
        if ($size === false || $size < 2 || $size > $maxBytes) {
            throw new SqlObsHostException('JSON file size is invalid.', $jsonPath, 'invalid_json_size');
        }
        try {
            $decoded = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SqlObsHostException('Invalid JSON: ' . $e->getMessage(), $jsonPath, 'invalid_json');
        }
        return self::object($decoded, $jsonPath);
    }

    /** @param array<string,mixed> $object @param list<string> $allowed */
    private static function known(array $object, array $allowed, string $path): void
    {
        foreach (array_keys($object) as $key) {
            if (!in_array((string)$key, $allowed, true)) {
                throw new SqlObsHostException('Unknown key.', $path . '.' . $key, 'unknown_key');
            }
        }
    }

    /** @return array<string,mixed> */
    private static function object(mixed $value, string $path): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new SqlObsHostException('Expected object.', $path, 'expected_object');
        }
        return $value;
    }

    private static function expect(mixed $actual, string $expected, string $path): void
    {
        if (!is_string($actual) || $actual !== $expected) {
            throw new SqlObsHostException('Expected ' . $expected . '.', $path, 'unexpected_value');
        }
    }

    private static function boolean(mixed $value, string $path): bool
    {
        if (!is_bool($value)) {
            throw new SqlObsHostException('Expected boolean.', $path, 'expected_boolean');
        }
        return $value;
    }

    private static function integer(mixed $value, string $path, int $min, int $max): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new SqlObsHostException("Expected integer {$min}..{$max}.", $path, 'invalid_integer');
        }
        return $value;
    }

    private static function string(mixed $value, string $path, int $max): string
    {
        if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1) {
            throw new SqlObsHostException('Invalid string.', $path, 'invalid_string');
        }
        return trim($value);
    }

    private static function identifier(mixed $value, string $path, int $max): string
    {
        return self::boundedId($value, $path, $max, '/^[a-z0-9][a-z0-9._:-]*$/');
    }

    private static function boundedId(mixed $value, string $path, int $max, string $pattern): string
    {
        $string = self::string($value, $path, $max);
        if (preg_match($pattern, $string) !== 1) {
            throw new SqlObsHostException('Invalid identifier.', $path, 'invalid_identifier');
        }
        return $string;
    }

    private static function engineVersion(mixed $value, string $path): string
    {
        $version = self::string($value, $path, 40);
        if (preg_match('/^\d+\.\d+(?:\.\d+)?(?:[-+][A-Za-z0-9._-]+)?$/', $version) !== 1) {
            throw new SqlObsHostException('Invalid engine version.', $path, 'invalid_engine_version');
        }
        return $version;
    }

    private static function mode(mixed $value, string $path): string
    {
        return self::enum($value, self::MODES, $path);
    }

    /** @param list<string> $allowed */
    private static function enum(mixed $value, array $allowed, string $path): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new SqlObsHostException('Unsupported value.', $path, 'unsupported_value');
        }
        return $value;
    }

    private static function relativePath(mixed $value, string $path): string
    {
        $relative = self::string($value, $path, 300);
        $relative = self::slash($relative);
        if (str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:\//', $relative) === 1 || str_contains('/' . $relative . '/', '/../') || str_contains($relative, "\0")) {
            throw new SqlObsHostException('Path must be relative and cannot traverse.', $path, 'invalid_relative_path');
        }
        return ltrim($relative, './');
    }

    private static function filename(mixed $value, string $path): string
    {
        $filename = self::relativePath($value, $path);
        if (str_contains($filename, '/')) {
            throw new SqlObsHostException('Expected a file name in the dataset directory.', $path, 'invalid_dataset_filename');
        }
        return $filename;
    }

    private static function resolveExisting(string $base, string $path, string $jsonPath): string
    {
        $candidate = self::resolveCandidate($base, $path, $jsonPath);
        $real = realpath($candidate);
        if ($real === false || !is_file($real)) {
            throw new SqlObsHostException('Referenced file does not exist.', $jsonPath, 'referenced_file_missing', 2);
        }
        $baseReal = realpath($base);
        if ($baseReal === false || !self::isUnder(self::slash($baseReal), self::slash($real))) {
            throw new SqlObsHostException('Referenced file escapes allowed root.', $jsonPath, 'path_escape');
        }
        if (is_link($candidate)) {
            throw new SqlObsHostException('Symlink references are not allowed.', $jsonPath, 'symlink_not_allowed');
        }
        return self::slash($real);
    }

    private static function resolveCandidate(string $base, string $path, string $jsonPath): string
    {
        $baseReal = realpath($base);
        if ($baseReal === false) {
            throw new SqlObsHostException('Unable to resolve path root.', $jsonPath, 'path_root_unresolved', 2);
        }
        $relative = self::relativePath($path, $jsonPath);
        $candidate = self::slash($baseReal . '/' . $relative);
        if (!self::isUnder(self::slash($baseReal), $candidate)) {
            throw new SqlObsHostException('Path escapes allowed root.', $jsonPath, 'path_escape');
        }
        return $candidate;
    }

    private static function isUnder(string $root, string $path): bool
    {
        $root = rtrim($root, '/');
        return $path === $root || str_starts_with($path, $root . '/');
    }

    private static function relativeTo(string $root, string $path): string
    {
        $root = rtrim(self::slash($root), '/');
        $path = self::slash($path);
        return self::isUnder($root, $path) ? ltrim(substr($path, strlen($root)), '/') : basename($path);
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $path, int $min, int $max, int $stringMax): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < $min || count($value) > $max) {
            throw new SqlObsHostException("Expected list with {$min}..{$max} entries.", $path, 'invalid_list');
        }
        $out = [];
        foreach ($value as $i => $entry) {
            $s = self::string($entry, $path . '[' . $i . ']', $stringMax);
            if (in_array($s, $out, true)) {
                throw new SqlObsHostException('Duplicate list entry.', $path . '[' . $i . ']', 'duplicate_list_entry');
            }
            $out[] = $s;
        }
        return $out;
    }

    private static function expectHash(mixed $value, string $actual, string $path): void
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1 || !hash_equals($value, $actual)) {
            throw new SqlObsHostException('SHA-256 mismatch.', $path, 'hash_mismatch');
        }
    }

    private static function sha(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if (!is_string($hash)) {
            throw new SqlObsHostException('Unable to hash file.', '$', 'hash_failed', 2);
        }
        return $hash;
    }

    /** @param array<string,mixed> $value */
    private static function canonicalJson(array $value): string
    {
        $sort = static function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (array_is_list($item)) {
                return array_map($sort, $item);
            }
            ksort($item, SORT_STRING);
            foreach ($item as $key => $child) {
                $item[$key] = $sort($child);
            }
            return $item;
        };
        return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function majorMinor(string $version): string
    {
        return preg_match('/^(\d+\.\d+)/', $version, $m) === 1 ? $m[1] : $version;
    }

    private static function slash(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}

/** @return array<string,string|bool> */
function sqlobs_args(array $argv): array
{
    $out = ['command' => 'verify', 'config' => 'config/sql-observability/host.json'];
    $commandSet = false;
    for ($i = 1; $i < count($argv); $i++) {
        $arg = (string)$argv[$i];
        if (!$commandSet && !str_starts_with($arg, '--')) {
            $out['command'] = $arg;
            $commandSet = true;
            continue;
        }
        if ($arg === '--help') {
            $out['help'] = true;
            continue;
        }
        if (!str_starts_with($arg, '--')) {
            throw new SqlObsHostException('Unexpected positional argument.', '$.cli', 'unexpected_cli_argument', 2);
        }
        $raw = substr($arg, 2);
        [$key, $value] = str_contains($raw, '=') ? explode('=', $raw, 2) : [$raw, null];
        if (!in_array($key, ['config', 'id', 'event', 'mode', 'path', 'scenario', 'repetitions'], true)) {
            throw new SqlObsHostException('Unknown CLI option.', '$.cli.' . $key, 'unknown_cli_option', 2);
        }
        if ($value === null) {
            if (!isset($argv[$i + 1]) || str_starts_with((string)$argv[$i + 1], '--')) {
                throw new SqlObsHostException('CLI option requires a value.', '$.cli.' . $key, 'missing_cli_value', 2);
            }
            $value = (string)$argv[++$i];
        }
        $out[$key] = $value;
    }
    return $out;
}

function sqlobs_usage(): void
{
    echo <<<TXT
Testkit SQL observability host config

Usage:
  php scripts/sql-observability/config.php verify [--config path]
  php scripts/sql-observability/config.php list [--config path]
  php scripts/sql-observability/config.php scenario --id <scenario>
  php scripts/sql-observability/config.php dataset --path <manifest>
  php scripts/sql-observability/config.php event-mode --event <name> [--mode off|report|warn|fail]
  php scripts/sql-observability/config.php matrix [--scenario all|id] [--repetitions 1..5]
  php scripts/sql-observability/config.php scenario-matrix [--scenario all|id]

Outputs normalized JSON. Exit: 0 valid, 2 operational/CLI, 3 invalid contract.
TXT;
}

try {
    $args = sqlobs_args($argv);
    if (!empty($args['help'])) {
        sqlobs_usage();
        exit(0);
    }
    $command = (string)$args['command'];
    if ($command === 'dataset') {
        $path = (string)($args['path'] ?? '');
        if ($path === '') {
            throw new SqlObsHostException('Dataset path is required.', '$.cli.path', 'dataset_path_required', 2);
        }
        $result = SqlObsHostConfig::loadDataset($path);
    } else {
        $host = SqlObsHostConfig::loadHost((string)$args['config']);
        if ($command === 'verify') {
            $result = $host;
        } elseif ($command === 'list') {
            $result = [
                'schema_version' => $host['schema_version'],
                'status' => $host['status'],
                'scenarios' => array_map(
                    static fn(array $s): array => [
                        'id' => $s['id'],
                        'enabled' => $s['enabled'],
                        'label' => $s['label'],
                        'test_file' => $s['test_file'],
                        'repetitions' => $s['repetitions'],
                        'baseline_status' => $s['baseline_status'],
                    ],
                    $host['scenarios']
                ),
            ];
        } elseif ($command === 'scenario') {
            $id = (string)($args['id'] ?? '');
            $matches = array_values(array_filter(
                $host['scenarios'],
                static fn(array $scenario): bool => $scenario['id'] === $id
            ));
            if ($matches === []) {
                throw new SqlObsHostException('Unknown scenario.', '$.cli.id', 'scenario_not_found', 2);
            }
            $result = $matches[0];
            $result['project'] = $host['project'];
            $result['defaults'] = $host['defaults'];
            $result['host_status'] = $host['status'];
        } elseif ($command === 'scenario-matrix') {
            $selection = trim((string)($args['scenario'] ?? 'all'));
            $include = [];
            foreach ($host['scenarios'] as $scenario) {
                if (empty($scenario['enabled'])) {
                    continue;
                }
                if ($selection !== 'all' && $scenario['id'] !== $selection) {
                    continue;
                }
                $include[] = [
                    'scenario' => $scenario['id'],
                    'timeout_minutes' => $scenario['timeout_minutes'],
                    'artifact_retention_days' => $host['defaults']['artifact_retention_days'],
                ];
            }
            if ($include === []) {
                throw new SqlObsHostException('No enabled scenario matched the selection.', '$.cli.scenario', 'empty_matrix', 2);
            }
            $result = ['include' => $include];
        } elseif ($command === 'matrix') {
            $selection = trim((string)($args['scenario'] ?? 'all'));
            $override = trim((string)($args['repetitions'] ?? ''));
            if ($override !== '' && (preg_match('/^[1-5]$/', $override) !== 1)) {
                throw new SqlObsHostException('repetitions must be 1..5.', '$.cli.repetitions', 'invalid_repetitions', 2);
            }
            $include = [];
            foreach ($host['scenarios'] as $scenario) {
                if (empty($scenario['enabled'])) {
                    continue;
                }
                if ($selection !== 'all' && $scenario['id'] !== $selection) {
                    continue;
                }
                $repetitions = $override !== '' ? (int)$override : (int)$scenario['repetitions'];
                for ($rep = 1; $rep <= $repetitions; $rep++) {
                    $include[] = [
                        'scenario' => $scenario['id'],
                        'repetition' => $rep,
                        'timeout_minutes' => $scenario['timeout_minutes'],
                        'artifact_retention_days' => $host['defaults']['artifact_retention_days'],
                    ];
                }
            }
            if ($include === []) {
                throw new SqlObsHostException('No enabled scenario matched the matrix selection.', '$.cli.scenario', 'empty_matrix', 2);
            }
            $result = ['include' => $include];
        } elseif ($command === 'event-mode') {
            $event = (string)($args['event'] ?? '');
            $aliases = ['push' => 'push_main', 'main' => 'push_main', 'cron' => 'schedule'];
            $event = $aliases[$event] ?? $event;
            if (!isset($host['events'][$event])) {
                throw new SqlObsHostException('Unknown event.', '$.cli.event', 'event_not_found', 2);
            }
            $override = trim((string)($args['mode'] ?? ''));
            $effective = $override !== '' ? $override : (string)$host['events'][$event]['gate_mode'];
            if (!in_array($effective, ['off', 'report', 'warn', 'fail'], true)) {
                throw new SqlObsHostException('Unsupported gate mode.', '$.cli.mode', 'unsupported_gate_mode', 2);
            }
            $result = [
                'requested_gate_mode' => $override,
                'effective_gate_mode' => $effective,
                'source' => $override !== '' ? 'workflow_dispatch_input' : 'host_event_mapping',
                'event' => $event,
            ];
        } else {
            throw new SqlObsHostException('Unknown command.', '$.cli.command', 'unknown_command', 2);
        }
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
} catch (SqlObsHostException $e) {
    fwrite(STDERR, 'ERROR[' . $e->errorCodeId . '] ' . $e->jsonPath . ': ' . $e->getMessage() . PHP_EOL);
    exit($e->exitStatus);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR[unhandled] ' . $e->getMessage() . PHP_EOL);
    exit(2);
}
