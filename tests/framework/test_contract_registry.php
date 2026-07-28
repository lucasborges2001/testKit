<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/php/common/Env.php';
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
$expectedSuites = ['back_php', 'back_python', 'front_php', 'front_js', 'infra_php', 'migration_contract', 'reference_contract', 'sql_observability'];
$assert(ContractRegistry::suiteIds() === $expectedSuites, 'suite ids differ from canonical order');

foreach (ContractRegistry::publicDefinitions() as $name => $definition) {
    $assert(TargetResolver::resolve($name) === array_values((array)$definition['suites']), "resolver drift for {$name}");
}
foreach (ContractRegistry::aliases() as $alias => $canonical) {
    $definition = ContractRegistry::definition($alias);
    $assert(is_array($definition) && ($definition['deprecated'] ?? false) === true, "alias {$alias} must be deprecated");
    $assert(ContractRegistry::canonicalName($alias) === $canonical, "alias {$alias} canonical mismatch");
}

putenv('TEST_CATEGORY');
unset($_ENV['TEST_CATEGORY'], $_SERVER['TEST_CATEGORY']);
TargetResolver::resolve('smoke');
$assert(getenv('TEST_CATEGORY') === 'smoke', 'category target must derive TEST_CATEGORY from registry');
putenv('TEST_CATEGORY');
unset($_ENV['TEST_CATEGORY'], $_SERVER['TEST_CATEGORY']);

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

$sourceChecks = [
    'core/php/bootstrap.php' => "'/config/ContractRegistry.php'",
    'runners/runTest.php' => 'ContractRegistry::renderRunHelp()',
    'scripts/inspect.php' => 'ContractRegistry::configSchemaPayload(',
    'lib/bash/doctor.sh' => 'contract_registry.sh',
    'lib/powershell/Doctor.ps1' => 'Doctor.ContractRegistry.ps1',
];
foreach ($sourceChecks as $path => $needle) {
    $contents = file_get_contents($root . '/' . $path);
    $assert(is_string($contents) && str_contains($contents, $needle), "{$path} is not registry-backed");
}

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

$invalid = proc_open([PHP_BINARY, $root . '/scripts/contract.php', 'validate-target', '__unknown__'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $invalidPipes, $root);
if (is_resource($invalid)) {
    stream_get_contents($invalidPipes[1]);
    stream_get_contents($invalidPipes[2]);
    fclose($invalidPipes[1]);
    fclose($invalidPipes[2]);
}
$invalidExit = is_resource($invalid) ? proc_close($invalid) : -1;
$assert($invalidExit === 2, 'unknown target must be rejected with exit 2');

if ($errors !== []) {
    fwrite(STDERR, "Contract registry tests failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "OK contract registry parity\n";
