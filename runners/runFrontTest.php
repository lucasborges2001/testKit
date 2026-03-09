<?php
declare(strict_types=1);

/**
 * Runner de tests PHP para front.

 * - Descubre *.test.php dentro de test/front/
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

$testkitRoot = rtrim((string)(getenv('TESTKIT_ROOT') ?: dirname(__DIR__)), '/\\');
$repoRoot    = rtrim((string)(getenv('TK_REPO_ROOT') ?: (getenv('TESTKIT_PROJECT_ROOT') ?: dirname($testkitRoot))), '/\\');
$testsRoot   = $repoRoot . '/test/front';
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

$validScopes = ['unit' => true, 'integration' => true, 'e2e' => true, 'all' => true];
if (!isset($validScopes[$scope])) {
  fwrite(STDERR, "TEST_SCOPE inválido: {$scope}. Valores: unit|integration|e2e|all\n");
  exit(defined('PVT_EXIT_ERROR') ? PVT_EXIT_ERROR : 3);
}

$coverage       = (getenv('TEST_COVERAGE') ?: '0') === '1';
$coverageFormat = getenv('TEST_COVERAGE_FORMAT') ?: 'lcov';
$coverageDir    = getenv('TEST_COVERAGE_DIR') ?: ($testkitRoot . '/_out/coverage/php_public');
$prepend        = $testkitRoot . '/utils/php/coverage_prepend.php';

// env defaults
// Contract: prefer root/test/.env.test, support root/.env.test.
// If neither exists and DB_ENV_PATH isn't set, we fall back to legacy candidates (warn).
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
if ($dbEnvPath) putenv('DB_ENV_PATH=' . $dbEnvPath);

function discover_tests(string $dir): array {
  $out = [];
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
  foreach ($it as $f) {
    /** @var SplFileInfo $f */
    if (!$f->isFile()) continue;
    $name = $f->getFilename();
    if (!str_ends_with($name, '.test.php')) continue;
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
  pvt_ui_banner('FRONT / PHP');
}

echo "Running " . count($tests) . " tests PHP (front) (scope={$scope}, failFast=" . ($failFast ? '1' : '0') . ")\n";
if ($dbEnvPath) echo "DB_ENV_PATH: {$dbEnvPath}\n";
if ($match !== '') echo "TEST_MATCH: {$match}\n";
if ($coverage) echo "Coverage: on ({$coverageFormat}) dir={$coverageDir}\n";

if ($listOnly) {
  foreach ($tests as $file) {
    $rel = str_replace($repoRoot . '/', '', $file);
    echo $rel . "\n";
  }
  exit(defined('PVT_EXIT_PASS') ? PVT_EXIT_PASS : 0);
}

$php = PHP_BINARY;

$passed = 0;
$failed = 0;
$skipped = 0;

foreach ($tests as $file) {
  $rel = str_replace($repoRoot . '/', '', $file);
  echo "\n";
  if (function_exists('pvt_ui_test_head')) {
    echo pvt_ui_test_head($rel);
  } else {
    echo "==> {$rel}\n";
  }

  $cmd = [$php];
  $env = null;

  if ($coverage) {
    if (!is_file($prepend)) {
      fwrite(STDERR, "coverage_prepend.php no existe en {$prepend}\n");
      exit(defined('PVT_EXIT_ERROR') ? PVT_EXIT_ERROR : 3);
    }

    // Xdebug 3: forzamos cobertura y prepend para capturar por-test
    $cmd[] = '-d';
    $cmd[] = 'xdebug.mode=coverage';
    $cmd[] = '-d';
    $cmd[] = 'xdebug.start_with_request=no';
    $cmd[] = '-d';
    $cmd[] = 'auto_prepend_file=' . $prepend;

    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $rel) ?: 'test';
    $covFile = $coverageDir . '/' . $safe . '.json';

    // Env para el prepend: preservamos env del proceso padre.
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
    if ($dbEnvPath) $env['DB_ENV_PATH'] = $dbEnvPath;
    $env['APP_ENV'] = 'test';
    $env['APP_DEBUG'] = '1';
    $env['TESTKIT_ROOT'] = $testkitRoot;
    $env['TK_REPO_ROOT'] = $repoRoot;
  }

  $cmd[] = $file;

  // Passthrough TTY: preserva ANSI y output live.
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

  if ($code === 0) {
    $passed++;
    continue;
  }

  if ($code === (defined('PVT_EXIT_SKIP') ? PVT_EXIT_SKIP : 2) || $code === 2) {
    $skipped++;
    continue;
  }

  $failed++;
  fwrite(STDERR, "FAIL exit={$code} {$rel}\n");
  if ($failFast) break;
}

$counts = function_exists('pvt_ui_counts') ? pvt_ui_counts($passed, $failed, $skipped) : "PASS={$passed} FAIL={$failed} SKIP={$skipped}";
echo "\nSummary: {$counts}\n";

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
        if (!isset($merged[$path][$ln])) $merged[$path][$ln] = 0;
        $merged[$path][$ln] += $hits;
      }
    }
  }

  // JSON merged
  if ($coverageFormat === 'json' || $coverageFormat === 'both') {
    @mkdir($coverageDir, 0777, true);
    $out = $coverageDir . '/coverage.json';
    file_put_contents($out, json_encode($merged));
    echo "Coverage: wrote {$out}\n";
  }

  // LCOV
  if ($coverageFormat === 'lcov' || $coverageFormat === 'both') {
    @mkdir($coverageDir, 0777, true);
    $lcovPath = $coverageDir . '/lcov.info';
    $fh = fopen($lcovPath, 'wb');
    foreach ($merged as $file => $lines) {
      fwrite($fh, "TN:\n");
      fwrite($fh, "SF:" . $file . "\n");
      ksort($lines);
      $lf = 0; $lh = 0;
      foreach ($lines as $ln => $hits) {
        $lf++;
        if ($hits > 0) $lh++;
        fwrite($fh, "DA:{$ln},{$hits}\n");
      }
      fwrite($fh, "LF:{$lf}\n");
      fwrite($fh, "LH:{$lh}\n");
      fwrite($fh, "end_of_record\n");
    }
    fclose($fh);
    echo "Coverage: wrote {$lcovPath}\n";
  }
}

if ($failed > 0) exit(defined('PVT_EXIT_FAIL') ? PVT_EXIT_FAIL : 1);
if ($passed === 0 && $skipped > 0) exit(defined('PVT_EXIT_SKIP') ? PVT_EXIT_SKIP : 2);
exit(defined('PVT_EXIT_PASS') ? PVT_EXIT_PASS : 0);
