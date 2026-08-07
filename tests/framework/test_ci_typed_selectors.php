<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$workflowPath = $root . '/.github/workflows/ci.yml';
$docsPath = $root . '/docs/CI.md';

$errors = [];
$assert = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};

$read = static function (string $path) use ($assert): string {
    $assert(is_file($path), 'missing required file: ' . $path);
    if (!is_file($path)) {
        return '';
    }
    $content = file_get_contents($path);
    $assert(is_string($content), 'unable to read required file: ' . $path);
    return is_string($content) ? $content : '';
};

$workflow = $read($workflowPath);
$docs = $read($docsPath);

$requiredWorkflow = [
    'TESTKIT_PROJECT_ROOT: ${{ github.workspace }}/tests/fixtures/runtime-mysql-host',
    'TESTKIT_ENV_FILE: ${{ github.workspace }}/tests/fixtures/runtime-mysql-host/.env.test',
    './bin/testkit doctor --full --suite back-php',
    './bin/testkit run --rm testkit php runTest.php --group all --list',
    './bin/testkit run --rm testkit php runTest.php --group all',
];
foreach ($requiredWorkflow as $fragment) {
    $assert(str_contains($workflow, $fragment), 'CI missing canonical typed/runtime fragment: ' . $fragment);
}

$requiredDocs = [
    'tests/fixtures/runtime-mysql-host',
    './bin/testkit doctor --full --suite back-php',
    './bin/testkit run --rm testkit php runTest.php --group all --list',
    './bin/testkit run --rm testkit php runTest.php --group all',
];
foreach ($requiredDocs as $fragment) {
    $assert(str_contains($docs, $fragment), 'docs/CI.md missing canonical typed/runtime fragment: ' . $fragment);
}

$forbiddenFragments = [
    'php runTest.php --list',
    'php runTest.php all',
    'doctor --full migration-contract',
    'doctor --target=',
    'TEST_TARGET=',
    'TESTKIT_TARGET_',
];
foreach ($forbiddenFragments as $fragment) {
    $assert(!str_contains($workflow, $fragment), 'CI contains legacy selector surface: ' . $fragment);
}

$assert(
    !str_contains($workflow, './bin/testkit doctor --full --suite migration-contract'),
    'runtime-mysql must not use migration-contract without a snapshot fixture'
);

$positionalRunPattern = '/runTest\.php\s+(all|back|front|public_html|back-php|back-py|back-python|python|py|front-php|front-js|php|js|smoke|perf|stress|contract|critical|security|slow|migration-contract|migration|migrations)(?=\s|\\\\|$)/m';
$assert(
    preg_match($positionalRunPattern, $workflow) !== 1,
    'CI contains a positional runTest.php selector'
);

if ($errors !== []) {
    fwrite(STDERR, "CI typed-selector contract failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "OK CI typed selectors\n";
