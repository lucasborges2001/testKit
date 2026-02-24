<?php
declare(strict_types=1);

/**
 * Meta-runner (root): ejecuta runners separados sin mezclar discovery.
 *
 * Estructura esperada:
 * - test/back/runTestBack.php     (PHP backend)
 * - test/front/runFrontTest.php   (PHP front)
 * - test/front/runFrontTest.mjs   (JS front)
 *
 * Uso:
 *   php test/runTest.php
 *   php test/runTest.php back
 *   php test/runTest.php front
 *   php test/runTest.php front-php
 *   php test/runTest.php front-js
 *
 * Variables:
 * - TEST_TARGET=all|back|front|front-php|front-js  (si no se pasa argv)
 *
 * "Correr todo siempre":
 * - meta-runner: TEST_META_FAIL_FAST=0 (default)
 * - sub-runners: el meta-runner fuerza TEST_FAIL_FAST=0 por default
 *   (override: TEST_CHILD_FAIL_FAST=1)
 *
 * Node:
 * - NODE_BINARY=node
 * - TEST_JS_REQUIRE_NODE=1 => si falta node => FAIL (si no, SKIP)
 */

$repoRoot = dirname(__DIR__); // <repo>
$testRoot = __DIR__;          // <repo>/test

// UI + constants (si existen)
$const = $testRoot . '/utils/constants.php';
$ui    = $testRoot . '/utils/php/ui.php';
if (is_file($const)) require_once $const;
if (is_file($ui)) require_once $ui;

$EXIT_PASS = defined('PVT_EXIT_PASS') ? PVT_EXIT_PASS : 0;
$EXIT_FAIL = defined('PVT_EXIT_FAIL') ? PVT_EXIT_FAIL : 1;
$EXIT_SKIP = defined('PVT_EXIT_SKIP') ? PVT_EXIT_SKIP : 2;
$EXIT_ERR  = defined('PVT_EXIT_ERROR') ? PVT_EXIT_ERROR : 3;

$target = $argv[1] ?? (getenv('TEST_TARGET') ?: 'all');
$target = strtolower(trim((string)$target));

$VALID = ['all','back','front','public_html','front-php','front-js','php','js'];
if (!in_array($target, $VALID, true)) {
    fwrite(STDERR, "TEST_TARGET inválido: {$target}. Valores: " . implode('|', $VALID) . "\n");
    exit($EXIT_ERR);
}

// Meta-runner: por default NO corta nunca.
$metaFailFast  = (getenv('TEST_META_FAIL_FAST') ?: '0') === '1';
// Sub-runners: por default NO cortan (run-all).
$childFailFast = (getenv('TEST_CHILD_FAIL_FAST') ?: '0') === '1';
// Node: por default si falta => SKIP (para no romper stacks sin node).
$requireNode   = (getenv('TEST_JS_REQUIRE_NODE') ?: '0') === '1';

$backRunner     = $testRoot . '/back/runTestBack.php';
$frontPhpRunner = $testRoot . '/front/runFrontTest.php';
$frontJsRunner  = $testRoot . '/front/runFrontTest.mjs';

$php  = PHP_BINARY;
$node = getenv('NODE_BINARY') ?: 'node';

function banner_fallback(string $title): void {
    echo "\n" . str_repeat('=', 78) . "\n";
    echo $title . "\n";
    echo str_repeat('=', 78) . "\n\n";
}

function banner(string $title): void {
    if (function_exists('pvt_ui_banner')) pvt_ui_banner($title);
    else banner_fallback($title);
}

function run_cmd(array $cmd, string $cwd, array $env): array {
    $spec = [
        0 => STDIN,
        1 => STDOUT,
        2 => STDERR,
    ];

    $pipes = [];

    // Evita warnings ruidosos cuando el binario no existe (lo prevenimos con find_bin()).
    $proc = @proc_open($cmd, $spec, $pipes, $cwd, $env);
    if (!is_resource($proc)) {
        return [127, '', "No se pudo ejecutar: " . implode(' ', $cmd) . "\n"];
    }

    $code = proc_close($proc);
    return [(int)$code, '', ''];
}

function now_ms(): float {
    return microtime(true) * 1000.0;
}

function find_bin(string $bin, array $env): ?string {
    $bin = trim($bin);
    if ($bin === '') return null;

    // path explícito
    if (strpbrk($bin, "/\\") !== false) {
        return (is_file($bin) && is_executable($bin)) ? $bin : null;
    }

    $path = $env['PATH'] ?? getenv('PATH') ?? '';

    $candidates = [$bin];
    if (PHP_OS_FAMILY === 'Windows' && pathinfo($bin, PATHINFO_EXTENSION) === '') {
        $candidates = [$bin . '.exe', $bin . '.cmd', $bin . '.bat', $bin];
    }

    foreach (explode(PATH_SEPARATOR, (string)$path) as $dir) {
        $dir = rtrim($dir);
        if ($dir === '') continue;
        foreach ($candidates as $c) {
            $p = $dir . DIRECTORY_SEPARATOR . $c;
            if (is_file($p) && is_executable($p)) return $p;
        }
    }
    return null;
}

