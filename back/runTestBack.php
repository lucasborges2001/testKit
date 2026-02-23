<?php
declare(strict_types=1);

/**
 * Runner de tests PHP (BACK).
 *
 * - Descubre *.test.php dentro de test/back/
 * - Filtra por scope (/unit/, /integration/, /e2e/)
 * - Corre cada test en proceso separado
 *
 * ENV:
 *   TEST_SCOPE=unit|integration|e2e|all
 *   TEST_FAIL_FAST=1|0
 *   TEST_MATCH=<substring>
 *   TEST_LIST=1                  (lista tests y sale)
 *
 *   TEST_COVERAGE=1|0
 *   TEST_COVERAGE_FORMAT=lcov|json|both
 *   TEST_COVERAGE_DIR=<path>
 *
 * Colores ANSI:
 *   PVT_COLOR=auto|1|0, NO_COLOR=1, FORCE_COLOR=1
 */

$testsRoot = __DIR__;                // .../test/back
$repoRoot  = dirname($testsRoot, 2);  // .../repo

// utils (contrato + UI)
$constPath = $repoRoot . '/test/utils/constants.php';
$uiPath    = $repoRoot . '/test/utils/php/ui.php';
if (is_file($constPath)) require_once $constPath;
if (is_file($uiPath)) require_once $uiPath;

$scope    = strtolower(getenv('TEST_SCOPE') ?: (defined('TEST_SCOPE_DEFAULT') ? TEST_SCOPE_DEFAULT : 'all'));
$failFast = (getenv('TEST_FAIL_FAST') ?: '0') === '1';
$match    = getenv('TEST_MATCH') ?: '';
$listOnly = (getenv('TEST_LIST') ?: '0') === '1';

$validScopes = ['unit' => true, 'integration' => true, 'e2e' => true, 'all' => true];
if (!isset($validScopes[$scope])) {
  fwrite(STDERR, "TEST_SCOPE inválido: {$scope}. Valores: unit|integration|e2e|all\n");
  exit(defined('PVT_EXIT_ERROR') ? PVT_EXIT_ERROR : 3);
}

$coverage       = (getenv('TEST_COVERAGE') ?: '0') === '1';
$coverageFormat = getenv('TEST_COVERAGE_FORMAT') ?: 'lcov';
$coverageDir    = getenv('TEST_COVERAGE_DIR') ?: ($repoRoot . '/test/_out/coverage/php_back');
$prepend        = $repoRoot . '/test/utils/php/coverage_prepend.php';

// env defaults
$envCandidates = [$repoRoot . '/env.test', $repoRoot . '/env.debug', $repoRoot . '/.env'];
$dbEnvPath = getenv('DB_ENV_PATH');
if (!$dbEnvPath) {
  foreach ($envCandidates as $p) {
    if (is_file($p)) { $dbEnvPath = $p; break; }
  }
}

putenv('APP_ENV=test');
putenv('APP_DEBUG=1');
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

$all = discover_tests($testsRoot);
$tests = [];
foreach ($all as $f) {
  if (!scope_match($f, $scope)) continue;
  if ($match !== '' && stripos($f, $match) === false) continue;
  $tests[] = $f;
}

if (function_exists('pvt_ui_banner')) {
  pvt_ui_banner('BACK / PHP');
}

echo "Running " . count($tests) . " tests BACK/PHP (scope={$scope}, failFast=" . ($failFast ? '1' : '0') . ")\n";
if ($dbEnvPath) echo "DB_ENV_PATH: {$dbEnvPath}\n";
if ($match !== '') echo "TEST_MATCH: {$match}\n";
if ($coverage) echo "Coverage: on ({$coverageFormat}) dir={$coverageDir}\n";

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

if ($coverage) {
  if (!is_file($prepend)) {
    fwrite(STDERR, "Coverage: falta {$prepend}\n");
    exit(defined('PVT_EXIT_ERROR') ? PVT_EXIT_ERROR : 3);
  }
  @mkdir($coverageDir, 0777, true);
  foreach (glob($coverageDir . '/*.json') ?: [] as $f) @unlink($f);
}

$php = PHP_BINARY;

$passed = 0;
$failed = 0;
$skipped = 0;

$t0 = microtime(true);

foreach ($tests as $file) {
  $rel = str_replace($repoRoot . '/', '', str_replace('\\', '/', $file));
  echo "\n";
  if (function_exists('pvt_ui_test_head')) echo pvt_ui_test_head($rel);
  else echo "==> {$rel}\n";

  $cmd = [$php];
  $env = null;

  if ($coverage) {
    // Xdebug 3: forzamos cobertura y prepend para capturar por-test
    $cmd[] = '-d';
    $cmd[] = 'xdebug.mode=coverage';
    $cmd[] = '-d';
    $cmd[] = 'xdebug.start_with_request=no';
    $cmd[] = '-d';
    $cmd[] = 'auto_prepend_file=' . $prepend;

    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $rel) ?: 'test';
    $covFile = $coverageDir . '/' . $safe . '.json';

    // Env para el prepend: preservamos env del proceso padre (PATH, HOME, etc.)
    // Nota: proc_open() reemplaza el env completo si pasás un array.
    $env = [];
    foreach (array_merge($_SERVER, $_ENV) as $k => $v) {
      if (!is_string($k) || $k === '') continue;
      if (!is_scalar($v)) continue;
      $env[$k] = (string)$v;
    }
    $env['TEST_COVERAGE'] = '1';
    $env['TEST_COVERAGE_FILE'] = $covFile;
    $env['TEST_COVERAGE_FORMAT'] = $coverageFormat;
    $env['TEST_COVERAGE_DIR'] = $coverageDir;

    // Propagamos también defaults del runner
    if ($dbEnvPath) $env['DB_ENV_PATH'] = $dbEnvPath;
    $env['APP_ENV'] = 'test';
    $env['APP_DEBUG'] = '1';
  }

  $cmd[] = $file;

  // Passthrough TTY: output live (preserva ANSI si el test lo usa)
  $descriptors = [
    0 => STDIN,
    1 => STDOUT,
    2 => STDERR,
  ];

  $proc = proc_open($cmd, $descriptors, $pipes, $repoRoot, $env);
  if (!is_resource($proc)) {
    fwrite(STDERR, "No se pudo ejecutar el proceso para {$rel}\n");
    $failed++;
    if ($failFast) break;
    continue;
  }

  $code = proc_close($proc);

  if ($code === 0) { $passed++; continue; }
  if ($code === (defined('PVT_EXIT_SKIP') ? PVT_EXIT_SKIP : 2) || $code === 2) { $skipped++; continue; }

  $failed++;
  fwrite(STDERR, "FAIL exit={$code} {$rel}\n");
  if ($failFast) break;
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
