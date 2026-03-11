<?php
declare(strict_types=1);

/**
 * TEMPLATE BACK (generico)
 *
 * TAGS: unit,critical
 * SCOPE: unit
 *
 * Copiar a: test/back/<modulo>/unit/<nombre>.test.php
 */

$repoRoot = function_exists('tk_repo_root') ? tk_repo_root() : dirname(__DIR__, 3);
require_once $repoRoot . '/testkit/utils/php/assert.php';

try {
    t_case('template back works', static function (): void {
        t_assert(true, 'replace with real assertions');
    });

    exit(0);
} catch (TestSkip $e) {
    t_print_skip($e);
    exit(2);
} catch (Throwable $e) {
    t_print_fail($e);
    exit(1);
}
