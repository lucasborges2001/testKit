<?php
declare(strict_types=1);

/**
 * test/utils/php/ui.php
 * UI helpers for test runners.
 *
 * Env (prioridad):
 * - PVT_COLOR=0|1|auto   (default: auto)
 * - NO_COLOR=1           disable ANSI
 * - FORCE_COLOR=1        force ANSI even when not TTY
 */

function pvt_ui_enabled(): bool {
    $pvt = getenv('PVT_COLOR');
    if (is_string($pvt) && $pvt !== '') {
        $v = strtolower(trim($pvt));
        if (in_array($v, ['0','false','off','no'], true)) return false;
        if (in_array($v, ['1','true','on','yes'], true)) return true;
        // 'auto' cae al comportamiento por defecto
    }

    $no = getenv('NO_COLOR');
    if ($no !== false && $no !== '' && $no !== '0') return false;

    $force = getenv('FORCE_COLOR');
    if ($force !== false && $force !== '' && $force !== '0') return true;

    // Only enable when STDOUT is a TTY (avoid polluting redirected logs)
    $isTty = false;
    if (function_exists('stream_isatty')) {
        $isTty = @stream_isatty(STDOUT);
    } elseif (function_exists('posix_isatty')) {
        $isTty = @posix_isatty(STDOUT);
    }

    return (bool)$isTty;
}

function pvt_ui_ansi(string $s, string $code): string {
    static $on = null;
    if ($on === null) $on = pvt_ui_enabled();
    if (!$on) return $s;
    return "\033[{$code}m{$s}\033[0m";
}

// Styles
function pvt_ui_dim(string $s): string    { return pvt_ui_ansi($s, '90'); }
function pvt_ui_bold(string $s): string   { return pvt_ui_ansi($s, '1'); }
function pvt_ui_red(string $s): string    { return pvt_ui_ansi($s, '31'); }
function pvt_ui_green(string $s): string  { return pvt_ui_ansi($s, '32'); }
function pvt_ui_yellow(string $s): string { return pvt_ui_ansi($s, '33'); }
function pvt_ui_blue(string $s): string   { return pvt_ui_ansi($s, '34'); }
function pvt_ui_cyan(string $s): string   { return pvt_ui_ansi($s, '36'); }
function pvt_ui_gray(string $s): string   { return pvt_ui_ansi($s, '37'); }

function pvt_ui_banner(string $title): void {
    $line = str_repeat('=', 78);
    echo "\n" . pvt_ui_dim($line) . "\n";
    echo pvt_ui_bold(pvt_ui_cyan($title)) . "\n";
    echo pvt_ui_dim($line) . "\n\n";
}

function pvt_ui_test_head(string $relPath): string {
    return pvt_ui_bold(pvt_ui_cyan('==>')) . ' ' . pvt_ui_gray($relPath) . "\n";
}

function pvt_ui_status_label(string $status): string {
    $u = strtoupper($status);
    if ($u === 'PASS' || $u === 'OK') return pvt_ui_green($u);
    if ($u === 'SKIP') return pvt_ui_yellow($u);
    if ($u === 'FAIL' || $u === 'ERROR') return pvt_ui_red($u);
    return $u;
}

/**
 * Light post-processing of runner output for readability.
 * - OK:/PASS: -> green
 * - FAIL:/ERROR: -> red
 * - SKIP: -> yellow
 * - Warning:/Deprecated: -> yellow
 */
function pvt_ui_colorize_output(string $text): string {
    if (!pvt_ui_enabled() || $text === '') return $text;

    $lines = preg_split('/\r\n|\n|\r/', $text);
    if ($lines === false) return $text;

    $out = [];
    foreach ($lines as $line) {
        $orig = $line;
        if ($line === '') { $out[] = $line; continue; }

        if (preg_match('/^\s*(OK|PASS)\s*:/i', $line)) {
            $out[] = preg_replace_callback('/^\s*(OK|PASS)\s*:/i', fn($m) => pvt_ui_green(strtoupper($m[1])) . ':', $orig);
            continue;
        }
        if (preg_match('/^\s*(FAIL|ERROR)\s*:/i', $line)) {
            $out[] = preg_replace_callback('/^\s*(FAIL|ERROR)\s*:/i', fn($m) => pvt_ui_red(strtoupper($m[1])) . ':', $orig);
            continue;
        }
        if (preg_match('/^\s*SKIP\s*:/i', $line)) {
            $out[] = preg_replace_callback('/^\s*SKIP\s*:/i', fn($m) => pvt_ui_yellow('SKIP') . ':', $orig);
            continue;
        }
        if (preg_match('/^\s*(Warning|Deprecated)\s*:/i', $line)) {
            $out[] = preg_replace_callback('/^\s*(Warning|Deprecated)\s*:/i', fn($m) => pvt_ui_yellow($m[1]) . ':', $orig);
            continue;
        }

        $out[] = $orig;
    }

    return implode(PHP_EOL, $out);
}

function pvt_ui_summary_line(string $name, int $code): string {
    $status = ($code === 0) ? 'PASS' : (($code === 2) ? 'SKIP' : 'FAIL');
    $s = pvt_ui_status_label($status);
    $meta = pvt_ui_dim("(code={$code})");
    return pvt_ui_gray(str_pad($name, 22)) . " -> {$s} {$meta}\n";
}

function pvt_ui_counts(int $pass, int $fail, int $skip): string {
    return 'PASS=' . pvt_ui_green((string)$pass) .
        ' FAIL=' . ($fail > 0 ? pvt_ui_red((string)$fail) : pvt_ui_green('0')) .
        ' SKIP=' . ($skip > 0 ? pvt_ui_yellow((string)$skip) : pvt_ui_green('0'));
}
