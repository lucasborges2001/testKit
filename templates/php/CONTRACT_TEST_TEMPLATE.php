<?php
declare(strict_types=1);

/**
 * TAGS: integration,contract,critical
 * SCOPE: integration
 */

$repoRoot = function_exists('tk_repo_root') ? tk_repo_root() : dirname(__DIR__, 4);
require_once $repoRoot . '/testkit/utils/php/assert.php';

try {
    t_case('contract template', static function (): void {
        $body = '{"ok":true,"code":"OK"}';
        t_json_eq($body, ['ok' => true, 'code' => 'OK'], 'public contract mismatch');
    });
    exit(0);
} catch (TestSkip $e) {
    t_print_skip($e);
    exit(2);
} catch (Throwable $e) {
    t_print_fail($e);
    exit(1);
}
