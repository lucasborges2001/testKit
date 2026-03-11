<?php
declare(strict_types=1);

/**
 * TAGS: unit
 * SCOPE: unit
 */

$repoRoot = function_exists('tk_repo_root') ? tk_repo_root() : dirname(__DIR__, 4);
require_once $repoRoot . '/testkit/utils/php/assert.php';

try {
    t_case('unit template', static function (): void {
        t_eq(2 + 2, 4, 'math sanity');
    });
    exit(0);
} catch (TestSkip $e) {
    t_print_skip($e);
    exit(2);
} catch (Throwable $e) {
    t_print_fail($e);
    exit(1);
}
