<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting\Console;

use Testkit\Core\Common\Env;
use Testkit\Core\Reporting\UI;

final class ConsoleProgressRenderer
{
    public static function printSuiteProgress(array $snapshot): void
    {
        $avgMs = $snapshot['avg_ms_per_test'] ?? null;
        $etaMs = $snapshot['eta_ms'] ?? null;
        $jobs = (int)($snapshot['jobs'] ?? 1);

        $line = UI::info('[Progress]') . ' '
            . 'el=' . ConsoleTableFormatter::formatDurationMs((int)($snapshot['elapsed_ms'] ?? 0))
            . ' '
            . 'done=' . (int)($snapshot['completed'] ?? 0) . '/' . (int)($snapshot['total'] ?? 0)
            . ' '
            . 'p/f/s/to=' . (int)($snapshot['pass'] ?? 0)
            . '/' . (int)($snapshot['fail'] ?? 0)
            . '/' . (int)($snapshot['skip'] ?? 0)
            . '/' . (int)($snapshot['timeout'] ?? 0)
            . ' '
            . 'eta=' . (is_int($etaMs) ? ConsoleTableFormatter::formatDurationMs($etaMs) : 'n/a');

        if (self::progressDetail() === 'verbose') {
            $currentRel = trim((string)($snapshot['current_test_rel'] ?? ''));
            $currentElapsedMs = $currentRel !== '' ? (int)($snapshot['current_elapsed_ms'] ?? 0) : null;
            $currentLabel = $currentRel !== ''
                ? UI::gray(ConsoleTableFormatter::formatProgressPath($currentRel, ConsoleRenderLimits::MAX_PROGRESS_CURRENT_LEN))
                : 'n/a';

            $line .= ' '
                . 'cur=' . $currentLabel
                . ' '
                . 'cur_el=' . ($currentElapsedMs !== null ? ConsoleTableFormatter::formatDurationMs($currentElapsedMs) : 'n/a')
                . ' '
                . 'avg=' . ConsoleTableFormatter::formatAvgMs(is_int($avgMs) ? $avgMs : null)
                . ' '
                . 'jobs=' . $jobs;

            $workers = ConsoleTableFormatter::formatWorkersSummary($snapshot['workers'] ?? []);
            if ($workers !== '') {
                $line .= ' workers=' . UI::gray($workers);
            }
        } elseif ($jobs > 1) {
            $line .= ' jobs=' . $jobs;
        }

        echo $line . "\n";
    }

    public static function printPerTestProgress(array $snapshot): void
    {
        $rel = trim((string)($snapshot['rel'] ?? ''));
        $status = strtoupper(trim((string)($snapshot['status'] ?? 'done')));
        $status = $status !== '' ? $status : 'DONE';
        $jobs = (int)($snapshot['jobs'] ?? 1);

        $line = UI::info('[Test]') . ' '
            . 'status=' . ConsoleTableFormatter::renderTestStatus($status)
            . ' '
            . 'done=' . (int)($snapshot['completed'] ?? 0) . '/' . (int)($snapshot['total'] ?? 0)
            . ' '
            . 'dur=' . ConsoleTableFormatter::formatDurationMs((int)($snapshot['duration_ms'] ?? 0))
            . ' '
            . 'rel=' . UI::gray(ConsoleTableFormatter::formatProgressPath($rel, ConsoleRenderLimits::MAX_TEST_REL_LEN));

        if ($jobs > 1) {
            $line .= ' worker=' . (int)($snapshot['worker'] ?? 0);
        }

        if (self::progressDetail() === 'verbose') {
            $line .= ' '
                . 'el=' . ConsoleTableFormatter::formatDurationMs((int)($snapshot['elapsed_ms'] ?? 0))
                . ' '
                . 'p/f/s/to=' . (int)($snapshot['pass'] ?? 0)
                . '/' . (int)($snapshot['fail'] ?? 0)
                . '/' . (int)($snapshot['skip'] ?? 0)
                . '/' . (int)($snapshot['timeout'] ?? 0)
                . ' '
                . 'jobs=' . $jobs;

            $workers = ConsoleTableFormatter::formatWorkersSummary($snapshot['workers'] ?? []);
            if ($workers !== '') {
                $line .= ' active=' . UI::gray($workers);
            }
        }

        echo $line . "\n";
    }

    public static function printLongRunningTest(array $warning): void
    {
        $rel = trim((string)($warning['rel'] ?? ''));
        if ($rel === '') {
            return;
        }

        echo UI::warning('[WARN]') . ' '
            . 'long_running_test'
            . ' '
            . 'elapsed=' . ConsoleTableFormatter::formatDurationMs((int)($warning['elapsed_ms'] ?? 0))
            . ' '
            . 'rel=' . UI::gray(ConsoleTableFormatter::formatProgressPath($rel, ConsoleRenderLimits::MAX_WARNING_REL_LEN))
            . ' '
            . 'worker=' . (int)($warning['worker'] ?? 0)
            . "\n";
    }

    public static function printPhaseTimings(array $phaseTimings): void
    {
        UI::section('Phase Timings');
        echo '  discovery_ms=' . (int)($phaseTimings['discovery'] ?? 0) . "\n";
        echo '  admission_ms=' . (int)($phaseTimings['admission'] ?? 0) . "\n";
        echo '  execution_ms=' . (int)($phaseTimings['execution'] ?? 0) . "\n";
        echo '  reporting_ms=' . (int)($phaseTimings['reporting'] ?? 0) . "\n";
    }

    private static function progressDetail(): string
    {
        return strtolower(Env::string('TESTKIT_PROGRESS_DETAIL', 'verbose')) === 'verbose'
            ? 'verbose'
            : 'compact';
    }

    private function __construct()
    {
    }
}
