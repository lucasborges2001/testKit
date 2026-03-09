<?php
declare(strict_types=1);

/**
 * /test/runTest.php
 *
 * Meta-runner (root): ejecuta runners separados sin mezclar discovery.
 *
 * -----------------------------------------------------------------------------
 * RUTAS (relativas a <repo>)
 * -----------------------------------------------------------------------------
 * - Este archivo:                /test/runTest.php
 * - Runner BACK (PHP):           /test/back/runTestBack.php
 * - Runner FRONT (PHP):          /test/front/runFrontTest.php
 * - Runner FRONT (JS / Node):    /test/front/runFrontTest.mjs
 * - UI/const (TestKit):          /test/utils/constants.php
 *                               /test/utils/php/ui.php
 *
 * -----------------------------------------------------------------------------
 * USO
 * -----------------------------------------------------------------------------
 *   php test/runTest.php
 *   php test/runTest.php back
 *   php test/runTest.php front
 *   php test/runTest.php front-php
 *   php test/runTest.php front-js
 *
 * También por ENV:
 *   TEST_TARGET=all|back|front|front-php|front-js
 *
 * -----------------------------------------------------------------------------
 * FAIL-FAST
 * -----------------------------------------------------------------------------
 * - Meta-runner: TEST_META_FAIL_FAST=0 (default) / 1 (corta al primer FAIL)
 * - Sub-runners: TEST_CHILD_FAIL_FAST=0 (default) / 1 (propaga failFast)
 *   El meta-runner exporta TEST_FAIL_FAST a los sub-runners.
 *
 * -----------------------------------------------------------------------------
 * Node
 * -----------------------------------------------------------------------------
 * - NODE_BINARY=node (default)
 * - TEST_JS_REQUIRE_NODE=1 => si falta node => FAIL (si no, SKIP)
 *
 * -----------------------------------------------------------------------------
 * PHP (sub-runners)
 * -----------------------------------------------------------------------------
 * - TEST_PHP_BINARY=/ruta/a/php.exe  => fuerza el PHP usado para sub-runners
 *
 * Comportamiento por defecto:
 * - Usa PHP_BINARY (el PHP que ejecuta este meta-runner).
 * - Si se va a correr BACK/PHP y el PHP actual NO tiene pdo_mysql:
 *   - En Windows, si existe C:\xampp\php\php.exe y sí tiene pdo_mysql,
 *     se usa automáticamente para ejecutar los sub-runners.
 */

/* =============================================================================
 * 0) Bootstrap: rutas base + UI del TestKit
 * ============================================================================= */

$testkitRoot = dirname(__DIR__); // <testkit>
$repoRoot = rtrim((string)(getenv('TESTKIT_PROJECT_ROOT') ?: dirname($testkitRoot)), '/\\'); // <project>
$testRoot = $repoRoot . '/test';          // <project>/test
putenv('TESTKIT_ROOT=' . $testkitRoot);
putenv('TK_REPO_ROOT=' . $repoRoot);

$const = $testkitRoot . '/utils/constants.php';
$ui    = $testkitRoot . '/utils/php/ui.php';
if (is_file($const)) require_once $const;
if (is_file($ui)) require_once $ui;

$EXIT_PASS = defined('PVT_EXIT_PASS') ? PVT_EXIT_PASS : 0;
$EXIT_FAIL = defined('PVT_EXIT_FAIL') ? PVT_EXIT_FAIL : 1;
$EXIT_SKIP = defined('PVT_EXIT_SKIP') ? PVT_EXIT_SKIP : 2;
$EXIT_ERR  = defined('PVT_EXIT_ERROR') ? PVT_EXIT_ERROR : 3;

/* =============================================================================
 * 1) Target selection
 * ============================================================================= */

$target = $argv[1] ?? (getenv('TEST_TARGET') ?: 'all');
$target = strtolower(trim((string)$target));

$VALID = ['all','back','front','public_html','front-php','front-js','php','js'];
if (!in_array($target, $VALID, true)) {
    fwrite(STDERR, "TEST_TARGET inválido: {$target}. Valores: " . implode('|', $VALID) . "\n");
    exit($EXIT_ERR);
}

