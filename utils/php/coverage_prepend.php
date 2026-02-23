<?php
declare(strict_types=1);

/**
 * Auto-prepend para coverage por proceso.
 * Activación: TEST_COVERAGE=1 y xdebug disponible.
 *
 * Escribe JSON por proceso en TEST_COVERAGE_FILE.
 */

if ((getenv('TEST_COVERAGE') ?: '0') !== '1') return;
if (!function_exists('xdebug_start_code_coverage')) return;

$repoRoot = dirname(__DIR__, 3); // root/
$exclude = '#/(test|docker|vendor|logs)/#';

/** @phpstan-ignore-next-line */
xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);

register_shutdown_function(function () use ($repoRoot, $exclude) {
    if (!function_exists('xdebug_get_code_coverage')) return;

    $outFile = getenv('TEST_COVERAGE_FILE') ?: '';
    if ($outFile === '') return;

    $raw = xdebug_get_code_coverage();
    $filtered = [];

    foreach ($raw as $file => $lines) {
        $fileNorm = str_replace('\\', '/', (string)$file);
        if (!str_starts_with($fileNorm, str_replace('\\', '/', $repoRoot) . '/')) continue;
        if (preg_match($exclude, $fileNorm)) continue;
        $filtered[$fileNorm] = $lines;
    }

    @mkdir(dirname($outFile), 0777, true);
    file_put_contents($outFile, json_encode($filtered));
});