// Env base: lo más robusto es partir de getenv() (no depende de variables_order)
$env = getenv();
if ($env === false) $env = [];

// Forward explícito de las keys “contractuales” de test (si están seteadas)
$forwardKeys = [
    'DB_ENV_PATH','APP_ENV','APP_DEBUG',
    'TEST_SCOPE','TEST_MATCH','TEST_LIST',
    'TEST_COVERAGE','TEST_COVERAGE_FORMAT','TEST_COVERAGE_DIR',
    'TEST_USE_PUBLIC_LOADER','TEST_IMPORT_DEBUG',
    'NO_COLOR','FORCE_COLOR','PVT_COLOR',
];
foreach ($forwardKeys as $k) {
    $v = getenv($k);
    if ($v !== false) $env[$k] = $v;
}

// CLAVE: el meta-runner fuerza failFast=0 en sub-runners (a menos que lo pidas explícito)
$env['TEST_FAIL_FAST'] = $childFailFast ? '1' : '0';

$overallFail = 0;
$summary = [];
$tMeta0 = now_ms();

$wantBack     = in_array($target, ['all','back','php'], true);
$wantFrontPhp = in_array($target, ['all','front','public_html','front-php','php'], true);
$wantFrontJs  = in_array($target, ['all','front','public_html','front-js','js'], true);

if ($wantBack) {
    if (!is_file($backRunner)) {
        fwrite(STDERR, "Falta runner BACK: {$backRunner}\n");
        exit($EXIT_ERR);
    }
    $t0 = now_ms();
    [$code] = run_cmd([$php, $backRunner], $repoRoot, $env);
    $ms = (int)round(now_ms() - $t0);
    $summary[] = ['BACK/PHP', $code, $ms];
    if ($code !== 0 && $code !== $EXIT_SKIP) { $overallFail = 1; if ($metaFailFast) goto done; }
}

if ($wantFrontPhp) {
    if (!is_file($frontPhpRunner)) {
        fwrite(STDERR, "Falta runner FRONT/PHP: {$frontPhpRunner}\n");
        exit($EXIT_ERR);
    }
    $t0 = now_ms();
    [$code] = run_cmd([$php, $frontPhpRunner], $repoRoot, $env);
    $ms = (int)round(now_ms() - $t0);
    $summary[] = ['PUBLIC_HTML/PHP', $code, $ms];
    if ($code !== 0 && $code !== $EXIT_SKIP) { $overallFail = 1; if ($metaFailFast) goto done; }
}

if ($wantFrontJs) {
    if (!is_file($frontJsRunner)) {
        fwrite(STDERR, "Falta runner FRONT/JS: {$frontJsRunner}\n");
        exit($EXIT_ERR);
    }

    $nodePath = find_bin($node, $env);
    if ($nodePath === null) {
        $code = $requireNode ? $EXIT_FAIL : $EXIT_SKIP;
        $why  = $requireNode ? 'FAIL' : 'SKIP';
        fwrite(STDERR, "{$why}: no se encontró '{$node}' en PATH dentro del contenedor. (Instalá Node o corré JS afuera)\n");
        $summary[] = ['PUBLIC_HTML/JS', $code, 0];
        if ($code !== 0 && $code !== $EXIT_SKIP) { $overallFail = 1; if ($metaFailFast) goto done; }
    } else {
        $t0 = now_ms();
        [$code] = run_cmd([$nodePath, $frontJsRunner], $repoRoot, $env);
        $ms = (int)round(now_ms() - $t0);
        $summary[] = ['PUBLIC_HTML/JS', $code, $ms];
        if ($code !== 0 && $code !== $EXIT_SKIP) { $overallFail = 1; if ($metaFailFast) goto done; }
    }
}

done:

banner('SUMMARY (meta)');
foreach ($summary as $row) {
    [$name, $code, $ms] = $row;
    if (function_exists('pvt_ui_summary_line')) {
        echo pvt_ui_summary_line($name, (int)$code);
        if ($ms > 0) echo "    time_ms={$ms}\n";
        continue;
    }
    $status = ($code === 0) ? 'PASS' : (($code === 2) ? 'SKIP' : 'FAIL');
    $t = ($ms > 0) ? " time_ms={$ms}" : "";
    echo str_pad($name, 22) . " -> {$status} (code={$code}){$t}\n";
}

$tMetaMs = (int)round(now_ms() - $tMeta0);
echo "\nTotal meta time_ms={$tMetaMs}\n";

exit($overallFail);