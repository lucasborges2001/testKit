<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$activeWorkflowPath = $root . '/.github/workflows/ci.yml';
$disabledWorkflowPath = $root . '/.github/workflows-disabled/ci.yml';
$workflowContractPath = $root . '/docs/contracts/ci-workflow-reference.yml';
$workflowPath = is_file($activeWorkflowPath) ? $activeWorkflowPath : $workflowContractPath;
$workflowDisabled = !is_file($activeWorkflowPath);
$docsPath = $root . '/docs/CI.md';
$windowsDocsPath = $root . '/docs/WINDOWS.md';
$dockerfilePath = $root . '/docker/Dockerfile';
$composePath = $root . '/compose.yaml';
$envExamplePath = $root . '/.env.test.example';
$gitignorePath = $root . '/.gitignore';
$sqlObservabilityFixtureEnvPath = $root . '/tests/fixtures/sql-observability/blocked-host/test/.env.test';
$seedAndTestPsPath = $root . '/scripts/seed_and_test.ps1';

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
$windowsDocs = $read($windowsDocsPath);
$dockerfile = $read($dockerfilePath);
$compose = $read($composePath);
$envExample = $read($envExamplePath);
$gitignore = $read($gitignorePath);
$sqlObservabilityFixtureEnv = $read($sqlObservabilityFixtureEnvPath);
$seedAndTestPs = $read($seedAndTestPsPath);

if ($workflowDisabled) {
    $disabledWorkflow = $read($disabledWorkflowPath);
    $assert(
        str_contains($disabledWorkflow, 'temporarily disabled')
            && str_contains($disabledWorkflow, 'workflow_dispatch')
            && str_contains($disabledWorkflow, 'if: ${{ false }}'),
        'disabled CI stub must remain explicit and non-executing'
    );
    $assert(!str_contains($disabledWorkflow, 'push:'), 'disabled CI stub must not run on push');
    $assert(!str_contains($disabledWorkflow, 'pull_request:'), 'disabled CI stub must not run on pull_request');
}

$requiredWorkflow = [
    'uses: actions/checkout@v7',
    'uses: actions/setup-node@v7',
    'node-version: "24"',
    'uses: actions/upload-artifact@v7',
    'runs-on: windows-2025',
    'TESTKIT_PROJECT_ROOT: ${{ github.workspace }}/tests/fixtures/runtime-mysql-host',
    'TESTKIT_ENV_FILE: ${{ github.workspace }}/tests/fixtures/runtime-mysql-host/.env.test',
    'TESTKIT_PROJECT_ROOT: ${{ github.workspace }}/tests/fixtures/browser',
    'TESTKIT_ENV_FILE: ${{ github.workspace }}/tests/fixtures/browser/.env.test',
    './bin/testkit doctor --full --suite back-php',
    './bin/testkit run --rm testkit php runTest.php --group all --list',
    './bin/testkit run --rm testkit php runTest.php --group all',
    'node /workspace/testkit/runners/runBrowserE2e.mjs smoke.spec.mjs',
];
foreach ($requiredWorkflow as $fragment) {
    $assert(str_contains($workflow, $fragment), 'CI contract missing normalized fragment: ' . $fragment);
}

$requiredDocs = [
    'Node `24`',
    '`windows-2025`',
    'tests/fixtures/runtime-mysql-host',
    'tests/fixtures/browser',
    './bin/testkit doctor --full --suite back-php',
    './bin/testkit run --rm testkit php runTest.php --group all --list',
    './bin/testkit run --rm testkit php runTest.php --group all',
];
foreach ($requiredDocs as $fragment) {
    $assert(str_contains($docs, $fragment), 'docs/CI.md missing normalized contract fragment: ' . $fragment);
}

$requiredWindowsDocs = [
    '.\\bin\\testkit.ps1 run --rm testkit php runTest.php --suite back-php --list',
    '.\\bin\\testkit.ps1 run --rm testkit php runTest.php --suite back-php',
    '.\\bin\\testkit.ps1 doctor --full --suite back-php',
    'TEST_STORE_DRIVER_REQUIRED',
    'TEST_STORE_DRIVER_INVALID',
    '`windows-2025`',
];
foreach ($requiredWindowsDocs as $fragment) {
    $assert(str_contains($windowsDocs, $fragment), 'docs/WINDOWS.md missing canonical fragment: ' . $fragment);
}

