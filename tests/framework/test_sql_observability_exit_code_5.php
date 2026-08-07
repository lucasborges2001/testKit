<?php
declare(strict_types=1);

$testkit = dirname(__DIR__, 2);
$fixture = $testkit . '/tests/fixtures/sql-observability/blocked-host';
$runId = 'sqlobs_exit5_e2e_' . getmypid();
$reportRoot = $fixture . '/.testkit/reports/sql-observability/' . $runId;
$errors = [];

$assert = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};
$json = static function (string $path) use ($assert): array {
    $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    $assert(is_array($data), 'valid JSON required: ' . $path);
    return is_array($data) ? $data : [];
};
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        return;
    }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
        $item->isDir() && !$item->isLink() ? $removeTree($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
};
$dockerResources = static function (): array {
    $commands = [
        'containers' => ['docker', 'ps', '-a', '--format', '{{.Names}}'],
        'networks' => ['docker', 'network', 'ls', '--format', '{{.Name}}'],
        'volumes' => ['docker', 'volume', 'ls', '--format', '{{.Name}}'],
    ];
    $found = [];
    foreach ($commands as $type => $command) {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = is_resource($process) ? (stream_get_contents($pipes[1]) ?: '') : '';
        if (is_resource($process)) {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
        $found[$type] = array_values(array_filter(
            preg_split('/\R/', trim($output)) ?: [],
            static fn(string $name): bool => str_contains($name, 'sqlobs_blocked_gate')
        ));
    }
    return $found;
};

$removeTree($fixture . '/.testkit');
$before = $dockerResources();
$assert($before === ['containers' => [], 'networks' => [], 'volumes' => []], 'fixture Docker resources must be absent before the run');

$command = [
    PHP_BINARY,
    $testkit . '/runTest.php',
    '--suite',
    'sql-observability',
];
$process = proc_open(
    $command,
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $testkit,
    array_merge($_ENV, [
        'PATH' => (string)getenv('PATH'),
        'HOME' => (string)getenv('HOME'),
        'TESTKIT_PROJECT_ROOT' => $fixture,
        'TEST_STORE_DRIVER' => 'mysql',
        'TEST_RUN_ID' => $runId,
        'TESTKIT_SQL_OBSERVABILITY_CONFIG' => 'config/sql-observability/host.json',
        'TESTKIT_SQL_OBSERVABILITY_SCENARIO' => 'blocked-gate',
        'TESTKIT_SQL_OBSERVABILITY_REPETITIONS' => '1',
    ])
);
$stdout = is_resource($process) ? (stream_get_contents($pipes[1]) ?: '') : '';
$stderr = is_resource($process) ? (stream_get_contents($pipes[2]) ?: '') : '';
if (is_resource($process)) {
    fclose($pipes[1]);
    fclose($pipes[2]);
}
$exitCode = is_resource($process) ? proc_close($process) : -1;

$assert($exitCode === 5, 'public wrapper exit code must be exactly 5; got ' . $exitCode . "\nstdout:\n" . $stdout . "\nstderr:\n" . $stderr);
$assert(str_contains($stdout, 'sql_observability') && str_contains($stdout, 'code=5'), 'MetaRunner stdout must publish suite code 5');

$run = $reportRoot . '/scenarios/blocked-gate/run-1';
$gateRoot = $reportRoot . '/scenarios/blocked-gate/gate';
foreach ([$run . '/run-manifest.json', $run . '/mysql_profile.json', $run . '/mysql_policy.json', $run . '/mysql_comparison.json', $gateRoot . '/mysql_gate.json', $gateRoot . '/mysql_gate.junit.xml', $gateRoot . '/mysql_gate.sarif', $reportRoot . '/suite-report.json'] as $artifact) {
    $assert(is_file($artifact), 'required artifact missing: ' . $artifact);
}

$gate = $json($gateRoot . '/mysql_gate.json');
$assert(($gate['decision']['status'] ?? null) === 'blocked', 'gate status must be blocked');
$assert(($gate['decision']['exit_code'] ?? null) === 5, 'gate exit_code must be 5');
$assert(($gate['findings'] ?? []) !== [], 'gate findings must not be empty');
$assert(array_filter((array)($gate['findings'] ?? []), static fn(array $finding): bool => ($finding['category'] ?? '') === 'policy.violation' && ($finding['decision_effective'] ?? '') === 'block') !== [], 'gate must contain a blocking policy violation');

$junit = new DOMDocument();
$assert($junit->load($gateRoot . '/mysql_gate.junit.xml'), 'JUnit must be valid XML');
$suite = $junit->getElementsByTagName('testsuite')->item(0);
$assert($suite instanceof DOMElement && (int)$suite->getAttribute('tests') > 0, 'JUnit tests must be positive');
$assert($suite instanceof DOMElement && (int)$suite->getAttribute('failures') > 0, 'JUnit failures must be positive');
$assert($junit->getElementsByTagName('testcase')->length > 0, 'JUnit testcase must be identifiable');

$sarif = $json($gateRoot . '/mysql_gate.sarif');
$results = (array)($sarif['runs'][0]['results'] ?? []);
$assert(($sarif['version'] ?? null) === '2.1.0', 'SARIF version must be 2.1.0');
$assert(count((array)($sarif['runs'] ?? [])) === 1 && $results !== [], 'SARIF must contain one run and results');
$assert(array_filter($results, static fn(array $result): bool => ($result['ruleId'] ?? '') !== '' && ($result['level'] ?? '') === 'error') !== [], 'SARIF must contain an error result with ruleId');

$suiteReport = $json($reportRoot . '/suite-report.json');
$canonicalReport = $json($reportRoot . '/sql_observability_latest.json');
$metaReport = $json($reportRoot . '/meta_latest.json');
$assert(($suiteReport['suite_id'] ?? null) === 'sql_observability' && ($suiteReport['process_exit_code'] ?? null) === 5, 'suite report must publish process_exit_code=5');
$assert(($canonicalReport['process_exit_code'] ?? null) === 5 && ($canonicalReport['suite_status'] ?? null) !== 'passed', 'canonical suite report must be unsuccessful with process_exit_code=5');
$assert(($metaReport['suites'][0]['exit_code'] ?? null) === 5, 'MetaRunner report must preserve suite exit code 5');

$after = $dockerResources();
$assert($after === ['containers' => [], 'networks' => [], 'volumes' => []], 'fixture Docker resources must be absent after the run: ' . json_encode($after));
$runtimeCredentials = glob($fixture . '/.testkit/reports/sql-observability/.runtime/*.env') ?: [];
$assert($runtimeCredentials === [], 'temporary credential files must be removed');

$removeTree($fixture . '/.testkit');
if ($errors !== []) {
    fwrite(STDERR, "SQL observability exit-code-5 tests failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}
echo "OK SQL observability public exit code 5\n";
