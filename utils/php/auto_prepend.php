<?php
declare(strict_types=1);

/**
 * TestKit — auto-prepend universal
 *
 * Se ejecuta ANTES de cada test PHP (back y front) via:
 *   php -d auto_prepend_file=testkit/utils/php/auto_prepend.php <testfile>
 *
 * Responsabilidades:
 * - Cargar el env declarado por DB_ENV_PATH (KEY=VALUE, parse seguro) para que los tests sean autosuficientes.
 * - Aplicar defaults deterministas (APP_ENV, APP_DEBUG, mt_srand, timezone opcional).
 * - Resolver paths configurables (TK_BACK_DIR / TK_PUBLIC_DIR) y exponer helpers.
 * - Bootstrapping configurable por proyecto (autoload/boot file), sin hardcodes.
 * - (Opcional) DB per-worker (TEST_DB_STRATEGY=per_worker + TEST_WORKER_ID).
 * - (Opcional) DB clean por test (TEST_DB_STRATEGY=clean + flags).
 * - (Opcional) Coverage por proceso (TEST_COVERAGE=1 + Xdebug).
 */

require_once __DIR__ . '/../../core/php/store/bootstrap.php';
require_once __DIR__ . '/../../core/php/coverage/CoverageFilter.php';

// --- helpers internos --------------------------------------------------------

/** @return string */
function tk__env(string $k, string $default = ''): string {
  $v = getenv($k);
  if ($v === false) return $default;
  $v = (string)$v;
  return $v === '' ? $default : $v;
}

/** @return string absolute path */
function tk__abs_path(string $repoRoot, string $p): string {
  $p = trim($p);
  if ($p === '') return '';
  if (str_starts_with($p, '/') || preg_match('/^[A-Za-z]:\\\\/', $p) === 1) return $p;
  return rtrim($repoRoot, '/\\') . DIRECTORY_SEPARATOR . ltrim($p, '/\\');
}

/** Ajusta DSN mysql:...;dbname=XXX;... -> dbname=<new> */
function tk__dsn_set_dbname(string $dsn, string $dbName): string {
  if ($dsn === '') return $dsn;
  $out = preg_replace('/(dbname=)[^;]+/i', '${1}' . $dbName, $dsn, 1);
  return is_string($out) ? $out : $dsn;
}

// --- repo + env -------------------------------------------------------------

$repoRoot = tk__env('TK_REPO_ROOT', '');
if ($repoRoot === '') {
  $repoRoot = rtrim((string)(getenv('TK_REPO_ROOT') ?: dirname(dirname(dirname(__DIR__)))), '/\\');
}
$repoRoot = rtrim($repoRoot, '/\\');
putenv('TK_REPO_ROOT=' . $repoRoot);

\Testkit\Core\Common\ProjectEnv::hydrateCurrentProcess(
  $repoRoot,
  tk__env('TK_ENV_OVERRIDE', '0') === '1'
);

if (tk__env('APP_ENV', '') === '') putenv('APP_ENV=test');
if (tk__env('APP_DEBUG', '') === '') putenv('APP_DEBUG=1');

$seed = tk__env('TEST_RAND_SEED', '');
if ($seed !== '') {
  $s = (int)$seed;
  if ($s < 0) $s = -$s;
  @mt_srand($s);
}

$tz = tk__env('TEST_TZ', '');
if ($tz !== '') {
  @date_default_timezone_set($tz);
}

$backDir = tk__env('TK_BACK_DIR', 'back');
$publicDir = tk__env('TK_PUBLIC_DIR', 'public_html');
putenv('TK_BACK_DIR=' . $backDir);
putenv('TK_PUBLIC_DIR=' . $publicDir);

// --- DB strategy (opcional) -------------------------------------------------

$strategy = strtolower(tk__env('TEST_DB_STRATEGY', 'shared'));
if (!in_array($strategy, ['shared','clean','per_worker'], true)) {
  $strategy = 'shared';
  putenv('TEST_DB_STRATEGY=shared');
}

if ($strategy === 'per_worker' && tk__env('TEST_DB_WORKER_APPLIED', '0') !== '1') {
  $wid = (int)tk__env('TEST_WORKER_ID', '1');
  if ($wid <= 0) $wid = 1;

  $fmt = tk__env('TEST_DB_WORKER_SUFFIX_FORMAT', '_w%02d');
  if (!preg_match('/^[A-Za-z0-9_%._-]+$/', $fmt)) {
    $fmt = '_w%02d';
  }
  $suffix = @sprintf($fmt, $wid);
  if (!is_string($suffix) || $suffix === '') $suffix = '_w' . $wid;
  if (!preg_match('/^[A-Za-z0-9._-]+$/', $suffix)) $suffix = '_w' . $wid;

  $baseMy = tk__env('TEST_MYSQL_DB', tk__env('DB_NAME', ''));
  if ($baseMy !== '') {
    $db = $baseMy . $suffix;
    putenv('TEST_MYSQL_DB=' . $db);
    if (tk__env('DB_NAME', '') !== '') putenv('DB_NAME=' . $db);

    $dsn = tk__env('TEST_DB_DSN', '');
    if (stripos($dsn, 'mysql:') === 0) {
      putenv('TEST_DB_DSN=' . tk__dsn_set_dbname($dsn, $db));
    }
  }

  $basePg = tk__env('TEST_PG_DB', tk__env('PG_DB', ''));
  if ($basePg !== '') {
    $db = $basePg . $suffix;
    putenv('TEST_PG_DB=' . $db);
    if (tk__env('PG_DB', '') !== '') putenv('PG_DB=' . $db);
  }

  putenv('TEST_DB_WORKER_APPLIED=1');
}

