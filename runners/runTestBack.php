<?php
declare(strict_types=1);

/**
 * Runner de tests PHP (BACK).
 *
 * - Descubre *.test.php dentro de test/back/
 * - Filtra por scope (/unit/, /integration/, /e2e/)
 * - Corre cada test en proceso separado
 * - Soporta paralelismo por archivos (TEST_JOBS)
 *
 * ENV:
 *   TEST_SCOPE=unit|integration|e2e|all
 *   TEST_FAIL_FAST=1|0
 *   TEST_MATCH=<substring>
 *   TEST_LIST=1                  (lista tests y sale)
 *
 *   TEST_JOBS=N                  (default 1)
 *   TEST_DB_STRATEGY=shared|clean|per_worker
 *   TEST_DB_WORKER_SUFFIX_FORMAT=_w%02d
 *
 *   TEST_COVERAGE=1|0
 *   TEST_COVERAGE_FORMAT=lcov|json|both
 *   TEST_COVERAGE_DIR=<path>
 */

$testkitRoot = rtrim((string)(getenv('TESTKIT_ROOT') ?: dirname(__DIR__)), '/\\');
$repoRoot    = rtrim((string)(getenv('TK_REPO_ROOT') ?: (getenv('TESTKIT_PROJECT_ROOT') ?: dirname($testkitRoot))), '/\\');
$testsRoot   = $repoRoot . '/test/back';
$testsDir    = is_dir($testsRoot . '/tests') ? ($testsRoot . '/tests') : $testsRoot;
putenv('TESTKIT_ROOT=' . $testkitRoot);
putenv('TK_REPO_ROOT=' . $repoRoot);

// utils (contrato + UI)
$constPath = $testkitRoot . '/utils/constants.php';
$uiPath    = $testkitRoot . '/utils/php/ui.php';
if (is_file($constPath)) require_once $constPath;
if (is_file($uiPath)) require_once $uiPath;

$scope    = strtolower(getenv('TEST_SCOPE') ?: (defined('TEST_SCOPE_DEFAULT') ? TEST_SCOPE_DEFAULT : 'all'));
$failFast = (getenv('TEST_FAIL_FAST') ?: '1') === '1';
$match    = getenv('TEST_MATCH') ?: '';
$listOnly = (getenv('TEST_LIST') ?: '0') === '1';

$jobs = max(1, (int)(getenv('TEST_JOBS') ?: 1));

$validScopes = ['unit' => true, 'integration' => true, 'e2e' => true, 'all' => true];
if (!isset($validScopes[$scope])) {
  fwrite(STDERR, "TEST_SCOPE inválido: {$scope}. Valores: unit|integration|e2e|all\n");
  exit(defined('PVT_EXIT_ERROR') ? PVT_EXIT_ERROR : 3);
}

$coverage       = (getenv('TEST_COVERAGE') ?: '0') === '1';
$coverageFormat = getenv('TEST_COVERAGE_FORMAT') ?: 'lcov';
$coverageDir    = getenv('TEST_COVERAGE_DIR') ?: ($testkitRoot . '/_out/coverage/php_back');
$prepend        = $testkitRoot . '/utils/php/auto_prepend.php';

// env defaults
// Contract: prefer root/test/.env.test, support root/.env.test.
$envCandidatesPrimary = [$repoRoot . '/test/.env.test', $repoRoot . '/.env.test'];
$envCandidatesLegacy  = [$repoRoot . '/env.test', $repoRoot . '/.env.debug', $repoRoot . '/env.debug', $repoRoot . '/back/.env.test', $repoRoot . '/back/.env.debug', $repoRoot . '/back/.env', $repoRoot . '/.env'];
$dbEnvPath = getenv('DB_ENV_PATH');
if (!$dbEnvPath) {
  foreach ($envCandidatesPrimary as $p) {
    if (is_file($p)) { $dbEnvPath = $p; break; }
  }
  if (!$dbEnvPath) {
    foreach ($envCandidatesLegacy as $p) {
      if (is_file($p)) { $dbEnvPath = $p; break; }
    }
    if ($dbEnvPath) {
      fwrite(STDERR, "WARN: usando env legacy (no contractual): {$dbEnvPath}. Recomendado: <project>/test/.env.test o <project>/.env.test.\n");
    }
  }
}

