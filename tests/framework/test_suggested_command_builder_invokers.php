<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Reporting\SuggestedCommandBuilder;

$errors = [];

/** @param mixed $value */
function assert_true(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

$previousInvoker = getenv('TESTKIT_INVOKER');
$previousBin = getenv('TESTKIT_INVOKER_BIN');
$previousFlags = getenv('TESTKIT_INVOKER_RUN_FLAGS');

putenv('TESTKIT_INVOKER=bin_testkit_bash');
putenv('TESTKIT_INVOKER_BIN=./bin/testkit');
putenv('TESTKIT_INVOKER_RUN_FLAGS=--rm');

assert_true(
    SuggestedCommandBuilder::rerunFiltered('back_php', 'test/back/auth/integration/example.test.php')
        === './bin/testkit run --rm testkit php runTest.php --suite back-php --test test/back/auth/integration/example.test.php',
    'bash invoker should render typed suite selector and exact --test path',
    $errors
);
assert_true(
    SuggestedCommandBuilder::listSelection('front_js')
        === './bin/testkit run --rm testkit php runTest.php --suite front-js --list',
    'list selection should use typed suite selector',
    $errors
);

putenv('TESTKIT_INVOKER=bin_testkit_powershell');
putenv('TESTKIT_INVOKER_BIN=.\\bin\\testkit.ps1');
putenv('TESTKIT_INVOKER_RUN_FLAGS=--rm');

assert_true(
    SuggestedCommandBuilder::rerunSuite('back_python')
        === '.\\bin\\testkit.ps1 run --rm testkit php runTest.php --suite back-python',
    'powershell invoker should render typed suite selector',
    $errors
);
assert_true(
    SuggestedCommandBuilder::aggregateReport()
        === '.\\bin\\testkit.ps1 run --rm testkit php scripts/report.php',
    'powershell invoker should render ps1 wrapper for aggregate report',
    $errors
);

if ($previousInvoker === false) {
    putenv('TESTKIT_INVOKER');
} else {
    putenv('TESTKIT_INVOKER=' . $previousInvoker);
}
if ($previousBin === false) {
    putenv('TESTKIT_INVOKER_BIN');
} else {
    putenv('TESTKIT_INVOKER_BIN=' . $previousBin);
}
if ($previousFlags === false) {
    putenv('TESTKIT_INVOKER_RUN_FLAGS');
} else {
    putenv('TESTKIT_INVOKER_RUN_FLAGS=' . $previousFlags);
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Suggested command builder invokers PASS\n";
