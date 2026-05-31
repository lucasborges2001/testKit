<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/core/php/bootstrap.php';

use Testkit\Core\Discovery\TestDiscovery;
use Testkit\Core\Discovery\TestRootResolver;

function framework_discovery_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function framework_discovery_write(string $root, string $rel): void
{
    $path = $root . '/' . $rel;
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, "<?php\ndeclare(strict_types=1);\necho 'ok';\n");
}

function framework_discovery_config(array $overrides = []): array
{
    return array_replace([
        'scope' => 'all',
        'category' => 'all',
        'match' => '',
        'metadata_lines' => 60,
        'tags_from_filename' => true,
        'module_level' => 2,
        'tag_map' => '',
    ], $overrides);
}

$oldRepoRoot = getenv('TK_REPO_ROOT');
$repo = sys_get_temp_dir() . '/testkit_discovery_' . bin2hex(random_bytes(4));
@mkdir($repo, 0777, true);
putenv('TK_REPO_ROOT=' . $repo);
$_ENV['TK_REPO_ROOT'] = $repo;
$_SERVER['TK_REPO_ROOT'] = $repo;

try {
    framework_discovery_write($repo, 'test/back/legacy.test.php');
    framework_discovery_write($repo, 'test/back/legacy_smoke.php');
    framework_discovery_write($repo, 'test/smoke/host_smoke.php');
    framework_discovery_write($repo, 'test/integration/mercadopago/host_integration.php');
    framework_discovery_write($repo, 'submodules/MercadoPago/test/smoke/mercadopago_health_smoke.php');
    framework_discovery_write($repo, 'submodules/MercadoPago/test/integration/mercadopago_api_integration.php');
    framework_discovery_write($repo, 'submodules/Base/tests/smoke/bootstrap_smoke.php');
    framework_discovery_write($repo, 'submodules/wemobFirmware/test/smoke/firmware_smoke.php');
    framework_discovery_write($repo, 'submodules/MercadoPago/test/vendor/bad_smoke.php');
    framework_discovery_write($repo, 'submodules/MercadoPago/test/_out/bad_integration.php');

    $legacy = TestDiscovery::discover($repo . '/test/back', ['.test.php'], framework_discovery_config());
    framework_discovery_assert(count($legacy) === 1, 'legacy debe descubrir solo *.test.php');
    framework_discovery_assert($legacy[0]['rel'] === 'test/back/legacy.test.php', 'legacy rel inesperado');

    $resolved = TestRootResolver::resolve($repo, ['test', 'submodules/*/test', 'submodules/*/tests'], ['submodules/wemobFirmware/test']);
    $multi = TestDiscovery::discoverMany(
        $resolved['roots'],
        ['*.test.php', '*_smoke.php', '*_integration.php'],
        framework_discovery_config(),
        ['exclude_patterns' => ['*/vendor/*', '*/_out/*']]
    );
    $rels = array_map(static fn(array $test): string => (string)$test['rel'], $multi);

    foreach ([
        'test/back/legacy.test.php',
        'test/back/legacy_smoke.php',
        'test/smoke/host_smoke.php',
        'test/integration/mercadopago/host_integration.php',
        'submodules/MercadoPago/test/smoke/mercadopago_health_smoke.php',
        'submodules/MercadoPago/test/integration/mercadopago_api_integration.php',
        'submodules/Base/tests/smoke/bootstrap_smoke.php',
    ] as $expected) {
        framework_discovery_assert(in_array($expected, $rels, true), 'multi-root no descubrió: ' . $expected);
    }

    framework_discovery_assert(!in_array('submodules/wemobFirmware/test/smoke/firmware_smoke.php', $rels, true), 'exclude root no excluyó wemobFirmware');
    framework_discovery_assert(!in_array('submodules/MercadoPago/test/vendor/bad_smoke.php', $rels, true), 'exclude pattern no excluyó vendor');
    framework_discovery_assert(!in_array('submodules/MercadoPago/test/_out/bad_integration.php', $rels, true), 'exclude pattern no excluyó _out');

    $mpOnly = TestDiscovery::discoverMany($resolved['roots'], ['*_smoke.php', '*_integration.php'], framework_discovery_config(['match' => 'mercadopago']));
    framework_discovery_assert($mpOnly !== [], 'TEST_MATCH=mercadopago no seleccionó tests');
    foreach ($mpOnly as $test) {
        framework_discovery_assert(stripos((string)$test['rel'], 'mercadopago') !== false, 'TEST_MATCH filtró un path no mercadopago');
    }



    $mpScope = TestDiscovery::discoverMany(
        $resolved['roots'],
        ['*_smoke.php', '*_integration.php'],
        framework_discovery_config(['scope' => 'mercadopago'])
    );
    framework_discovery_assert($mpScope !== [], 'TEST_SCOPE=mercadopago no seleccionó tests');
    foreach ($mpScope as $test) {
        framework_discovery_assert(stripos((string)$test['rel'], 'mercadopago') !== false || in_array('mercadopago', $test['tags'], true), 'TEST_SCOPE filtró un path/tag no mercadopago');
    }

    $smoke = TestDiscovery::discoverMany($resolved['roots'], ['*_smoke.php', '*_integration.php'], framework_discovery_config(['category' => 'smoke']));
    framework_discovery_assert($smoke !== [], 'TEST_CATEGORY=smoke no seleccionó tests');
    foreach ($smoke as $test) {
        framework_discovery_assert(in_array('smoke', $test['tags'], true), 'smoke sin tag smoke');
    }

    $integration = TestDiscovery::discoverMany($resolved['roots'], ['*_smoke.php', '*_integration.php'], framework_discovery_config(['category' => 'integration']));
    framework_discovery_assert($integration !== [], 'TEST_CATEGORY=integration no seleccionó tests');
    foreach ($integration as $test) {
        framework_discovery_assert(in_array('integration', $test['tags'], true), 'integration sin tag integration');
    }

    foreach ([['../outside'], ['/tmp/outside']] as $badRoots) {
        $thrown = false;
        try {
            TestRootResolver::resolve($repo, $badRoots);
        } catch (InvalidArgumentException) {
            $thrown = true;
        }
        framework_discovery_assert($thrown, 'security root no fue rechazado: ' . implode(',', $badRoots));
    }
} finally {
    if (is_string($oldRepoRoot) && $oldRepoRoot !== '') {
        putenv('TK_REPO_ROOT=' . $oldRepoRoot);
    } else {
        putenv('TK_REPO_ROOT');
    }
}

echo "OK discovery multi-root framework\n";