putenv('APP_ENV=test');
putenv('APP_DEBUG=1');
putenv('TEST_SUITE=back');
if ($dbEnvPath) putenv('DB_ENV_PATH=' . $dbEnvPath);

/** @return array<int,string> */
function discover_tests(string $dir): array {
  $out = [];
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
  foreach ($it as $f) {
    /** @var SplFileInfo $f */
    if (!$f->isFile()) continue;
    if (!str_ends_with($f->getFilename(), '.test.php')) continue;
    $out[] = $f->getPathname();
  }
  sort($out);
  return $out;
}

function scope_match(string $file, string $scope): bool {
  if ($scope === 'all' || $scope === '') return true;
  $p = str_replace('\\', '/', $file);
  return str_contains($p, '/' . $scope . '/');
}

$all = discover_tests($testsDir);
$tests = [];
foreach ($all as $f) {
  if (!scope_match($f, $scope)) continue;
  if ($match !== '' && stripos($f, $match) === false) continue;
  $tests[] = $f;
}

if (function_exists('pvt_ui_banner')) {
  pvt_ui_banner('BACK / PHP');
}

echo "Running " . count($tests) . " tests BACK/PHP (scope={$scope}, failFast=" . ($failFast ? '1' : '0') . ", jobs={$jobs})\n";
if ($dbEnvPath) echo "DB_ENV_PATH: {$dbEnvPath}\n";
if ($match !== '') echo "TEST_MATCH: {$match}\n";
if ($coverage) echo "Coverage: on ({$coverageFormat}) dir={$coverageDir}\n";
echo "DB_STRATEGY: " . (getenv('TEST_DB_STRATEGY') ?: 'shared') . "\n";

if ($listOnly) {
  foreach ($tests as $file) {
    $rel = str_replace($repoRoot . '/', '', str_replace('\\', '/', $file));
    echo $rel . "\n";
  }
  exit(defined('PVT_EXIT_PASS') ? PVT_EXIT_PASS : 0);
}

if (!$tests) {
  fwrite(STDERR, "No se encontraron tests (scope={$scope}, match={$match}).\n");
  exit(defined('PVT_EXIT_ERROR') ? PVT_EXIT_ERROR : 3);
}

if (!is_file($prepend)) {
  fwrite(STDERR, "Falta auto_prepend: {$prepend}\n");
  exit(defined('PVT_EXIT_ERROR') ? PVT_EXIT_ERROR : 3);
}

if ($coverage) {
  @mkdir($coverageDir, 0777, true);
  foreach (glob($coverageDir . '/*.json') ?: [] as $f) @unlink($f);
}

/** @return array<string,string> */
function build_base_env(): array {
  $env = [];

  $g = getenv();
  if (is_array($g)) {
    foreach ($g as $k => $v) {
      if (!is_string($k) || $k === '') continue;
      if (!is_scalar($v)) continue;
      $env[$k] = (string)$v;
    }
  }

  foreach (array_merge($_SERVER, $_ENV) as $k => $v) {
    if (!is_string($k) || $k === '') continue;
    if (!is_scalar($v)) continue;
    if (!array_key_exists($k, $env)) {
      $env[$k] = (string)$v;
    }
  }

  return $env;
}

$baseEnv = build_base_env();

/**
 * @param array<string,string> $baseEnv
 * @return array{proc:resource,pipes:array{0:resource,1:resource,2:resource},rel:string,file:string,worker:int}
 */
