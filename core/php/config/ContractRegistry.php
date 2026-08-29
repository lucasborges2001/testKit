<?php
declare(strict_types=1);

namespace Testkit\Core\Config;

use InvalidArgumentException;

final class ContractRegistry
{
    public const SCHEMA_NAME = 'testkit.contract_registry';
    public const SCHEMA_VERSION = 2;

    /** @return array<string,array<string,string>> */
    private static function blueprints(): array
    {
        return [
            'back_php' => ['public_name'=>'back-php','language'=>'php','runner'=>'BackPhpSuite','profile'=>'domain_php'],
            'back_python' => ['public_name'=>'back-python','language'=>'python','runner'=>'BackPythonSuite','profile'=>'domain_python'],
            'front_php' => ['public_name'=>'front-php','language'=>'php','runner'=>'FrontPhpSuite','profile'=>'domain_php'],
            'front_js' => ['public_name'=>'front-js','language'=>'javascript','runner'=>'FrontJsSuite','profile'=>'domain_js'],
            'infra_php' => ['public_name'=>'infra-php','language'=>'php','runner'=>'InfraPhpSuite','profile'=>'infra_php'],
            'migration_contract' => ['public_name'=>'migration-contract','language'=>'php','runner'=>'MigrationContractSuite','profile'=>'migration_contract'],
            'reference_contract' => ['public_name'=>'reference-contract','language'=>'php','runner'=>'ReferenceContractSuite','profile'=>'reference_contract'],
            'sql_observability' => ['public_name'=>'sql-observability','language'=>'bash/php','runner'=>'SqlObservabilitySuite','profile'=>'sql_observability'],
            'sql_static_audit' => ['public_name'=>'sql-static-audit','language'=>'php','runner'=>'SqlStaticAuditSuite','profile'=>'sql_static_audit'],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public static function suites(): array
    {
        $out = [];
        foreach (self::blueprints() as $id => $row) {
            $out[$id] = [
                'public_name'=>$row['public_name'],'language'=>$row['language'],'runner'=>$row['runner'],
                'capabilities'=>self::capabilities($id, $row['profile'], $row['language']),
                'restrictions'=>self::restrictions($row['profile']),
            ];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function capabilities(string $id, string $profile, string $language): array
    {
        $php = $language === 'php';
        $python = $language === 'python';
        $base = [
            'shared_discovery_contract'=>true,'perf_thresholds'=>true,'fragility_history'=>true,
            'module_scoped_reports'=>true,'native_coverage_artifacts'=>$php || $python,
            'structured_coverage_diagnostics'=>$php,'coverage_formats'=>$php ? ['json','lcov'] : ($python ? ['trace'] : []),
            'suite_engine'=>$id,'declared_runner_contract'=>true,'structured_warnings'=>true,
            'canonical_seed_state'=>true,'agent_run_compatible'=>true,'parallel_guard'=>$profile !== 'domain_js',
            'bootstrap_only'=>false,'executes_domain_tests'=>str_starts_with($profile, 'domain_') || $profile === 'infra_php',
            'supports_snapshot_baseline'=>str_starts_with($profile, 'domain_') || $profile === 'migration_contract',
            'supports_layered_baseline'=>str_starts_with($profile, 'domain_'),
        ];
        $extra = match ($profile) {
            'infra_php' => ['operational_host_suite'=>true,'supports_http_checks'=>true,'supports_docker_checks'=>true,
                'supports_security_boundary_checks'=>true,'supports_snapshot_baseline'=>false,
                'supports_layered_baseline'=>false,'canonical_seed_state'=>false],
            'migration_contract' => ['bootstrap_only'=>true,'executes_domain_tests'=>false,'supports_layered_baseline'=>false],
            'reference_contract' => ['native_coverage_artifacts'=>false,'structured_coverage_diagnostics'=>false,
                'coverage_formats'=>[],'executes_domain_tests'=>false,'static_reference_scan'=>true,
                'php_include_resolution'=>true,'supports_snapshot_baseline'=>false,'supports_layered_baseline'=>false],
            'sql_observability' => ['native_coverage_artifacts'=>false,'structured_coverage_diagnostics'=>false,
                'coverage_formats'=>[],'executes_domain_tests'=>false,'sql_query_profiling'=>true,'policy_gate'=>true,
                'supports_snapshot_baseline'=>false,'supports_layered_baseline'=>false],
            'sql_static_audit' => ['native_coverage_artifacts'=>false,'structured_coverage_diagnostics'=>false,
                'coverage_formats'=>[],'executes_domain_tests'=>false,'static_sql_audit'=>true,'report_only'=>true,
                'sql_literal_extraction'=>true,'sql_coverage_reasons'=>true,'supports_static_baseline'=>true,
                'supports_snapshot_baseline'=>false,'supports_layered_baseline'=>false],
            default => [],
        };
        return array_merge($base, $extra);
    }

    /** @return array<string,mixed> */
    private static function restrictions(string $profile): array
    {
        return match ($profile) {
            'infra_php' => ['store_bootstrap'=>'none','db_sensitivity'=>'discovered',
                'top_level_parallel_policy'=>'allowed_unless_test_declares_db_sensitive',
                'intra_suite_parallel_policy'=>'allowed_unless_test_declares_serial_or_db_sensitive',
                'seed_state_contract'=>'not_applicable','bootstrap_mutates_store'=>false,
                'supports_per_worker_parallel'=>true,'top_level_parallel_safe'=>true,'operational_host_suite'=>true,
                'may_require_external_http_server'=>true,'may_require_docker_runtime'=>true],
            'migration_contract' => ['store_bootstrap'=>'project_shared_store','db_sensitivity'=>'always',
                'top_level_parallel_policy'=>'exclusive','intra_suite_parallel_policy'=>'sequential_only',
                'seed_state_contract'=>'required','bootstrap_mutates_store'=>true,'supports_per_worker_parallel'=>false,
                'top_level_parallel_safe'=>false,'requires_snapshot_baseline'=>true,'requires_store_driver'=>'mysql',
                'requires_db_strategy'=>'shared','requires_jobs'=>1,'bootstrap_only'=>true],
            'reference_contract' => ['store_bootstrap'=>'none','db_sensitivity'=>'never',
                'top_level_parallel_policy'=>'allowed','intra_suite_parallel_policy'=>'allowed',
                'seed_state_contract'=>'not_applicable','bootstrap_mutates_store'=>false,
                'supports_per_worker_parallel'=>false,'top_level_parallel_safe'=>true,'static_scan_only'=>true],
            'sql_observability' => ['store_bootstrap'=>'scenario_managed_mysql','db_sensitivity'=>'always',
                'top_level_parallel_policy'=>'exclusive','intra_suite_parallel_policy'=>'sequential_only',
                'seed_state_contract'=>'scenario_defined','bootstrap_mutates_store'=>true,
                'supports_per_worker_parallel'=>false,'top_level_parallel_safe'=>false,
                'may_require_docker_runtime'=>true,'preserve_policy_exit_code'=>true],
            'sql_static_audit' => ['store_bootstrap'=>'none','db_sensitivity'=>'never',
                'top_level_parallel_policy'=>'allowed','intra_suite_parallel_policy'=>'not_applicable',
                'seed_state_contract'=>'not_applicable','bootstrap_mutates_store'=>false,
                'supports_per_worker_parallel'=>false,'top_level_parallel_safe'=>true,'static_scan_only'=>true],
            default => ['store_bootstrap'=>'project_shared_store','db_sensitivity'=>'discovered',
                'top_level_parallel_policy'=>'exclusive_when_db_sensitive',
                'intra_suite_parallel_policy'=>'per_worker_when_db_sensitive','seed_state_contract'=>'required',
                'bootstrap_mutates_store'=>true,'supports_per_worker_parallel'=>true,'top_level_parallel_safe'=>false],
        };
    }

    /** @return array<string,array{suites:list<string>}> */
    public static function groups(): array
    {
        return [
            'all'=>['suites'=>['back_php','back_python','front_php','front_js','infra_php']],
            'back'=>['suites'=>['back_php','back_python']], 'front'=>['suites'=>['front_php','front_js']],
            'infra'=>['suites'=>['infra_php']], 'php'=>['suites'=>['back_php','front_php','infra_php']],
            'js'=>['suites'=>['front_js']],
        ];
    }

    /** @return array<string,array{category:string,suites:list<string>}> */
    public static function categories(): array
    {
        $suites = ['back_php','back_python','front_php','front_js','infra_php'];
        $out = [];
        foreach (['smoke','perf','stress','contract','critical','security','slow'] as $name) {
            $out[$name] = ['category'=>$name,'suites'=>$suites];
        }
        return $out;
    }

    /** @return list<string> */
    public static function selectorKinds(): array { return ['suite','group','category']; }

    /** @return array<string,array<string,array<string,mixed>>> */
    public static function selectorDefinitions(): array
    {
        $out = ['suite'=>[],'group'=>[],'category'=>[]];
        foreach (self::suites() as $id => $suite) {
            $name = (string)$suite['public_name'];
            $out['suite'][$name] = ['kind'=>'suite','name'=>$name,'suite_id'=>$id,'suites'=>[$id]];
        }
        foreach (self::groups() as $name => $group) {
            $out['group'][$name] = ['kind'=>'group','name'=>$name,'suites'=>$group['suites']];
        }
        foreach (self::categories() as $name => $category) {
            $out['category'][$name] = ['kind'=>'category','name'=>$name,'category'=>$name,'suites'=>$category['suites']];
        }
        return $out;
    }

    /** @return list<string> */
    public static function selectorNames(string $kind): array
    {
        $kind = self::normalizeKind($kind);
        return array_keys(self::selectorDefinitions()[$kind]);
    }

    /** @return array<string,mixed>|null */
    public static function definition(string $kind, string $name): ?array
    {
        $kind = self::normalizeKind($kind);
        $name = strtolower(trim($name));
        return self::selectorDefinitions()[$kind][$name] ?? null;
    }

    /** @return list<string> */
    public static function resolve(string $kind, string $name): array
    {
        return array_values((array)(self::definition($kind, $name)['suites'] ?? []));
    }

    /** @return array<string,mixed> */
    public static function suiteContract(string $id, string $language = ''): array
    {
        $id = strtolower(trim($id));
        $suite = self::suites()[$id] ?? null;
        if (!is_array($suite)) { throw new InvalidArgumentException('suite no registrada: ' . $id); }
        $registered = strtolower((string)$suite['language']);
        $requested = strtolower(trim($language));
        $ok = $requested === '' || $requested === $registered
            || ($id === 'front_js' && in_array($requested, ['js','javascript'], true));
        if (!$ok) { throw new InvalidArgumentException("language mismatch para {$id}: esperado {$registered}, recibido {$requested}"); }
        return ['contract_version'=>self::SCHEMA_VERSION,'suite_id'=>$id,'public_name'=>$suite['public_name'],
            'language'=>$registered,'runner'=>$suite['runner'],'capabilities'=>$suite['capabilities'],
            'hazards'=>$suite['restrictions'],'restrictions'=>$suite['restrictions']];
    }

    /** @return array<string,mixed> */
    public static function supportMatrix(): array
    {
        return ['engines'=>[
            ['name'=>'mysql','status'=>'closed_primary','role'=>'primary_structural_store'],
            ['name'=>'pgsql','status'=>'partial_experimental','role'=>'secondary_partial_store'],
            ['name'=>'none','status'=>'no_store','role'=>'no_structural_store']],
            'services'=>[
                ['name'=>'redis','status'=>'auxiliary','role'=>'optional_service'],
                ['name'=>'influx','status'=>'auxiliary_profiling','role'=>'profiling_service']]];
    }

    /** @return list<array{command:string,purpose:string}> */
    public static function commands(): array
    {
        return [
            ['command'=>'php runTest.php --suite back-php','purpose'=>'ejecutar una suite concreta'],
            ['command'=>'php runTest.php --group all --list','purpose'=>'listar la selección de un grupo'],
            ['command'=>'php runTest.php --category smoke','purpose'=>'ejecutar una categoría'],
            ['command'=>'php scripts/contract.php --json','purpose'=>'serializar el registro contractual'],
            ['command'=>'php scripts/contract.php validate --json','purpose'=>'validar invariantes del registro'],
            ['command'=>'php scripts/inspect.php config-schema --json','purpose'=>'serializar configuración y registro'],
        ];
    }

    /** @return array<string,mixed> */
    public static function payload(): array
    {
        return ['schema'=>['name'=>self::SCHEMA_NAME,'version'=>self::SCHEMA_VERSION],'digest'=>self::digest(),
            'commands'=>self::commands(),'suites'=>self::suites(),'selector_kinds'=>self::selectorKinds(),
            'selectors'=>self::selectorDefinitions(),'groups'=>self::groups(),'categories'=>self::categories(),
            'support_matrix'=>self::supportMatrix()];
    }

    /** @param array<string,mixed> $legacy @return array<string,mixed> */
    public static function configSchemaPayload(array $legacy): array
    {
        foreach (['targets','target_definitions','aliases'] as $key) { unset($legacy[$key]); }
        $r = self::payload();
        return array_merge($legacy, ['schema_version'=>7,'support_contract_version'=>self::SCHEMA_VERSION,
            'contract_registry'=>$r['schema'],'contract_registry_digest'=>$r['digest'],'commands'=>$r['commands'],
            'suites'=>$r['suites'],'selector_kinds'=>$r['selector_kinds'],'selectors'=>$r['selectors'],
            'groups'=>$r['groups'],'categories'=>$r['categories'],'support_matrix'=>$r['support_matrix']]);
    }

    /** @return list<string> */
    public static function validate(): array
    {
        $errors = [];
        $suites = self::suites();
        foreach (self::selectorDefinitions() as $kind => $definitions) {
            foreach ($definitions as $name => $definition) {
                $resolved = array_values((array)($definition['suites'] ?? []));
                if ($resolved === []) { $errors[] = "selector {$kind}:{$name} resolves no suites"; }
                foreach ($resolved as $id) {
                    if (!isset($suites[$id])) { $errors[] = "selector {$kind}:{$name} references unknown suite {$id}"; }
                }
                if (str_contains($name, 'tarifa')) { $errors[] = "domain-specific selector found: {$kind}:{$name}"; }
            }
        }
        if (isset(self::groups()['public_html'])) { $errors[] = 'legacy public_html group is still registered'; }
        return array_values(array_unique($errors));
    }

    public static function digest(): string
    {
        return hash('sha256', self::canonicalJson(['schema'=>['name'=>self::SCHEMA_NAME,'version'=>self::SCHEMA_VERSION],
            'suites'=>self::suites(),'selectors'=>self::selectorDefinitions(),'support_matrix'=>self::supportMatrix()]));
    }

    public static function renderRunHelp(): string
    {
        return implode(PHP_EOL, ['Uso:','  php runTest.php --suite <nombre> [--list] [--test <repo-relative>]...',
            '  php runTest.php --group <nombre> [--list] [--test <repo-relative>]...',
            '  php runTest.php --category <nombre> [--list] [--test <repo-relative>]...',
            '  php runTest.php --suite <nombre> --selection-file <repo-relative>','  php runTest.php --help','',
            'Suites:','  '.implode(' | ', self::selectorNames('suite')),'','Grupos:',
            '  '.implode(' | ', self::selectorNames('group')),'','Categorías:',
            '  '.implode(' | ', self::selectorNames('category')),'','Reglas:',
            '  debe declararse exactamente uno de --suite, --group o --category',
            '  no se aceptan targets posicionales, aliases ni TESTKIT_TARGET_*',
            '  --test es repetible y exige rutas repo-relative exactas',
            '  --test y --selection-file son mutuamente excluyentes','',
            'Contrato: php scripts/contract.php --json','Esquema: php scripts/inspect.php config-schema --json']) . PHP_EOL;
    }

    /** @param array<string,mixed> $payload */
    public static function renderConfigSchemaText(array $payload): string
    {
        return implode(PHP_EOL, ['config-schema',str_repeat('=',72),
            'schema_version: '.(string)($payload['schema_version'] ?? ''),
            'contract_registry: '.self::SCHEMA_NAME.'@'.self::SCHEMA_VERSION,
            'contract_registry_digest: '.(string)($payload['contract_registry_digest'] ?? ''),
            'selector_kinds: '.implode(', ', self::selectorKinds()),
            'suites: '.implode(', ', self::selectorNames('suite')),
            'groups: '.implode(', ', self::selectorNames('group')),
            'categories: '.implode(', ', self::selectorNames('category')),
            'environment_entries: '.count((array)($payload['environment'] ?? []))]) . PHP_EOL;
    }

    public static function renderMarkdown(): string
    {
        $lines = ['# Registro contractual de testKit','',
            '> Generado desde `Testkit\\Core\\Config\\ContractRegistry`. No editar listas manualmente.','',
            'Schema: `'.self::SCHEMA_NAME.'@'.self::SCHEMA_VERSION.'`','Digest: `'.self::digest().'`','',
            '## Selector público','',
            'Toda corrida declara exactamente uno de `--suite`, `--group` o `--category`.',
            'No existen targets posicionales, aliases ni extensiones `TESTKIT_TARGET_*`.','',
            '## Suites','', '| Nombre público | Suite ID | Lenguaje | Runner |','|---|---|---|---|'];
        foreach (self::suites() as $id => $s) {
            $lines[] = "| `{$s['public_name']}` | `{$id}` | `{$s['language']}` | `{$s['runner']}` |";
        }
        $lines[]=''; $lines[]='## Grupos'; $lines[]='';
        foreach (self::groups() as $name=>$g) { $lines[]='- `'.$name.'`: `'.implode('`, `',$g['suites']).'`'; }
        $lines[]=''; $lines[]='## Categorías'; $lines[]='';
        foreach (self::categories() as $name=>$c) { $lines[]='- `'.$name.'`: `'.implode('`, `',$c['suites']).'`'; }
        array_push($lines,'','## Ejemplos','','```bash','php runTest.php --suite back-php',
            'php runTest.php --group all --list','php runTest.php --category smoke',
            'php runTest.php --suite back-php --test test/back/auth/login.test.php','```','',
            '## Contrato completo','','```bash','php scripts/contract.php --json',
            'php scripts/contract.php validate --json','php scripts/contract.php validate-selector suite back-php --json',
            'php scripts/contract.php check-doc docs/CONTRACT_REGISTRY.md',
            'php tests/framework/test_contract_registry.php','```','');
        return implode(PHP_EOL, $lines);
    }

    private static function normalizeKind(string $kind): string
    {
        $kind = strtolower(trim($kind));
        if (!in_array($kind, self::selectorKinds(), true)) {
            throw new InvalidArgumentException('selector kind inválido: '.$kind.'. Valores: '.implode('|', self::selectorKinds()));
        }
        return $kind;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) { return $value; }
        if (!array_is_list($value)) { ksort($value); }
        foreach ($value as $key=>$child) { $value[$key]=self::canonicalize($child); }
        return $value;
    }

    /** @param array<string,mixed> $value */
    private static function canonicalJson(array $value): string
    {
        return json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
}