$wantBack     = in_array($target, ['all','back','php'], true);
$wantFrontPhp = in_array($target, ['all','front','public_html','front-php','php'], true);
$wantFrontJs  = in_array($target, ['all','front','public_html','front-js','js'], true);

/* =============================================================================
 * 2) Flags contractuales de ejecución
 * ============================================================================= */

$metaFailFast  = (getenv('TEST_META_FAIL_FAST') ?: '0') === '1';
$childFailFast = (getenv('TEST_CHILD_FAIL_FAST') ?: '0') === '1';
$requireNode   = (getenv('TEST_JS_REQUIRE_NODE') ?: '0') === '1';

/* =============================================================================
 * 3) Runners
 * ============================================================================= */

$backRunner     = $testkitRoot . '/runners/runTestBack.php';
$frontPhpRunner = $testkitRoot . '/runners/runFrontTest.php';
$frontJsRunner  = $testkitRoot . '/runners/runFrontTest.mjs';

/* =============================================================================
 * 4) UI helpers
 * ============================================================================= */

function banner_fallback(string $title): void {
    echo "\n" . str_repeat('=', 78) . "\n";
    echo $title . "\n";
    echo str_repeat('=', 78) . "\n\n";
}

function banner(string $title): void {
    if (function_exists('pvt_ui_banner')) pvt_ui_banner($title);
    else banner_fallback($title);
}

/* =============================================================================
 * 5) Time helpers
 * ============================================================================= */

function now_ms(): float {
    return microtime(true) * 1000.0;
}

/* =============================================================================
 * 6) Process execution helpers
 * ============================================================================= */

/**
 * Ejecuta un comando y deja STDOUT/STDERR “en vivo” (ideal para runners).
 *
 * @param array<int,string> $cmd
 * @param string $cwd
 * @param array<string,string> $env
 * @return array{0:int,1:string,2:string}  [exitCode, stdout, stderr] (stdout/err vacíos)
 */
function run_cmd_stream(array $cmd, string $cwd, array $env): array {
    $spec = [
        0 => STDIN,
        1 => STDOUT,
        2 => STDERR,
    ];

    $pipes = [];
    $proc = @proc_open($cmd, $spec, $pipes, $cwd, $env);
    if (!is_resource($proc)) {
        return [127, '', "No se pudo ejecutar: " . implode(' ', $cmd) . "\n"];
    }

    $code = proc_close($proc);
    return [(int)$code, '', ''];
}

/**
 * Ejecuta un comando y captura stdout/stderr (ideal para preflight/detección).
 *
 * @param array<int,string> $cmd
 * @param string $cwd
 * @param array<string,string> $env
 * @return array{0:int,1:string,2:string} [exitCode, stdout, stderr]
 */
function run_cmd_capture(array $cmd, string $cwd, array $env): array {
    $spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $pipes = [];
    $proc = @proc_open($cmd, $spec, $pipes, $cwd, $env);
    if (!is_resource($proc)) {
        return [127, '', "No se pudo ejecutar: " . implode(' ', $cmd) . "\n"];
    }

    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) ?: '';
    $err = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);

    $code = proc_close($proc);
    return [(int)$code, $out, $err];
}

/* =============================================================================
 * 7) PATH lookup (binarios)
 * ============================================================================= */

/**
 * Busca un binario en PATH (o valida una ruta explícita).
 *
 * @param string $bin
 * @param array<string,string> $env
 * @return string|null ruta absoluta si existe y es ejecutable
 */
function find_bin(string $bin, array $env): ?string {
    $bin = trim($bin);
    if ($bin === '') return null;

    // Ruta explícita
    if (strpbrk($bin, "/\\") !== false) {
        return (is_file($bin) && is_executable($bin)) ? $bin : null;
    }

    $path = $env['PATH'] ?? getenv('PATH') ?? '';

    $candidates = [$bin];
    if (PHP_OS_FAMILY === 'Windows' && pathinfo($bin, PATHINFO_EXTENSION) === '') {
        $candidates = [$bin . '.exe', $bin . '.cmd', $bin . '.bat', $bin];
    }

    foreach (explode(PATH_SEPARATOR, (string)$path) as $dir) {
        $dir = rtrim((string)$dir);
        if ($dir === '') continue;
        foreach ($candidates as $c) {
            $p = $dir . DIRECTORY_SEPARATOR . $c;
            if (is_file($p) && is_executable($p)) return $p;
        }
    }
    return null;
}

