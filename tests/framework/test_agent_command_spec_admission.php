<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Execution\CommandSpec;
use Testkit\Core\Reporting\AgentRunExecute;

$errors = [];

function acs_assert(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

$decision = [
    'next_action' => [
        'kind' => 'command_spec_self_test',
        'reason' => 'contract_test',
        'command' => 'this display string must never be executed',
        'command_spec' => CommandSpec::create(
            [PHP_BINARY, '-r', 'echo json_encode(["source" => "command_spec"]);'],
            [],
            '.',
            true
        ),
    ],
];

$execution = AgentRunExecute::execute($decision);
acs_assert(($execution['executed'] ?? null) === true, 'valid command_spec must execute', $errors);
acs_assert(($execution['admission']['accepted'] ?? null) === true, 'valid command_spec must pass admission', $errors);
acs_assert(($execution['result']['exit_code'] ?? null) === 0, 'valid command_spec process must preserve exit code', $errors);
acs_assert(($execution['child_payload']['source'] ?? null) === 'command_spec', 'executor must consume command_spec and decode declared JSON', $errors);
acs_assert(($execution['command_spec']['schema'] ?? null) === CommandSpec::SCHEMA, 'execution envelope must persist normalized command_spec', $errors);

$invalid = $decision;
$invalid['next_action']['command_spec']['argv'] = ['bash', '-c', 'exit 0'];
$rejected = AgentRunExecute::execute($invalid);
acs_assert(($rejected['executed'] ?? null) === false, 'rejected command_spec must not execute', $errors);
acs_assert(($rejected['admission']['accepted'] ?? null) === false, 'rejected command_spec must expose failed admission', $errors);
acs_assert(AgentRunExecute::exitCode($rejected) === 2, 'rejected command_spec must map to exit code 2', $errors);

$noAction = AgentRunExecute::execute([
    'next_action' => [
        'kind' => 'no_action',
        'reason' => 'nothing_to_do',
        'command' => null,
        'command_spec' => null,
    ],
]);
acs_assert(($noAction['executed'] ?? null) === false, 'null command_spec must remain non-executing', $errors);
acs_assert(AgentRunExecute::exitCode($noAction) === 0, 'no_action must preserve zero exit code', $errors);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Agent command spec admission PASS\n";
exit(0);
