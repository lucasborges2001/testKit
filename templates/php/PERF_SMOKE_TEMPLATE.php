<?php
declare(strict_types=1);

/**
 * TAGS: perf,smoke,slow
 * SCOPE: integration
 */

$repoRoot = function_exists('tk_repo_root') ? tk_repo_root() : dirname(__DIR__, 4);
require_once $repoRoot . '/testkit/utils/php/assert.php';

try {
    t_case('perf smoke template', static function (): void {
        $maxMs = (int)(getenv('TEST_PERF_MAX_MS') ?: 800);
        $t0 = microtime(true);

        usleep(10 * 1000); // replace with real path under test

        $elapsed = (int)round((microtime(true) - $t0) * 1000);
        t_assert($elapsed <= $maxMs, 'perf threshold exceeded | elapsed_ms=' . $elapsed . ' max_ms=' . $maxMs);
    });
    exit(0);
} catch (TestSkip $e) {
    t_print_skip($e);
    exit(2);
} catch (Throwable $e) {
    t_print_fail($e);
    exit(1);
}
