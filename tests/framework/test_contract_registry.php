<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/php/config/ContractRegistry.php';
require_once $root . '/core/php/config/SuiteContractRegistry.php';
require_once $root . '/core/php/suites/TargetResolver.php';

use Testkit\Core\Config\ContractRegistry;
use Testkit\Core\Config\SuiteContractRegistry;
use Testkit\Core\Suites\TargetResolver;

$errors = [];
$assert = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};

$assert(ContractRegistry::validate() === [], 'registry validation must pass');
$assert(ContractRegistry::SCHEMA_VERSION === 2, 'registry schema must be v2');
$expectedSuites = [
    'back_php', 'back_python', 'front_php', 'front_js', 'infra_php',
    'migration_contract', 'reference_contract', 'sql_observability',
];
$assert(array_keys(ContractRegistry::suites()) === $expectedSuites, 'suite ids differ from canonical order');
$assert(ContractRegistry::selectorKinds() === ['suite', 'group', 'category'], 'selector kinds drift');
$assert(!isset(ContractRegistry::groups()['public_html']), 'legacy public_html group must be removed');

foreach (ContractRegistry::selectorDefinitions() as $kind => $definitions) {
    foreach ($definitions as $name => $definition) {
        $assert(
            TargetResolver::resolveTyped($kind, $name) === array_values((array)$definition['suites']),
            "resolver drift for {$kind}:{$name}"
        );
    }
}

foreach (['back-py', 'python', 'py', 'http', 'migration', 'migrations', 'references', 'php-references'] as $legacy) {
    foreach (ContractRegistry::selectorKinds() as $kind) {
        $assert(ContractRegistry::definition($kind, $legacy) === null, "legacy alias still accepted: {$kind}:{$legacy}");
    }
}

foreach (ContractRegistry::suites() as $suiteId => $suite) {
    $adapter = SuiteContractRegistry::contractForSuite($suiteId, (string)$suite['language']);
    $canonical = ContractRegistry::suiteContract($suiteId, (string)$suite['language']);
    $assert($adapter['contract_version'] === $canonical['contract_version'], "contract version drift for {$suiteId}");
    $assert($adapter['capabilities'] === $canonical['capabilities'], "capability drift for {$suiteId}");
    $assert($adapter['hazards'] === $canonical['hazards'], "hazard drift for {$suiteId}");
}

$doc = $root . '/docs/CONTRACT_REGISTRY.md';
$assert(is_file($doc), 'generated registry documentation is missing');
$assert((string)file_get_contents($doc) === ContractRegistry::renderMarkdown(), 'generated registry documentation drift');

$command = [PHP_BINARY, $root . '/scripts/contract.php', 'validate', '--json'];
$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
$stdout = is_resource($process) ? (stream_get_contents($pipes[1]) ?: '') : '';
$stderr = is_resource($process) ? (stream_get_contents($pipes[2]) ?: '') : '';
if (is_resource($process)) {
    fclose($pipes[1]);
    fclose($pipes[2]);
}
$exit = is_resource($process) ? proc_close($process) : -1;
$payload = json_decode($stdout, true);
$assert($exit === 0 && is_array($payload) && ($payload['ok'] ?? false) === true, "contract validate CLI failed: {$stderr}");

$invalid = proc_open(
    [PHP_BINARY, $root . '/scripts/contract.php', 'validate-selector', 'suite', '__unknown__'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $invalidPipes,
    $root
);
if (is_resource($invalid)) {
    stream_get_contents($invalidPipes[1]);
    stream_get_contents($invalidPipes[2]);
    fclose($invalidPipes[1]);
    fclose($invalidPipes[2]);
}
$invalidExit = is_resource($invalid) ? proc_close($invalid) : -1;
$assert($invalidExit === 2, 'unknown typed selector must be rejected with exit 2');

if ($errors !== []) {
    fwrite(STDERR, "Contract registry tests failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "OK contract registry typed selectors\n";