// --- Bootstrapping (configurable por proyecto) ------------------------------

$requireBootstrap = tk__env('TK_REQUIRE_BOOTSTRAP', '0') === '1';
$suite = tk__env('TEST_SUITE', '');

/** @return void */
function tk__require_if_exists(string $path, bool $required, string $label): void {
  if ($path === '') {
    if ($required) throw new RuntimeException("Falta {$label} (ruta vacía)");
    return;
  }
  if (!is_file($path)) {
    if ($required) throw new RuntimeException("Falta {$label}: {$path}");
    return;
  }
  require_once $path;
}

try {
  if ($suite === 'back') {
    $autoloadRel = tk__env('TK_BACK_AUTOLOAD', $backDir . '/vendor/autoload.php');
    $autoloadAbs = tk__abs_path($repoRoot, $autoloadRel);
    tk__require_if_exists($autoloadAbs, false, 'TK_BACK_AUTOLOAD');

    $bootRel = tk__env('TK_BACK_BOOTSTRAP', '');
    $bootAbs = $bootRel !== '' ? tk__abs_path($repoRoot, $bootRel) : '';
    tk__require_if_exists($bootAbs, $requireBootstrap && $bootRel !== '', 'TK_BACK_BOOTSTRAP');
  }

  if ($suite === 'front_php') {
    $autoloadRel = tk__env('TK_PUBLIC_AUTOLOAD', $publicDir . '/vendor/autoload.php');
    $autoloadAbs = tk__abs_path($repoRoot, $autoloadRel);
    tk__require_if_exists($autoloadAbs, false, 'TK_PUBLIC_AUTOLOAD');

    $bootRel = tk__env('TK_PUBLIC_BOOTSTRAP', '');
    $bootAbs = $bootRel !== '' ? tk__abs_path($repoRoot, $bootRel) : '';
    tk__require_if_exists($bootAbs, $requireBootstrap && $bootRel !== '', 'TK_PUBLIC_BOOTSTRAP');
  }
} catch (Throwable $e) {
  fwrite(STDERR, "BOOTSTRAP ERROR: " . $e->getMessage() . "\n");
  if ($requireBootstrap) {
    exit(defined('PVT_EXIT_ERROR') ? PVT_EXIT_ERROR : 3);
  }
}

// --- DB clean (opcional; explícito) -----------------------------------------

/** @return void */
function tk__store_clean_if_enabled(): void {
  $strategy = strtolower((string)(getenv('TEST_DB_STRATEGY') ?: 'shared'));
  if ($strategy !== 'clean') return;

  $each = (getenv('TEST_DB_CLEAN_EACH') ?: '0') === '1';
  $always = (getenv('TEST_DB_CLEAN_ALWAYS') ?: '0') === '1';
  if (!$each && !$always) return;

  $file = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
  $file = str_replace('\\', '/', $file);

  $isIntegration = str_contains($file, '/integration/');
  $isE2e = str_contains($file, '/e2e/');
  if (!$always && !$isIntegration && !$isE2e) return;

  try {
    $driver = \Testkit\Core\Store\StoreRegistry::detectDriver();
    if ($driver !== 'none') {
      \Testkit\Core\Store\StoreMaintenance::clean($driver);
    }
  } catch (Throwable $e) {
    fwrite(STDERR, "DB_CLEAN WARN: " . $e->getMessage() . "\n");
  }
}

tk__store_clean_if_enabled();

// --- Coverage por proceso (opcional) ----------------------------------------

if ((getenv('TEST_COVERAGE') ?: '0') === '1' && function_exists('xdebug_start_code_coverage')) {
  /** @phpstan-ignore-next-line */
  xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);

  register_shutdown_function(function () use ($repoRoot) {
    if (!function_exists('xdebug_get_code_coverage')) return;

    $outFile = getenv('TEST_COVERAGE_FILE') ?: '';
    if ($outFile === '') return;

    $raw = xdebug_get_code_coverage();
    $filtered = [];
    $sourceDirs = \Testkit\Core\Coverage\CoverageFilter::sourceDirsFromEnv();
    $excludeDirs = \Testkit\Core\Coverage\CoverageFilter::excludeDirsFromEnv();

    foreach ($raw as $file => $lines) {
      $fileNorm = str_replace('\\', '/', (string)$file);
      if (!\Testkit\Core\Coverage\CoverageFilter::shouldInclude($fileNorm, $repoRoot, $sourceDirs, $excludeDirs)) continue;
      $filtered[$fileNorm] = $lines;
    }

    @mkdir(dirname($outFile), 0777, true);
    file_put_contents($outFile, json_encode($filtered));
  });
}

// --- Helpers públicos para tests --------------------------------------------

/** @return string */
function tk_repo_root(): string { return (string)(getenv('TK_REPO_ROOT') ?: dirname(__DIR__, 3)); }
/** @return string */
function tk_back_dir(): string { return (string)(getenv('TK_BACK_DIR') ?: 'back'); }
/** @return string */
function tk_public_dir(): string { return (string)(getenv('TK_PUBLIC_DIR') ?: 'public_html'); }

/** @return int unix timestamp */
function tk_now(): int {
  $s = getenv('TEST_NOW');
  if ($s !== false && (string)$s !== '') {
    $t = strtotime((string)$s);
    if ($t !== false) return (int)$t;
  }
  return time();
}