/* =============================================================================
 * 8) PHP preflight: selección de binario y diagnóstico
 * ============================================================================= */

/**
 * Verifica si un PHP (binario) tiene pdo_mysql disponible.
 *
 * @param string $phpPath
 * @param string $cwd
 * @param array<string,string> $env
 * @return bool
 */
function php_has_pdo_mysql(string $phpPath, string $cwd, array $env): bool {
    $code = 'echo (extension_loaded("pdo_mysql") && in_array("mysql", PDO::getAvailableDrivers(), true)) ? "1" : "0";';
    [$exit, $out] = run_cmd_capture([$phpPath, '-r', $code], $cwd, $env);
    return ($exit === 0) && (trim($out) === '1');
}

/**
 * Selecciona el PHP para ejecutar sub-runners.
 *
 * Reglas:
 * - Si TEST_PHP_BINARY está seteado, se usa (si existe).
 * - Si se va a correr BACK y el PHP actual no tiene pdo_mysql:
 *   - En Windows, si C:\xampp\php\php.exe existe y tiene pdo_mysql, se usa.
 * - En otros casos, se usa PHP_BINARY.
 *
 * @param bool $needPdoMysql
 * @param string $repoRoot
 * @param array<string,string> $env
 * @return array{0:string,1:array<int,string>} [phpPath, warnings[]]
 */
function choose_php_for_children(bool $needPdoMysql, string $repoRoot, array $env): array {
    $warnings = [];

    $explicit = getenv('TEST_PHP_BINARY');
    if ($explicit !== false && trim($explicit) !== '') {
        $p = trim($explicit);
        if (!is_file($p) || !is_executable($p)) {
            $warnings[] = "TEST_PHP_BINARY apunta a un PHP inválido/no ejecutable: {$p}";
            return [PHP_BINARY, $warnings];
        }
        return [$p, $warnings];
    }

    // Default: el PHP actual
    $current = PHP_BINARY;

    if (!$needPdoMysql) {
        return [$current, $warnings];
    }

    // Si el PHP actual sirve, no cambiamos.
    if (php_has_pdo_mysql($current, $repoRoot, $env)) {
        return [$current, $warnings];
    }

    // Fallback Windows: XAMPP (solo si existe y sirve)
    if (PHP_OS_FAMILY === 'Windows') {
        $xampp = 'C:\xampp\php\php.exe';
        if (is_file($xampp) && is_executable($xampp)) {
            if (php_has_pdo_mysql($xampp, $repoRoot, $env)) {
                $warnings[] = "El PHP actual no tiene pdo_mysql. Se usará XAMPP para sub-runners: {$xampp}";
                return [$xampp, $warnings];
            }
        }
    }

    $warnings[] = "El PHP actual no tiene pdo_mysql y no se encontró un fallback válido. Ejecutá con XAMPP: C:\\xampp\\php\\php.exe test\\runTest.php";
    return [$current, $warnings];
}

/**
 * Imprime diagnóstico mínimo del runtime actual (meta-runner).
 */
function print_php_runtime_diag(): void {
    $bin = PHP_BINARY;
    $ini = php_ini_loaded_file();
    $ini = ($ini === false || $ini === '') ? '(none)' : $ini;

    $drivers = [];
    if (class_exists('PDO')) {
        try { $drivers = PDO::getAvailableDrivers(); } catch (\Throwable) { $drivers = []; }
    }
    $driversStr = $drivers ? implode(',', $drivers) : '(none)';

    echo "PHP_BINARY: {$bin}\n";
    echo "php.ini:    {$ini}\n";
    echo "PDO:        " . (class_exists('PDO') ? 'yes' : 'no') . "\n";
    echo "PDO drivers:{$driversStr}\n";
}

/* =============================================================================
 * 9) Env forwarding (contract)
 * ============================================================================= */

$env = getenv();
if ($env === false) $env = [];

