<?php
declare(strict_types=1);

namespace Testkit\Core\Config;

use InvalidArgumentException;

final class ContractRegistry
{
    public const SCHEMA_NAME = 'testkit.contract_registry';
    public const SCHEMA_VERSION = 1;

    /** @return array<string,array{public_name:string,language:string,runner:string,profile:string}> */
    private static function suiteBlueprints(): array
    {
        return [
            'back_php' => ['public_name' => 'back-php', 'language' => 'php', 'runner' => 'BackPhpSuite', 'profile' => 'domain_php'],
            'back_python' => ['public_name' => 'back-python', 'language' => 'python', 'runner' => 'BackPythonSuite', 'profile' => 'domain_python'],
            'front_php' => ['public_name' => 'front-php', 'language' => 'php', 'runner' => 'FrontPhpSuite', 'profile' => 'domain_php'],
            'front_js' => ['public_name' => 'front-js', 'language' => 'javascript', 'runner' => 'FrontJsSuite', 'profile' => 'domain_js'],
            'infra_php' => ['public_name' => 'infra-php', 'language' => 'php', 'runner' => 'InfraPhpSuite', 'profile' => 'infra_php'],
            'migration_contract' => ['public_name' => 'migration-contract', 'language' => 'php', 'runner' => 'MigrationContractSuite', 'profile' => 'migration_contract'],
            'reference_contract' => ['public_name' => 'reference-contract', 'language' => 'php', 'runner' => 'ReferenceContractSuite', 'profile' => 'reference_contract'],
            'sql_observability' => ['public_name' => 'sql-observability', 'language' => 'bash/php', 'runner' => 'SqlObservabilitySuite', 'profile' => 'sql_observability'],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public static function suites(): array
    {
        $out = [];
        foreach (self::suiteBlueprints() as $suiteId => $blueprint) {
            $profile = $blueprint['profile'];
            $out[$suiteId] = [
                'public_name' => $blueprint['public_name'],
                'language' => $blueprint['language'],
                'runner' => $blueprint['runner'],
                'capabilities' => self::capabilitiesFor($suiteId, $profile, $blueprint['language']),
                'restrictions' => self::restrictionsFor($profile),
            ];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function capabilitiesFor(string $suiteId, string $profile, string $language): array
    {
        $php = $language === 'php';
        $python = $language === 'python';
        $base = [
            'shared_discovery_contract' => true,
            'perf_thresholds' => true,
            'fragility_history' => true,
            'module_scoped_reports' => true,
            'native_coverage_artifacts' => $php || $python,
            'structured_coverage_diagnostics' => $php,
            'coverage_formats' => $php ? ['json', 'lcov'] : ($python ? ['trace'] : []),
            'suite_engine' => $suiteId,
            'declared_runner_contract' => true,
            'structured_warnings' => true,
            'canonical_seed_state' => true,
            'agent_run_compatible' => true,
            'parallel_guard' => $profile !== 'domain_js',
            'bootstrap_only' => false,
            'executes_domain_tests' => str_starts_with($profile, 'domain_') || $profile === 'infra_php',
            'supports_snapshot_baseline' => str_starts_with($profile, 'domain_') || $profile === 'migration_contract',
            'supports_layered_baseline' => str_starts_with($profile, 'domain_'),
        ];

        return match ($profile) {
            'infra_php' => array_merge($base, [
                'operational_host_suite' => true,
                'supports_http_checks' => true,
                'supports_docker_checks' => true,
                'supports_security_boundary_checks' => true,
                'supports_snapshot_baseline' => false,
                'supports_layered_baseline' => false,
                'canonical_seed_state' => false,
            ]),
            'migration_contract' => array_merge($base, [
                'bootstrap_only' => true,
                'executes_domain_tests' => false,
                'supports_layered_baseline' => false,
            ]),
            'reference_contract' => array_merge($base, [
                'native_coverage_artifacts' => false,
                'structured_coverage_diagnostics' => false,
                'coverage_formats' => [],
                'executes_domain_tests' => false,
                'static_reference_scan' => true,
                'php_include_resolution' => true,
                'supports_snapshot_baseline' => false,
                'supports_layered_baseline' => false,
            ]),
            'sql_observability' => array_merge($base, [
                'native_coverage_artifacts' => false,
                'structured_coverage_diagnostics' => false,
                'coverage_formats' => [],
                'executes_domain_tests' => false,
                'sql_query_profiling' => true,
                'policy_gate' => true,
                'supports_snapshot_baseline' => false,
                'supports_layered_baseline' => false,
            ]),
            default => $base,
        };
    }

    /** @return array<string,mixed> */
    private static function restrictionsFor(string $profile): array
    {
        $shared = [
            'store_bootstrap' => 'project_shared_store',
            'db_sensitivity' => 'discovered',
            'top_level_parallel_policy' => 'exclusive_when_db_sensitive',
            'intra_suite_parallel_policy' => 'per_worker_when_db_sensitive',
            'seed_state_contract' => 'required',
            'bootstrap_mutates_store' => true,
            'supports_per_worker_parallel' => true,
            'top_level_parallel_safe' => false,
        ];

        return match ($profile) {
            'infra_php' => [
                'store_bootstrap' => 'none', 'db_sensitivity' => 'discovered',
                'top_level_parallel_policy' => 'allowed_unless_test_declares_db_sensitive',
                'intra_suite_parallel_policy' => 'allowed_unless_test_declares_serial_or_db_sensitive',
                'seed_state_contract' => 'not_applicable', 'bootstrap_mutates_store' => false,
                'supports_per_worker_parallel' => true, 'top_level_parallel_safe' => true,
                'operational_host_suite' => true, 'may_require_external_http_server' => true,
                'may_require_docker_runtime' => true,
            ],
            'migration_contract' => [
                'store_bootstrap' => 'project_shared_store', 'db_sensitivity' => 'always',
                'top_level_parallel_policy' => 'exclusive', 'intra_suite_parallel_policy' => 'sequential_only',
                'seed_state_contract' => 'required', 'bootstrap_mutates_store' => true,
                'supports_per_worker_parallel' => false, 'top_level_parallel_safe' => false,
                'requires_snapshot_baseline' => true, 'requires_store_driver' => 'mysql',
                'requires_db_strategy' => 'shared', 'requires_jobs' => 1, 'bootstrap_only' => true,
            ],
            'reference_contract' => [
                'store_bootstrap' => 'none', 'db_sensitivity' => 'never',
                'top_level_parallel_policy' => 'allowed', 'intra_suite_parallel_policy' => 'allowed',
                'seed_state_contract' => 'not_applicable', 'bootstrap_mutates_store' => false,
                'supports_per_worker_parallel' => false, 'top_level_parallel_safe' => true,
                'static_scan_only' => true,
            ],
            'sql_observability' => [
                'store_bootstrap' => 'scenario_managed_mysql', 'db_sensitivity' => 'always',
                'top_level_parallel_policy' => 'exclusive', 'intra_suite_parallel_policy' => 'sequential_only',
                'seed_state_contract' => 'scenario_defined', 'bootstrap_mutates_store' => true,
                'supports_per_worker_parallel' => false, 'top_level_parallel_safe' => false,
                'may_require_docker_runtime' => true, 'preserve_policy_exit_code' => true,
            ],
            default => $shared,
        };
    }

    /** @return array<string,array{suites:list<string>}> */
    public static function groups(): array
    {
        return [
            'all' => ['suites' => ['back_php', 'back_python', 'front_php', 'front_js', 'infra_php']],
            'back' => ['suites' => ['back_php', 'back_python']],
            'front' => ['suites' => ['front_php', 'front_js']],
            'infra' => ['suites' => ['infra_php']],
            'public_html' => ['suites' => ['front_php', 'front_js']],
            'php' => ['suites' => ['back_php', 'front_php', 'infra_php']],
            'js' => ['suites' => ['front_js']],
        ];
    }

    /** @return array<string,array{category:string,suites:list<string>}> */
    public static function categories(): array
    {
        $suites = ['back_php', 'back_python', 'front_php', 'front_js', 'infra_php'];
        $out = [];
        foreach (['smoke', 'perf', 'stress', 'contract', 'critical', 'security', 'slow'] as $category) {
            $out[$category] = ['category' => $category, 'suites' => $suites];
        }
        return $out;
    }

    /** @return array<string,string> */
    public static function aliases(): array
    {
        return [
            'back-py' => 'back-python', 'python' => 'back-python', 'py' => 'back-python',
            'http' => 'infra-php', 'migration' => 'migration-contract', 'migrations' => 'migration-contract',
            'references' => 'reference-contract', 'php-references' => 'reference-contract',
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public static function publicDefinitions(): array
    {
        $definitions = [];
        foreach (self::suites() as $suiteId => $suite) {
            $name = (string)$suite['public_name'];
            $definitions[$name] = ['kind' => 'suite', 'canonical' => $name, 'suite_id' => $suiteId, 'suites' => [$suiteId], 'deprecated' => false];
        }
        foreach (self::groups() as $name => $group) {
            $definitions[$name] = ['kind' => 'group', 'canonical' => $name, 'suites' => $group['suites'], 'deprecated' => false];
        }
        foreach (self::categories() as $name => $category) {
            $definitions[$name] = ['kind' => 'category', 'canonical' => $name, 'category' => $name, 'suites' => $category['suites'], 'deprecated' => false];
        }
        foreach (self::aliases() as $alias => $canonical) {
            $target = $definitions[$canonical];
            $definitions[$alias] = [
                'kind' => 'alias', 'target_kind' => $target['kind'], 'canonical' => $canonical,
                'suites' => $target['suites'], 'deprecated' => true, 'remove_in_phase' => 3,
            ];
        }
        ksort($definitions);
        return $definitions;
    }

    /** @return list<string> */
    public static function suiteIds(): array { return array_keys(self::suites()); }
    /** @return list<string> */
    public static function publicNames(): array { return array_keys(self::publicDefinitions()); }

    /** @return array<string,mixed>|null */
    public static function definition(string $name): ?array
    {
        $definitions = self::publicDefinitions();
        $key = strtolower(trim($name));
        return isset($definitions[$key]) ? $definitions[$key] : null;
    }

    /** @return list<string> */
    public static function resolve(string $name): array
    {
        return array_values((array)(self::definition($name)['suites'] ?? []));
    }

    public static function targetKind(string $name): string
    {
        $definition = self::definition($name);
        if (!is_array($definition)) { return 'unknown'; }
        return (string)(($definition['kind'] ?? '') === 'alias' ? ($definition['target_kind'] ?? 'unknown') : ($definition['kind'] ?? 'unknown'));
    }

    public static function canonicalName(string $name): string
    {
        return (string)(self::definition($name)['canonical'] ?? '');
    }

    public static function categoryFor(string $name): string
    {
        $definition = self::definition($name);
        return is_array($definition) && ($definition['kind'] ?? '') === 'category' ? (string)$definition['category'] : '';
    }

    /** @return array<string,mixed> */
    public static function suiteContract(string $suiteId, string $language = ''): array
    {
        $suiteId = strtolower(trim($suiteId));
        $suite = self::suites()[$suiteId] ?? null;
        if (!is_array($suite)) { throw new InvalidArgumentException('suite no registrada: ' . $suiteId); }
        $registered = strtolower((string)$suite['language']);
        $requested = strtolower(trim($language));
        $compatible = $requested === '' || $requested === $registered || ($suiteId === 'front_js' && in_array($requested, ['js', 'javascript'], true));
        if (!$compatible) { throw new InvalidArgumentException("language mismatch para {$suiteId}: esperado {$registered}, recibido {$requested}"); }
        return [
            'contract_version' => self::SCHEMA_VERSION, 'suite_id' => $suiteId,
            'public_name' => $suite['public_name'], 'language' => $registered, 'runner' => $suite['runner'],
            'capabilities' => $suite['capabilities'], 'hazards' => $suite['restrictions'], 'restrictions' => $suite['restrictions'],
        ];
    }

    /** @return array<string,mixed> */
    public static function supportMatrix(): array
    {
        return [
            'engines' => [
                ['name' => 'mysql', 'status' => 'closed_primary', 'role' => 'primary_structural_store'],
                ['name' => 'pgsql', 'status' => 'partial_experimental', 'role' => 'secondary_partial_store'],
                ['name' => 'none', 'status' => 'no_store', 'role' => 'no_structural_store'],
            ],
            'services' => [
                ['name' => 'redis', 'status' => 'auxiliary', 'role' => 'optional_service'],
                ['name' => 'influx', 'status' => 'auxiliary_profiling', 'role' => 'profiling_service'],
            ],
        ];
    }

    /** @return list<array{command:string,purpose:string}> */
    public static function commands(): array
    {
        return [
            ['command' => 'php runTest.php --help', 'purpose' => 'mostrar ayuda derivada del registro'],
            ['command' => 'php runTest.php <target> --list', 'purpose' => 'listar la selección efectiva'],
            ['command' => 'php scripts/contract.php --json', 'purpose' => 'serializar el registro contractual'],
            ['command' => 'php scripts/contract.php validate --json', 'purpose' => 'validar invariantes del registro'],
            ['command' => 'php scripts/inspect.php config-schema --json', 'purpose' => 'serializar configuración y registro'],
        ];
    }

    /** @return array<string,mixed> */
    public static function payload(): array
    {
        return [
            'schema' => ['name' => self::SCHEMA_NAME, 'version' => self::SCHEMA_VERSION], 'digest' => self::digest(),
            'commands' => self::commands(), 'suites' => self::suites(), 'targets' => self::publicNames(),
            'target_definitions' => self::publicDefinitions(), 'groups' => self::groups(),
            'categories' => self::categories(), 'aliases' => self::aliases(), 'support_matrix' => self::supportMatrix(),
        ];
    }

    /** @param array<string,mixed> $legacy @return array<string,mixed> */
    public static function configSchemaPayload(array $legacy): array
    {
        $registry = self::payload();
        return array_merge($legacy, [
            'schema_version' => 6, 'support_contract_version' => self::SCHEMA_VERSION,
            'contract_registry' => $registry['schema'], 'contract_registry_digest' => $registry['digest'],
            'commands' => $registry['commands'], 'suites' => $registry['suites'], 'targets' => $registry['targets'],
            'target_definitions' => $registry['target_definitions'], 'groups' => $registry['groups'],
            'categories' => $registry['categories'], 'aliases' => $registry['aliases'],
            'support_matrix' => $registry['support_matrix'],
        ]);
    }

    /** @return list<string> */
    public static function validate(): array
    {
        $errors = [];
        $suites = self::suites();
        $definitions = self::publicDefinitions();
        foreach ($suites as $suiteId => $suite) {
            foreach (['public_name', 'language', 'runner', 'capabilities', 'restrictions'] as $key) {
                if (!array_key_exists($key, $suite)) { $errors[] = "suite {$suiteId} missing {$key}"; }
            }
            $public = (string)($suite['public_name'] ?? '');
            if (($definitions[$public]['suite_id'] ?? null) !== $suiteId) { $errors[] = "suite {$suiteId} public target mismatch"; }
        }
        foreach ($definitions as $name => $definition) {
            $resolved = array_values((array)($definition['suites'] ?? []));
            if ($resolved === []) { $errors[] = "target {$name} resolves no suites"; }
            foreach ($resolved as $suiteId) {
                if (!isset($suites[$suiteId])) { $errors[] = "target {$name} references unknown suite {$suiteId}"; }
            }
            if (($definition['kind'] ?? '') === 'alias') {
                $canonical = (string)($definition['canonical'] ?? '');
                if (!isset($definitions[$canonical]) || ($definitions[$canonical]['kind'] ?? '') === 'alias') {
                    $errors[] = "alias {$name} has invalid canonical target {$canonical}";
                }
            }
            if (str_contains($name, 'tarifa')) { $errors[] = "domain-specific target found: {$name}"; }
        }
        return array_values(array_unique($errors));
    }

    public static function digest(): string
    {
        return hash('sha256', self::canonicalJson([
            'schema' => ['name' => self::SCHEMA_NAME, 'version' => self::SCHEMA_VERSION],
            'suites' => self::suites(), 'definitions' => self::publicDefinitions(), 'support_matrix' => self::supportMatrix(),
        ]));
    }

    public static function renderRunHelp(): string
    {
        $suiteNames = array_map(static fn(array $suite): string => (string)$suite['public_name'], self::suites());
        return implode(PHP_EOL, [
            'Uso:', '  php runTest.php [target] [--list]', '  php runTest.php --help', '',
            'Suites:', '  ' . implode(' | ', $suiteNames), '',
            'Grupos:', '  ' . implode(' | ', array_keys(self::groups())), '',
            'Categorías:', '  ' . implode(' | ', array_keys(self::categories())), '',
            'Aliases heredados (se eliminan en Fase 3):', '  ' . implode(' | ', array_keys(self::aliases())), '',
            'Opciones soportadas:', '  --list     lista la selección efectiva', '  --help     muestra esta ayuda', '',
            'Contrato: php scripts/contract.php --json', 'Esquema: php scripts/inspect.php config-schema --json',
        ]) . PHP_EOL;
    }

    /** @param array<string,mixed> $payload */
    public static function renderConfigSchemaText(array $payload): string
    {
        return implode(PHP_EOL, [
            'config-schema', str_repeat('=', 72),
            'schema_version: ' . (string)($payload['schema_version'] ?? ''),
            'contract_registry: ' . self::SCHEMA_NAME . '@' . self::SCHEMA_VERSION,
            'contract_registry_digest: ' . (string)($payload['contract_registry_digest'] ?? ''),
            'suites: ' . implode(', ', array_keys(self::suites())),
            'groups: ' . implode(', ', array_keys(self::groups())),
            'categories: ' . implode(', ', array_keys(self::categories())),
            'aliases: ' . implode(', ', array_keys(self::aliases())),
            'environment_entries: ' . count((array)($payload['environment'] ?? [])),
        ]) . PHP_EOL;
    }

    public static function renderMarkdown(): string
    {
        $lines = [
            '# Registro contractual de testKit', '',
            '> Generado desde `Testkit\\Core\\Config\\ContractRegistry`. No editar listas manualmente.', '',
            'Schema: `' . self::SCHEMA_NAME . '@' . self::SCHEMA_VERSION . '`  ',
            'Digest: `' . self::digest() . '`', '', '## Suites', '',
            '| Nombre público | Suite ID | Lenguaje | Runner |', '|---|---|---|---|',
        ];
        foreach (self::suites() as $suiteId => $suite) {
            $lines[] = "| `{$suite['public_name']}` | `{$suiteId}` | `{$suite['language']}` | `{$suite['runner']}` |";
        }
        $lines[] = ''; $lines[] = '## Grupos'; $lines[] = '';
        foreach (self::groups() as $name => $group) { $lines[] = '- `' . $name . '`: `' . implode('`, `', $group['suites']) . '`'; }
        $lines[] = ''; $lines[] = '## Categorías'; $lines[] = '';
        foreach (self::categories() as $name => $category) { $lines[] = '- `' . $name . '`: `' . implode('`, `', $category['suites']) . '`'; }
        $lines[] = ''; $lines[] = '## Aliases heredados'; $lines[] = '';
        $lines[] = 'Se eliminan en Fase 3; no son nombres canónicos.'; $lines[] = '';
        foreach (self::aliases() as $alias => $canonical) { $lines[] = '- `' . $alias . '` → `' . $canonical . '`'; }
        $lines[] = ''; $lines[] = '## Contrato completo'; $lines[] = '';
        $lines[] = 'Capacidades, restricciones, target definitions y support matrix se serializan con:';
        $lines[] = ''; $lines[] = '```bash'; $lines[] = 'php scripts/contract.php --json';
        $lines[] = 'php scripts/contract.php validate --json'; $lines[] = 'php scripts/contract.php check-doc docs/CONTRACT_REGISTRY.md';
        $lines[] = 'php tests/framework/test_contract_registry.php'; $lines[] = '```'; $lines[] = '';
        return implode(PHP_EOL, $lines);
    }

    /** @param mixed $value */
    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) { return $value; }
        if (!array_is_list($value)) { ksort($value); }
        foreach ($value as $key => $child) { $value[$key] = self::canonicalize($child); }
        return $value;
    }

    /** @param array<string,mixed> $value */
    private static function canonicalJson(array $value): string
    {
        return json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
