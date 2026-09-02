<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

final class CompactBatchReporter
{
    private const LABEL_WIDTH = 30;
    private const FAILURE_OUTPUT_LINES = 12;

    /** @param array<string,mixed> $check */
    public static function printCheck(array $check): void
    {
        $normalized = self::normalizeCheck($check);
        $status = self::status($normalized);
        $statusText = match ($status) {
            'FAIL' => UI::failure('FAIL'),
            'SKIP' => UI::warning('SKIP'),
            'LISTED' => UI::info('LISTED'),
            default => UI::success('PASS'),
        };

        $count = sprintf('%d/%d', $normalized['passed'], $normalized['total']);
        if ($status === 'SKIP') {
            $count = sprintf('%d/%d', $normalized['skipped'], $normalized['total']);
        } elseif ($status === 'LISTED') {
            $count = sprintf('%d', $normalized['total']);
        }

        echo $statusText . ' '
            . str_pad($normalized['label'], self::LABEL_WIDTH)
            . ' ' . str_pad($count, 9, ' ', STR_PAD_LEFT)
            . '  ' . UI::gray(self::formatDuration($normalized['duration_ms']))
            . PHP_EOL;

        if ($status === 'SKIP' && $normalized['skip_reason'] !== '') {
            echo '  ' . UI::gray('reason:') . ' ' . $normalized['skip_reason'] . PHP_EOL;
        }

        foreach ($normalized['failures'] as $failure) {
            self::printFailure($failure);
        }
    }

    /** @param array<int,array<string,mixed>> $checks */
    public static function printSummary(array $checks): void
    {
        $passed = 0;
        $failed = 0;
        $skipped = 0;
        $durationMs = 0;

        foreach ($checks as $check) {
            $normalized = self::normalizeCheck($check);
            $passed += $normalized['passed'];
            $failed += $normalized['failed'];
            $skipped += $normalized['skipped'];
            $durationMs += $normalized['duration_ms'];
        }

        $parts = [
            UI::success($passed . ' PASS'),
            $failed > 0 ? UI::failure($failed . ' FAIL') : UI::gray('0 FAIL'),
            $skipped > 0 ? UI::warning($skipped . ' SKIP') : UI::gray('0 SKIP'),
        ];

        echo PHP_EOL
            . UI::bold('Summary:') . ' '
            . implode(' · ', $parts)
            . ' · ' . UI::gray(self::formatDuration($durationMs))
            . PHP_EOL;
    }

    /** @param array<string,mixed> $failure */
    private static function printFailure(array $failure): void
    {
        $label = trim((string)($failure['label'] ?? 'check'));
        $reason = trim((string)($failure['reason'] ?? ''));
        $output = trim((string)($failure['output'] ?? ''));
        $rerun = trim((string)($failure['rerun'] ?? ''));
        $exitCode = $failure['exit_code'] ?? null;

        echo PHP_EOL . '  ' . UI::failure('FAIL') . ' ' . $label . PHP_EOL;
        if (is_int($exitCode)) {
            echo '    ' . UI::gray('exit_code=') . UI::failure((string)$exitCode) . PHP_EOL;
        }
        if ($reason !== '') {
            echo '    ' . UI::gray('reason:') . ' ' . $reason . PHP_EOL;
        }
        if ($output !== '') {
            $lines = preg_split('/\R/', $output) ?: [];
            $visible = array_slice($lines, 0, self::FAILURE_OUTPUT_LINES);
            foreach ($visible as $line) {
                echo '    ' . $line . PHP_EOL;
            }
            $omitted = count($lines) - count($visible);
            if ($omitted > 0) {
                echo '    ' . UI::gray("... {$omitted} lines omitted") . PHP_EOL;
            }
        }
        if ($rerun !== '') {
            echo '    ' . UI::gray('rerun:') . ' ' . UI::info($rerun) . PHP_EOL;
        }
    }

    /**
     * @param array<string,mixed> $check
     * @return array{label:string,total:int,passed:int,failed:int,skipped:int,duration_ms:int,skip_reason:string,failures:array<int,array<string,mixed>>,status:string}
     */
    private static function normalizeCheck(array $check): array
    {
        $passed = max(0, (int)($check['passed'] ?? 0));
        $failed = max(0, (int)($check['failed'] ?? 0));
        $skipped = max(0, (int)($check['skipped'] ?? 0));
        $total = max(0, (int)($check['total'] ?? ($passed + $failed + $skipped)));
        if ($total < ($passed + $failed + $skipped)) {
            $total = $passed + $failed + $skipped;
        }

        return [
            'label' => trim((string)($check['label'] ?? 'Check')),
            'total' => $total,
            'passed' => $passed,
            'failed' => $failed,
            'skipped' => $skipped,
            'duration_ms' => max(0, (int)($check['duration_ms'] ?? 0)),
            'skip_reason' => trim((string)($check['skip_reason'] ?? '')),
            'failures' => array_values(array_filter(
                is_array($check['failures'] ?? null) ? $check['failures'] : [],
                'is_array'
            )),
            'status' => strtoupper(trim((string)($check['status'] ?? ''))),
        ];
    }

    /** @param array{passed:int,failed:int,skipped:int,total:int,status:string} $check */
    private static function status(array $check): string
    {
        if ($check['status'] === 'LISTED') {
            return 'LISTED';
        }
        if ($check['failed'] > 0) {
            return 'FAIL';
        }
        if ($check['passed'] === 0 && $check['skipped'] > 0 && $check['skipped'] === $check['total']) {
            return 'SKIP';
        }
        return 'PASS';
    }

    private static function formatDuration(int $durationMs): string
    {
        if ($durationMs < 1000) {
            return $durationMs . 'ms';
        }
        return number_format($durationMs / 1000, 1, '.', '') . 's';
    }

    private function __construct()
    {
    }
}