$assert(str_contains($dockerfile, 'ARG NODE_VERSION=24'), 'docker/Dockerfile must default to Node 24');
$assert(str_contains($dockerfile, 'ARG PLAYWRIGHT_VERSION=1.61.0'), 'docker/Dockerfile must pin Playwright 1.61.0');
$assert(str_contains($compose, 'NODE_VERSION: ${TESTKIT_NODE_VERSION:-24}'), 'compose.yaml must default TESTKIT_NODE_VERSION to 24');
$assert(str_contains($envExample, 'TESTKIT_NODE_VERSION=24'), '.env.test.example must pin TESTKIT_NODE_VERSION=24');
$assert(!str_contains($envExample, 'TESTKIT_NODE_VERSION=20'), '.env.test.example must not pin Node 20');
$assert(str_contains($sqlObservabilityFixtureEnv, 'TESTKIT_NODE_VERSION=24'), 'SQL observability fixture must pin TESTKIT_NODE_VERSION=24');
$assert(!str_contains($sqlObservabilityFixtureEnv, 'TESTKIT_NODE_VERSION=20'), 'SQL observability fixture must not pin Node 20');
$assert(str_contains($gitignore, '/tests/fixtures/browser/test-results/'), '.gitignore must ignore browser fixture runtime artifacts');

$requiredSeedAndTestFragments = [
    '[string]$Suite =',
    '[string]$Group =',
    '[string]$Category =',
    '@(\'--suite\', $Suite)',
    '@(\'--group\', $Group)',
    '@(\'--category\', $Category)',
    'Declarar exactamente uno de -Suite, -Group o -Category.',
    '& $test @SelectorArgs',
    '& $PhpBin $MetaRunner @SelectorArgs',
];
foreach ($requiredSeedAndTestFragments as $fragment) {
    $assert(str_contains($seedAndTestPs, $fragment), 'scripts/seed_and_test.ps1 missing typed-selector fragment: ' . $fragment);
}

$forbiddenSeedAndTestFragments = [
    '[string]$Target',
    '[Parameter(Position = 0)]',
    '& $test $Target',
    '& $PhpBin $MetaRunner $Target',
];
foreach ($forbiddenSeedAndTestFragments as $fragment) {
    $assert(!str_contains($seedAndTestPs, $fragment), 'scripts/seed_and_test.ps1 contains legacy positional selector surface: ' . $fragment);
}

$forbiddenWorkflowFragments = [
    'actions/checkout@v4',
    'actions/setup-node@v4',
    'actions/upload-artifact@v4',
    'node-version: "20"',
    'runs-on: windows-latest',
    'php runTest.php --list',
    'php runTest.php all',
    'doctor --full migration-contract',
    'doctor --target=',
    'TEST_TARGET=',
    'TESTKIT_TARGET_',
];
foreach ($forbiddenWorkflowFragments as $fragment) {
    $assert(!str_contains($workflow, $fragment), 'CI contract contains stale/legacy surface: ' . $fragment);
}

$forbiddenWindowsFragments = [
    'php runTest.php --list',
    'php runTest.php back-php',
    'doctor --full migration-contract',
];
foreach ($forbiddenWindowsFragments as $fragment) {
    $assert(!str_contains($windowsDocs, $fragment), 'docs/WINDOWS.md contains legacy selector surface: ' . $fragment);
}

$assert(
    !str_contains($workflow, './bin/testkit doctor --full --suite migration-contract'),
    'runtime-mysql must not use migration-contract without a snapshot fixture'
);

$assert(
    substr_count($workflow, 'php tests/framework/run.php') === 1,
    'full PHP framework self-tests must run exactly once in the CI contract framework-self-tests job'
);

$positionalRunPattern = '/runTest\.php\s+(all|back|front|public_html|back-php|back-py|back-python|python|py|front-php|front-js|php|js|smoke|perf|stress|contract|critical|security|slow|migration-contract|migration|migrations)(?=\s|\\\\|$)/m';
$assert(
    preg_match($positionalRunPattern, $workflow) !== 1,
    'CI contract contains a positional runTest.php selector'
);

if ($errors !== []) {
    fwrite(STDERR, "CI typed-selector contract failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "OK CI typed selectors\n";
