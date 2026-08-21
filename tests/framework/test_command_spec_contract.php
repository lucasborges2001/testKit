<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/execution/CommandSpec.php';
require_once __DIR__ . '/../../core/php/reporting/agent/AgentActionPlanner.php';

use Testkit\Core\Execution\CommandSpec;
use Testkit\Core\Reporting\Agent\AgentActionPlanner;

$errors = [];

function cs_assert(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

function cs_rejected(array $spec): bool
{
    try {
        CommandSpec::normalize($spec);
        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
}

$agentMode = ['enabled' => true, 'mode' => 'agent'];
$file = 'test/back/auth/integration/login.test.php';

$rerun = AgentActionPlanner::rerunSingleFileCommandSpec('back-php', $file, $agentMode);
cs_assert(($rerun['schema'] ?? null) === CommandSpec::SCHEMA, 'rerun command_spec must use canonical schema', $errors);
cs_assert(($rerun['executor'] ?? null) === CommandSpec::EXECUTOR_PROCESS, 'rerun command_spec must use admitted process executor', $errors);
cs_assert(($rerun['argv'] ?? null) === ['php', 'runTest.php', '--suite', 'back-php', '--test', $file], 'rerun command_spec must use typed --suite/--test argv', $errors);
cs_assert(($rerun['env']['TESTKIT_MODE'] ?? null) === 'agent', 'rerun command_spec must preserve TESTKIT_MODE explicitly', $errors);
cs_assert(!array_key_exists('TEST_MATCH', (array)($rerun['env'] ?? [])), 'rerun command_spec must not use TEST_MATCH bridge', $errors);
cs_assert(($rerun['cwd'] ?? null) === '.', 'rerun command_spec cwd must be TestKit-root relative', $errors);
cs_assert(($rerun['expects_json'] ?? null) === false, 'rerun command_spec must not claim JSON output', $errors);

$inspect = AgentActionPlanner::inspectCommandSpec('latest', 'run-123', $agentMode);
cs_assert(($inspect['argv'] ?? null) === ['php', 'scripts/inspect.php', 'latest', '--run=run-123', '--json'], 'inspect command_spec must preserve exact typed argv', $errors);
cs_assert(($inspect['expects_json'] ?? null) === true, 'inspect command_spec must declare JSON result expectation', $errors);

$list = AgentActionPlanner::listTestsCommandSpec('group', 'all', ['enabled' => false]);
cs_assert(($list['argv'] ?? null) === ['php', 'runTest.php', '--group', 'all', '--list'], 'list command_spec must use explicit selector argv', $errors);
cs_assert(($list['env'] ?? null) === [], 'standard-mode command_spec must not inject agent env', $errors);

$valid = CommandSpec::create(['php', 'scripts/inspect.php', 'latest', '--json'], ['B' => '2', 'A' => '1'], 'scripts/./tools');
cs_assert(($valid['env'] ?? null) === ['A' => '1', 'B' => '2'], 'command_spec env must normalize deterministically', $errors);
cs_assert(($valid['cwd'] ?? null) === 'scripts/tools', 'command_spec cwd normalization must be deterministic', $errors);

$base = CommandSpec::create(['php', '-v']);
$invalidSpecs = [
    array_merge($base, ['schema' => 'testkit.command_spec@999']),
    array_merge($base, ['executor' => 'external-runtime']),
    array_merge($base, ['argv' => 'php -v']),
    array_merge($base, ['argv' => ['bash', '-c', 'echo unsafe']]),
    array_merge($base, ['argv' => ['pwsh', '-Command', 'Write-Host unsafe']]),
    array_merge($base, ['env' => ['BAD-NAME' => 'x']]),
    array_merge($base, ['cwd' => '../outside']),
    array_merge($base, ['cwd' => '/tmp']),
    array_merge($base, ['command' => 'php -v']),
];
foreach ($invalidSpecs as $index => $spec) {
    cs_assert(cs_rejected($spec), 'invalid command_spec fixture #' . $index . ' must be rejected', $errors);
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Command spec contract PASS\n";
exit(0);
