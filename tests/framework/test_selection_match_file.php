<?php
declare(strict_types=1);

$testkitRoot = realpath(__DIR__ . '/../..');
if (!is_string($testkitRoot)) {
    fwrite(STDERR, "No se pudo resolver TESTKIT_ROOT\n");
    exit(1);
}
putenv('TESTKIT_ROOT=' . $testkitRoot);

$tmp = sys_get_temp_dir() . '/testkit_selection_' . bin2hex(random_bytes(4));
$root = $tmp;
$paths = [
    'test/front/cliente/unit/cliente_api_wiring.test.php',
    'test/front/cliente/integration/cliente_mi_cargador_tarifa_domestica_seeded_integration.test.php',
    'test/front/cliente/unit/cliente_domestic_asset_endpoint_contract.test.php',
    'test/front/cliente/unit/cliente_mi_cargador_modal_contract.test.php',
];
foreach ($paths as $path) {
    $full = $root . '/' . $path;
    @mkdir(dirname($full), 0777, true);
    file_put_contents($full, "<?php\n// tags: unit\n");
}
@mkdir($root . '/.testkit', 0777, true);
putenv('TK_REPO_ROOT=' . $root);
putenv('TESTKIT_PROJECT_ROOT=' . $root);

require_once $testkitRoot . '/core/php/bootstrap.php';

use Testkit\Core\Discovery\TestDiscovery;
use Testkit\Core\Discovery\TestSelection;

function tk_selection_config(array $overrides = []): array
{
    return array_merge([
        'scope' => 'all',
        'category' => 'all',
        'match' => '',
        'match_list' => '',
        'match_file' => '',
        'selection_match_mode' => 'exact',
        'match_list_mode' => 'exact',
        'metadata_lines' => 60,
        'tags_from_filename' => true,
        'module_level' => 2,
        'tag_map' => '',
    ], $overrides);
}

function tk_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

function tk_expect_exception(callable $fn, string $class, string $message): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        tk_assert($e instanceof $class, $message . ' Tipo recibido: ' . get_class($e));
        return;
    }

    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

function tk_rels(array $tests): array
{
    $rels = array_map(static fn(array $test): string => (string)$test['rel'], $tests);
    sort($rels);
    return $rels;
}

$testsDir = $root . '/test/front';

$legacy = TestDiscovery::discover($testsDir, ['.test.php'], tk_selection_config([
    'match' => 'cliente_api_wiring',
]));
tk_assert(tk_rels($legacy) === ['test/front/cliente/unit/cliente_api_wiring.test.php'], 'TEST_MATCH legacy debe seguir funcionando por substring.');

$list = TestDiscovery::discover($testsDir, ['.test.php'], tk_selection_config([
    'match_list' => 'test/front/cliente/unit/cliente_api_wiring.test.php, test/front/cliente/unit/cliente_mi_cargador_modal_contract.test.php',
]));
tk_assert(tk_rels($list) === [
    'test/front/cliente/unit/cliente_api_wiring.test.php',
    'test/front/cliente/unit/cliente_mi_cargador_modal_contract.test.php',
], 'TEST_MATCH_LIST debe seleccionar múltiples archivos exactos.');

$exactDefault = TestDiscovery::discover($testsDir, ['.test.php'], tk_selection_config([
    'match_list' => 'cliente_api_wiring',
]));
tk_assert(tk_rels($exactDefault) === [], 'TEST_MATCH_LIST debe ser exact por defecto, no substring amplio.');

$substring = TestDiscovery::discover($testsDir, ['.test.php'], tk_selection_config([
    'match_list' => 'cliente_api_wiring',
    'match_list_mode' => 'substring',
]));
tk_assert(tk_rels($substring) === ['test/front/cliente/unit/cliente_api_wiring.test.php'], 'TEST_MATCH_LIST_MODE=substring debe habilitar substring explícito.');

$selectionFile = $root . '/.testkit/selection.front_php.txt';
file_put_contents($selectionFile, implode("\n", [
    '# comentario',
    '',
    'test\\front\\cliente\\unit\\cliente_api_wiring.test.php',
    'test/front/cliente/integration/cliente_mi_cargador_tarifa_domestica_seeded_integration.test.php',
    'test/front/cliente/unit/no_existe.test.php',
    '',
]));
$fileConfig = tk_selection_config([
    'match_file' => '.testkit/selection.front_php.txt',
]);
$fileSelected = TestDiscovery::discover($testsDir, ['.test.php'], $fileConfig);
tk_assert(tk_rels($fileSelected) === [
    'test/front/cliente/integration/cliente_mi_cargador_tarifa_domestica_seeded_integration.test.php',
    'test/front/cliente/unit/cliente_api_wiring.test.php',
], 'TEST_MATCH_FILE debe seleccionar múltiples archivos e ignorar comentarios/líneas vacías.');

$metadata = TestSelection::fromConfig($fileConfig)->metadata(tk_rels($fileSelected));
tk_assert(($metadata['selection_source'] ?? '') === 'match_file', 'selection_source debe ser match_file.');
tk_assert(($metadata['selection_match_mode'] ?? '') === 'exact', 'selection_match_mode default debe ser exact.');
tk_assert((int)($metadata['selection_entries_count'] ?? 0) === 3, 'selection_entries_count debe contar entradas válidas.');
tk_assert(($metadata['selection_entries'] ?? []) === [
    'test/front/cliente/unit/cliente_api_wiring.test.php',
    'test/front/cliente/integration/cliente_mi_cargador_tarifa_domestica_seeded_integration.test.php',
    'test/front/cliente/unit/no_existe.test.php',
], 'selection_entries debe exponer entradas normalizadas.');
tk_assert(($metadata['selection_unmatched_entries'] ?? []) === ['test/front/cliente/unit/no_existe.test.php'], 'selection_unmatched_entries debe incluir entradas inexistentes.');
tk_assert(($metadata['selection_invalid_entries'] ?? null) === [], 'selection_invalid_entries debe existir como lista.');
tk_assert(($metadata['selection_errors'] ?? null) === [], 'selection_errors debe existir como lista.');
tk_assert(($metadata['selection_file'] ?? '') === '.testkit/selection.front_php.txt', 'selection_file debe ser repo-relative.');
tk_assert(($metadata['selection_file_exists'] ?? false) === true, 'selection_file_exists debe ser true cuando existe.');

tk_expect_exception(
    static fn() => TestDiscovery::discover($testsDir, ['.test.php'], tk_selection_config(['match_file' => '.testkit/no_such_file.txt'])),
    RuntimeException::class,
    'TEST_MATCH_FILE inexistente debe fallar explícitamente.'
);

$badFile = $root . '/.testkit/bad_selection.txt';
file_put_contents($badFile, "../outside.test.php\n");
tk_expect_exception(
    static fn() => TestDiscovery::discover($testsDir, ['.test.php'], tk_selection_config(['match_file' => '.testkit/bad_selection.txt'])),
    InvalidArgumentException::class,
    'TEST_MATCH_FILE con path traversal debe fallar.'
);

tk_expect_exception(
    static fn() => TestDiscovery::discover($testsDir, ['.test.php'], tk_selection_config(['match_list' => '/tmp/outside.test.php'])),
    InvalidArgumentException::class,
    'TEST_MATCH_LIST con ruta absoluta debe fallar.'
);

echo "[SUCCESS] selección múltiple validada\n";
exit(0);
