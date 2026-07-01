<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Config\SuiteContractRegistry;
use Testkit\Core\Discovery\TestTagger;
use Testkit\Core\Suites\TargetResolver;

$errors = [];

$assert = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};

$assert(TargetResolver::resolve('infra') === ['infra_php'], 'infra target must resolve to infra_php');
$assert(TargetResolver::resolve('infra-php') === ['infra_php'], 'infra-php target must resolve to infra_php');
$assert(TargetResolver::resolve('http') === ['infra_php'], 'http target must resolve to infra_php');
$assert(in_array('infra_php', TargetResolver::resolve('php'), true), 'php aggregate must include infra_php');
$assert(in_array('infra_php', TargetResolver::resolve('security'), true), 'security category target must include infra_php');

$contract = SuiteContractRegistry::contractForSuite('infra_php', 'php');
$capabilities = is_array($contract['capabilities'] ?? null) ? $contract['capabilities'] : [];
$hazards = is_array($contract['hazards'] ?? null) ? $contract['hazards'] : [];

$assert(($capabilities['operational_host_suite'] ?? null) === true, 'infra_php must declare operational_host_suite');
$assert(($capabilities['supports_http_checks'] ?? null) === true, 'infra_php must support HTTP checks');
$assert(($hazards['store_bootstrap'] ?? null) === 'none', 'infra_php must not bootstrap shared store by default');
$assert(($hazards['bootstrap_mutates_store'] ?? null) === false, 'infra_php bootstrap must not mutate store');
$assert(($hazards['may_require_docker_runtime'] ?? null) === true, 'infra_php must disclose Docker runtime hazard');

$tmp = tempnam(sys_get_temp_dir(), 'tk_security_tag_');
if ($tmp !== false) {
    $securityFile = $tmp . '.security.test.php';
    rename($tmp, $securityFile);
    file_put_contents($securityFile, "<?php\n");
    $tags = TestTagger::tagsFor($securityFile);
    $assert(in_array('security', $tags, true), 'security filename token must tag test as security');
    @unlink($securityFile);
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . PHP_EOL);
    }
    exit(1);
}

echo "Infra PHP suite contract PASS\n";