function start_proc(string $file, string $rel, int $workerId, bool $coverage, string $coverageFormat, string $coverageDir, string $prepend, array $baseEnv, string $repoRoot, string $testkitRoot): array {
  $php = PHP_BINARY;

  $cmd = [$php];
  if ($coverage) {
    $cmd[] = '-d'; $cmd[] = 'xdebug.mode=coverage';
    $cmd[] = '-d'; $cmd[] = 'xdebug.start_with_request=no';
  }
  $cmd[] = '-d'; $cmd[] = 'auto_prepend_file=' . $prepend;
  $cmd[] = $file;

  $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $rel) ?: 'test';
  $covFile = $coverageDir . '/' . $safe . '.json';

  $env = $baseEnv;
  $env['TEST_SUITE'] = 'back';
  $env['TEST_WORKER_ID'] = (string)$workerId;
  $env['APP_ENV'] = 'test';
  $env['APP_DEBUG'] = '1';
  $env['TESTKIT_ROOT'] = $testkitRoot;
  $env['TK_REPO_ROOT'] = $repoRoot;

  if ($coverage) {
    $env['TEST_COVERAGE'] = '1';
    $env['TEST_COVERAGE_FILE'] = $covFile;
    $env['TEST_COVERAGE_FORMAT'] = $coverageFormat;
    $env['TEST_COVERAGE_DIR'] = $coverageDir;
  }

  $desc = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
  ];

  $pipes = [];
  $proc = proc_open($cmd, $desc, $pipes, $repoRoot, $env);
  if (!is_resource($proc)) {
    // create dummy pipes for uniform handling
    $p0 = fopen('php://temp', 'r');
    $p1 = fopen('php://temp', 'w+');
    $p2 = fopen('php://temp', 'w+');
    return ['proc' => $p0, 'pipes' => [0=>$p0,1=>$p1,2=>$p2], 'rel' => $rel, 'file' => $file, 'worker' => $workerId];
  }

  fclose($pipes[0]);
  stream_set_blocking($pipes[1], true);
  stream_set_blocking($pipes[2], true);

  return ['proc' => $proc, 'pipes' => $pipes, 'rel' => $rel, 'file' => $file, 'worker' => $workerId];
}

/**
 * @param array{proc:resource,pipes:array{0:resource,1:resource,2:resource},rel:string,file:string,worker:int} $job
 * @return array{code:int,stdout:string,stderr:string}
 */
function finish_proc(array $job): array {
  $stdout = stream_get_contents($job['pipes'][1]);
  $stderr = stream_get_contents($job['pipes'][2]);
  fclose($job['pipes'][1]);
  fclose($job['pipes'][2]);

  $code = 127;
  if (is_resource($job['proc'])) {
    $code = proc_close($job['proc']);
  }

  return ['code' => (int)$code, 'stdout' => (string)$stdout, 'stderr' => (string)$stderr];
}

$passed = 0;
$failed = 0;
$skipped = 0;
$t0 = microtime(true);

if ($jobs <= 1) {
  foreach ($tests as $file) {
    $rel = str_replace($repoRoot . '/', '', str_replace('\\', '/', $file));
    echo "\n";
    if (function_exists('pvt_ui_test_head')) echo pvt_ui_test_head($rel);
    else echo "==> {$rel}\n";

    $job = start_proc($file, $rel, 1, $coverage, $coverageFormat, $coverageDir, $prepend, $baseEnv, $repoRoot, $testkitRoot);
    $res = finish_proc($job);

    if ($res['stdout'] !== '') fwrite(STDOUT, $res['stdout']);
    if ($res['stderr'] !== '') fwrite(STDERR, $res['stderr']);

    $code = $res['code'];
    if ($code === 0) { $passed++; continue; }
    if ($code === (defined('PVT_EXIT_SKIP') ? PVT_EXIT_SKIP : 2) || $code === 2) { $skipped++; continue; }

    $failed++;
    fwrite(STDERR, "FAIL exit={$code} {$rel}\n");
    if ($failFast) break;
  }
} else {
  // Process pool con salida por-job (evita interleaving ilegible)
  $queue = $tests;
  $running = [];
  $freeWorkers = range(1, $jobs);
  $stopLaunch = false;

  while ($queue || $running) {
    while (!$stopLaunch && $queue && $freeWorkers) {
      $file = array_shift($queue);
      $rel = str_replace($repoRoot . '/', '', str_replace('\\', '/', $file));
      $wid = array_shift($freeWorkers);
      $running[] = start_proc($file, $rel, (int)$wid, $coverage, $coverageFormat, $coverageDir, $prepend, $baseEnv, $repoRoot, $testkitRoot);
    }

    $doneIdx = null;
    foreach ($running as $i => $job) {
      $st = proc_get_status($job['proc']);
      if (is_array($st) && ($st['running'] ?? false) === false) { $doneIdx = $i; break; }
    }

    if ($doneIdx === null) {
      usleep(20000);
      continue;
    }

    $job = $running[$doneIdx];
    array_splice($running, $doneIdx, 1);
    $res = finish_proc($job);

    echo "\n";
    if (function_exists('pvt_ui_test_head')) echo pvt_ui_test_head($job['rel']);
    else echo "==> {$job['rel']}\n";

    if ($res['stdout'] !== '') fwrite(STDOUT, $res['stdout']);
    if ($res['stderr'] !== '') fwrite(STDERR, $res['stderr']);

    $code = $res['code'];
    if ($code === 0) { $passed++; }
    elseif ($code === (defined('PVT_EXIT_SKIP') ? PVT_EXIT_SKIP : 2) || $code === 2) { $skipped++; }
    else {
      $failed++;
      fwrite(STDERR, "FAIL exit={$code} {$job['rel']}\n");
      if ($failFast) $stopLaunch = true;
    }

    $freeWorkers[] = (int)$job['worker'];
  }

  if ($stopLaunch) {
    fwrite(STDERR, "\nFAIL-FAST: abortando lanzamiento de nuevos tests (los jobs en ejecución terminaron).\n");
  }
}

