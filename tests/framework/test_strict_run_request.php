<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/php/config/ContractRegistry.php';
require_once $root . '/core/php/config/RunRequest.php';

use Testkit\Core\Config\RunRequest;

$errors = [];
$assert = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};
$rejects = static function (array $argv, string $message) use ($assert): void {
    try {
        RunRequest::parse($argv);
        $assert(false, $message);
    } catch (InvalidArgumentException) {
        $assert(true, $message);
    }
};

$request = RunRequest::parse([
    'runTest.php', '--suite', 'back-php', '--list',
    '--test', 'test/back/auth/login.test.php',
    '--test=test/back/auth/logout.test.php',
]);
$assert($request->selectorKind === 'suite', 'suite kind not parsed');
$assert($request->selectorName === 'back-php', 'suite name not parsed');
$assert($request->listOnly, '--list not parsed');
$assert($request->tests === ['test/back/auth/login.test.php', 'test/back/auth/logout.test.php'], 'exact tests not parsed');

$group = RunRequest::parse(['runTest.php', '--group=all']);
$assert($group->selectorKind === 'group' && $group->selectorName === 'all', 'group selector not parsed');
$category = RunRequest::parse(['runTest.php', '--category', 'smoke']);
$assert($category->selectorKind === 'category' && $category->selectorName === 'smoke', 'category selector not parsed');

$rejects(['runTest.php', 'back-php'], 'positional target must be rejected');
$rejects(['runTest.php', '--suite', 'back-py'], 'suite alias must be rejected');
$rejects(['runTest.php', '--group', 'public_html'], 'legacy public_html group must be rejected');
$rejects(['runTest.php', '--suite', 'back-php', '--group', 'all'], 'multiple selectors must be rejected');
$rejects(['runTest.php', '--suite', 'back-php', '--test', '../escape.php'], 'path traversal must be rejected');
$rejects(['runTest.php', '--suite', 'back-php', '--test', '/tmp/test.php'], 'absolute test path must be rejected');
$rejects([
    'runTest.php', '--suite', 'back-php', '--test', 'test/a.php', '--selection-file', 'test/list.txt',
], 'selection sources must be mutually exclusive');

putenv('TEST_TARGET=all');
try {
    RunRequest::assertNoLegacyTargetEnvironment();
    $assert(false, 'TEST_TARGET must be rejected');
} catch (InvalidArgumentException) {
    $assert(true, 'TEST_TARGET must be rejected');
}
putenv('TEST_TARGET');
putenv('TESTKIT_TARGET_CUSTOM=back_php');
try {
    RunRequest::assertNoLegacyTargetEnvironment();
    $assert(false, 'TESTKIT_TARGET_* must be rejected');
} catch (InvalidArgumentException) {
    $assert(true, 'TESTKIT_TARGET_* must be rejected');
}
putenv('TESTKIT_TARGET_CUSTOM');

if ($errors !== []) {
    fwrite(STDERR, "Strict run request tests failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "OK strict run request\n";
