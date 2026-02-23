<?php
declare(strict_types=1);

/**
 * test/utils/php/assert.php
 * Test helpers (sin dependencias externas).
 *
 * Exit codes por archivo:
 *   0 = PASS
 *   1 = FAIL
 *   2 = SKIP
 *
 * Colores ANSI (consistentes con runners):
 *   NO_COLOR=1 / FORCE_COLOR=1
 */

// Optional UI layer (keeps standalone tests readable)
$__ui = __DIR__ . '/ui.php';
if (is_file($__ui)) require_once $__ui;

final class TestSkip extends RuntimeException {}
final class TestFail extends RuntimeException {}

function t_skip(string $reason): void { throw new TestSkip($reason); }

function t_assert(bool $cond, string $msg = 'assert failed'): void {
    if (!$cond) throw new TestFail($msg);
}

function t_eq(mixed $a, mixed $b, string $msg = ''): void {
    if ($a !== $b) {
        $m = $msg !== '' ? $msg : 'expected strict equality';
        $got = var_export($a, true);
        $exp = var_export($b, true);

        // Keep message parseable without ANSI; UI only decorates labels.
        if (function_exists('pvt_ui_enabled') && pvt_ui_enabled()) {
            $labelGot = function_exists('pvt_ui_red') ? pvt_ui_red('got') : 'got';
            $labelExp = function_exists('pvt_ui_green') ? pvt_ui_green('expected') : 'expected';
            throw new TestFail("{$m} | {$labelGot}={$got} {$labelExp}={$exp}");
        }

        throw new TestFail($m . ' | got=' . $got . ' expected=' . $exp);
    }
}

function t_ne(mixed $a, mixed $b, string $msg = ''): void {
    if ($a === $b) {
        $m = $msg !== '' ? $msg : 'expected not equal';
        $val = var_export($a, true);
        if (function_exists('pvt_ui_enabled') && pvt_ui_enabled()) {
            $label = function_exists('pvt_ui_red') ? pvt_ui_red('value') : 'value';
            throw new TestFail("{$m} | {$label}={$val}");
        }
        throw new TestFail($m . ' | value=' . $val);
    }
}

function t_contains(string $needle, string $haystack, string $msg = ''): void {
    if (strpos($haystack, $needle) === false) {
        $m = $msg !== '' ? $msg : 'expected substring not found';
        $n = var_export($needle, true);
        if (function_exists('pvt_ui_enabled') && pvt_ui_enabled()) {
            $label = function_exists('pvt_ui_yellow') ? pvt_ui_yellow('needle') : 'needle';
            throw new TestFail("{$m} | {$label}={$n}");
        }
        throw new TestFail($m . ' | needle=' . $n);
    }
}

function t_match(string $pattern, string $text, string $msg = ''): void {
    if (@preg_match($pattern, $text) !== 1) {
        $m = $msg !== '' ? $msg : 'pattern did not match';
        if (function_exists('pvt_ui_enabled') && pvt_ui_enabled()) {
            $label = function_exists('pvt_ui_yellow') ? pvt_ui_yellow('pattern') : 'pattern';
            throw new TestFail("{$m} | {$label}={$pattern}");
        }
        throw new TestFail($m . ' | pattern=' . $pattern);
    }
}

function t_case(string $name, callable $fn): void {
    $fn();

    if (function_exists('pvt_ui_enabled') && pvt_ui_enabled()) {
        $pass = function_exists('pvt_ui_green') ? pvt_ui_green('PASS') : 'PASS';
        $nm = function_exists('pvt_ui_gray') ? pvt_ui_gray($name) : $name;
        echo "  - {$pass}: {$nm}\n";
        return;
    }

    echo "  - PASS: {$name}\n";
}

function t_print_fail(Throwable $e): void {
    $msg = $e->getMessage();
    $at = $e->getFile() . ':' . $e->getLine();

    if (function_exists('pvt_ui_enabled') && pvt_ui_enabled()) {
        $fail = function_exists('pvt_ui_red') ? pvt_ui_red('FAIL') : 'FAIL';
        $atLbl = function_exists('pvt_ui_dim') ? pvt_ui_dim('AT:') : 'AT:';
        $atVal = function_exists('pvt_ui_dim') ? pvt_ui_dim($at) : $at;
        fwrite(STDERR, "{$fail}: {$msg}\n");
        fwrite(STDERR, "{$atLbl}   {$atVal}\n");
        return;
    }

    fwrite(STDERR, "FAIL: {$msg}\n");
    fwrite(STDERR, "AT:   {$at}\n");
}

function t_print_skip(Throwable $e): void {
    $msg = $e->getMessage();

    if (function_exists('pvt_ui_enabled') && pvt_ui_enabled()) {
        $skip = function_exists('pvt_ui_yellow') ? pvt_ui_yellow('SKIP') : 'SKIP';
        $m = function_exists('pvt_ui_dim') ? pvt_ui_dim($msg) : $msg;
        echo "{$skip}: {$m}\n";
        return;
    }

    echo "SKIP: {$msg}\n";
}

/**
 * Decodifica JSON con mensaje de error consistente.
 *
 * @return mixed
 */
function t_json_decode(string $json, bool $assoc = true, string $msg = 'invalid json'): mixed {
    try {
        return json_decode($json, $assoc, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        throw new TestFail($msg . ' | ' . $e->getMessage());
    }
}

/**
 * Normaliza estructura JSON para comparación estable:
 * - arrays asociativos => ordena keys
 * - listas => preserva orden
 */
function t_json_normalize(mixed $v): mixed {
    if (!is_array($v)) return $v;

    // PHP 8.1+: array_is_list
    $isList = function_exists('array_is_list') ? array_is_list($v) : (array_keys($v) === range(0, count($v) - 1));

    if ($isList) {
        $out = [];
        foreach ($v as $x) $out[] = t_json_normalize($x);
        return $out;
    }

    $out = [];
    foreach ($v as $k => $x) $out[$k] = t_json_normalize($x);
    ksort($out);
    return $out;
}

/**
 * Assert JSON == expected (sin fragilidad por orden de keys).
 */
function t_json_eq(string $json, mixed $expected, string $msg = 'json mismatch'): void {
    try {
        $decoded = t_json_decode($json, true);
    } catch (Throwable $e) {
        throw new TestFail($msg . ' | ' . $e->getMessage());
    }

    $a = t_json_normalize($decoded);
    $b = t_json_normalize($expected);

    // usamos == para evitar que el orden de keys rompa la comparación,
    // pero mantenemos tipos (normalizado) y mostramos detalle con var_export.
    if ($a == $b) return;

    $got = var_export($decoded, true);
    $exp = var_export($expected, true);
    throw new TestFail($msg . ' | got=' . $got . ' expected=' . $exp);
}