$forwardKeys = [
    'DB_ENV_PATH','APP_ENV','APP_DEBUG',
    'TEST_SCOPE','TEST_MATCH','TEST_LIST',
    'TEST_COVERAGE','TEST_COVERAGE_FORMAT','TEST_COVERAGE_DIR',
    'TEST_USE_PUBLIC_LOADER','TEST_IMPORT_DEBUG',
    'NO_COLOR','FORCE_COLOR','PVT_COLOR',
    'NODE_BINARY','TEST_JS_REQUIRE_NODE',
    'TEST_PHP_BINARY',
];
foreach ($forwardKeys as $k) {
    $v = getenv($k);
    if ($v !== false) $env[$k] = $v;
}

// Meta-runner fuerza failFast=0 en sub-runners (salvo override)
$env['TEST_FAIL_FAST'] = $childFailFast ? '1' : '0';

/* =============================================================================
 * 10) Preflight (diagnóstico y selección de PHP hijo)
 * ============================================================================= */

$needPdoMysql = $wantBack; // BACK integra DB, requiere pdo_mysql en práctica
[$phpChild, $phpWarnings] = choose_php_for_children($needPdoMysql, $repoRoot, $env);

if (!empty($phpWarnings)) {
    banner('RUNTIME (preflight)');
    print_php_runtime_diag();
    echo "\n";
    foreach ($phpWarnings as $w) {
        fwrite(STDERR, "WARN: {$w}\n");
    }
    echo "\n";
}

/* =============================================================================
 * 11) Execution
 * ============================================================================= */

$node = getenv('NODE_BINARY') ?: 'node';

$overallFail = 0;
$summary = [];
$tMeta0 = now_ms();

// BACK / PHP
if ($wantBack) {
    if (!is_file($backRunner)) {
        fwrite(STDERR, "Falta runner BACK: {$backRunner}\n");
        exit($EXIT_ERR);
    }

    $t0 = now_ms();
    [$code] = run_cmd_stream([$phpChild, $backRunner], $repoRoot, $env);
    $ms = (int)round(now_ms() - $t0);
    $summary[] = ['BACK/PHP', $code, $ms];
    if ($code !== 0 && $code !== $EXIT_SKIP) { $overallFail = 1; if ($metaFailFast) goto done; }
}

// FRONT / PHP
if ($wantFrontPhp) {
    if (!is_file($frontPhpRunner)) {
        fwrite(STDERR, "Falta runner FRONT/PHP: {$frontPhpRunner}\n");
        exit($EXIT_ERR);
    }

    $t0 = now_ms();
    [$code] = run_cmd_stream([$phpChild, $frontPhpRunner], $repoRoot, $env);
    $ms = (int)round(now_ms() - $t0);
    $summary[] = ['PUBLIC_HTML/PHP', $code, $ms];
    if ($code !== 0 && $code !== $EXIT_SKIP) { $overallFail = 1; if ($metaFailFast) goto done; }
}

// FRONT / JS
if ($wantFrontJs) {
    if (!is_file($frontJsRunner)) {
        fwrite(STDERR, "Falta runner FRONT/JS: {$frontJsRunner}\n");
        exit($EXIT_ERR);
    }

    $nodePath = find_bin($node, $env);
    if ($nodePath === null) {
        $code = $requireNode ? $EXIT_FAIL : $EXIT_SKIP;
        $why  = $requireNode ? 'FAIL' : 'SKIP';
        fwrite(STDERR, "{$why}: no se encontró '{$node}' en PATH. (Instalá Node o ajustá NODE_BINARY)\n");
        $summary[] = ['PUBLIC_HTML/JS', $code, 0];
        if ($code !== 0 && $code !== $EXIT_SKIP) { $overallFail = 1; if ($metaFailFast) goto done; }
    } else {
        $t0 = now_ms();
        [$code] = run_cmd_stream([$nodePath, $frontJsRunner], $repoRoot, $env);
        $ms = (int)round(now_ms() - $t0);
        $summary[] = ['PUBLIC_HTML/JS', $code, $ms];
        if ($code !== 0 && $code !== $EXIT_SKIP) { $overallFail = 1; if ($metaFailFast) goto done; }
    }
}

done:

/* =============================================================================
 * 12) Summary
 * ============================================================================= */

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