$ms = (int)round((microtime(true) - $t0) * 1000);
$counts = function_exists('pvt_ui_counts') ? pvt_ui_counts($passed, $failed, $skipped) : "PASS={$passed} FAIL={$failed} SKIP={$skipped}";
echo "\nSummary: {$counts} time_ms={$ms}\n";

// Merge coverage por-test (json) -> lcov/json finales (opcional)
if ($coverage) {
  $covFiles = glob($coverageDir . '/*.json') ?: [];
  if (!$covFiles) {
    fwrite(STDERR, "Coverage: no se generaron datos. ¿Xdebug está instalado/activo en CLI?\n");
    exit(defined('PVT_EXIT_ERROR') ? PVT_EXIT_ERROR : 3);
  }

  $merged = [];
  foreach ($covFiles as $f) {
    $json = json_decode((string)file_get_contents($f), true);
    if (!is_array($json)) continue;
    foreach ($json as $path => $lines) {
      if (!is_array($lines)) continue;
      if (!isset($merged[$path])) $merged[$path] = [];
      foreach ($lines as $ln => $hits) {
        $ln = (int)$ln;
        $hits = (int)$hits;
        if ($ln <= 0) continue;
        if ($hits < 0) $hits = 0;
        $merged[$path][$ln] = ($merged[$path][$ln] ?? 0) + $hits;
      }
    }
  }
  ksort($merged);

  if ($coverageFormat === 'json' || $coverageFormat === 'both') {
    file_put_contents($coverageDir . '/coverage.json', json_encode($merged));
    echo "Coverage: wrote {$coverageDir}/coverage.json\n";
  }

  if ($coverageFormat === 'lcov' || $coverageFormat === 'both') {
    $lcovPath = $coverageDir . '/lcov.info';
    $fh = fopen($lcovPath, 'wb');
    $rootNorm = str_replace('\\', '/', $repoRoot) . '/';

    foreach ($merged as $path => $lines) {
      $path = str_replace('\\', '/', (string)$path);
      $relPath = str_starts_with($path, $rootNorm) ? substr($path, strlen($rootNorm)) : $path;
      fwrite($fh, "SF:" . $relPath . "\n");
      ksort($lines);
      foreach ($lines as $ln => $hits) {
        fwrite($fh, "DA:{$ln},{$hits}\n");
      }
      fwrite($fh, "end_of_record\n");
    }
    fclose($fh);
    echo "Coverage: wrote {$lcovPath}\n";
  }
}

if ($failed > 0) exit(defined('PVT_EXIT_FAIL') ? PVT_EXIT_FAIL : 1);
if ($passed === 0 && $skipped > 0) exit(defined('PVT_EXIT_SKIP') ? PVT_EXIT_SKIP : 2);
exit(defined('PVT_EXIT_PASS') ? PVT_EXIT_PASS : 0);
