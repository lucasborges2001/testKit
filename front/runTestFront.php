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

$testsRoot = __DIR__;                 // .../test/front
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
$coverageDir    = getenv('TEST_COVERAGE_DIR') ?: ($repoRoot . '/test/_out/coverage/php_public');
$prepend        = $repoRoot . '/test/utils/php/coverage_prepend.php';

// env defaults (similar al runner principal)
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

$all = discover_tests($testsRoot);
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

  if ($coverage) {
    if (!is_file($prepend)) {
      fwrite(STDERR, "coverage_prepend.php no existe en {$prepend}\n");
      exit(defined('PVT_EXIT_ERROR') ? PVT_EXIT_ERROR : 3);
    }

    // Xdebug 3: preferimos que el usuario setee XDEBUG_MODE=coverage, pero igual forzamos.
    $cmd[] = '-d';
    $cmd[] = 'xdebug.mode=coverage';

    $cmd[] = '-d';
    $cmd[] = 'auto_prepend_file=' . $prepend;

    // env para el prepend
    putenv('TEST_COVERAGE_FORMAT=' . $coverageFormat);
    putenv('TEST_COVERAGE_DIR=' . $coverageDir);
  }

  $cmd[] = $file;

  // Passthrough TTY: preserva ANSI y output live.
  $descriptors = [
    0 => STDIN,
    1 => STDOUT,
    2 => STDERR,
  ];

  $proc = proc_open($cmd, $descriptors, $pipes, $repoRoot, null);
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

if ($failed > 0) exit(defined('PVT_EXIT_FAIL') ? PVT_EXIT_FAIL : 1);
if ($passed === 0 && $skipped > 0) exit(defined('PVT_EXIT_SKIP') ? PVT_EXIT_SKIP : 2);
exit(defined('PVT_EXIT_PASS') ? PVT_EXIT_PASS : 0);
