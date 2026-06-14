<?php
declare(strict_types=1);

namespace Testkit\Core\Cleanup;

require_once __DIR__ . '/CleanupOptions.php';
require_once __DIR__ . '/CleanupFilesystem.php';
require_once __DIR__ . '/CleanupSafety.php';
require_once __DIR__ . '/CleanupLockInspector.php';
require_once __DIR__ . '/CleanupPlanner.php';
require_once __DIR__ . '/CleanupExecutor.php';
require_once __DIR__ . '/CleanupAuditWriter.php';
require_once __DIR__ . '/CleanupReporter.php';

/**
 * CLI entrypoint for conservative testKit artifact cleanup.
 *
 * The implementation is intentionally split into small collaborators:
 * - CleanupOptions parses and validates CLI flags.
 * - CleanupPlanner discovers deletion candidates.
 * - CleanupExecutor performs deletion only after --apply.
 * - CleanupAuditWriter writes cleanup evidence.
 * - CleanupReporter renders text or JSON output.
 */
final class CleanupCommand
{
    /**
     * @param array<int,string> $argv
     */
    public static function runCli(array $argv): int
    {
        try {
            $options = CleanupOptions::parse(array_slice($argv, 1));
            $payload = CleanupPlanner::buildPlan($options);

            if ($options['apply']) {
                CleanupExecutor::executePlan($payload);
                $payload['mode'] = 'apply';
            }

            CleanupAuditWriter::write($payload);

            if ($options['json']) {
                echo CleanupReporter::encodeJson($payload) . PHP_EOL;
            } else {
                CleanupReporter::printText($payload, $options);
            }

            return ((int)($payload['summary']['errors'] ?? 0)) > 0 ? 1 : 0;
        } catch (\InvalidArgumentException $e) {
            fwrite(STDERR, 'cleanup error: ' . $e->getMessage() . PHP_EOL);
            fwrite(STDERR, PHP_EOL . CleanupOptions::usage() . PHP_EOL);
            return 2;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'cleanup error: ' . $e->getMessage() . PHP_EOL);
            return 1;
        }
    }
}
