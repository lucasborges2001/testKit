<?php
declare(strict_types=1);

/**
 * TAGS: integration,critical
 * SCOPE: integration
 */

$repoRoot = function_exists('tk_repo_root') ? tk_repo_root() : dirname(__DIR__, 4);
require_once $repoRoot . '/testkit/utils/php/assert.php';

try {
    t_case('integration template', static function (): void {
        $response = ['ok' => true, 'code' => 'OK'];
        t_eq($response['ok'], true, 'expected ok=true');
        t_eq($response['code'], 'OK', 'expected contract code');
    });
    exit(0);
} catch (TestSkip $e) {
    t_print_skip($e);
    exit(2);
} catch (Throwable $e) {
    t_print_fail($e);
    exit(1);
}
