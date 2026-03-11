<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

final class ConsoleReporter
{
    /**
     * @param array<string,mixed> $config
     */
    public static function printSuiteStart(array $config, int $testsCount): void
    {
        $name = strtoupper(str_replace('_', ' ', (string)$config['suite_id']));
        if (function_exists('pvt_ui_banner')) {
            pvt_ui_banner($name);
        }

        echo 'Running ' . $testsCount . ' tests';
        echo ' (scope=' . (string)$config['scope'];
        echo ', category=' . (string)$config['category'];
        echo ', failFast=' . ((bool)$config['fail_fast'] ? '1' : '0');
        echo ', jobs=' . (int)$config['jobs'] . ")\n";

        if ((bool)$config['coverage']) {
            echo 'Coverage: on (' . (string)$config['coverage_format'] . ') dir=' . (string)$config['coverage_dir'] . "\n";
        }

        if ((string)$config['match'] !== '') {
            echo 'Match: ' . (string)$config['match'] . "\n";
        }
    }

    /**
     * @param array<int,array<string,mixed>> $tests
     */
    public static function printList(array $tests): void
    {
        foreach ($tests as $test) {
            echo (string)$test['rel'] . "\n";
        }
    }

    /**
     * @param array<string,mixed> $result
     */
    public static function printSuiteResult(array $result): void
    {
        $pass = (int)($result['pass'] ?? 0);
        $fail = (int)($result['fail'] ?? 0);
        $skip = (int)($result['skip'] ?? 0);
        $duration = (int)($result['duration_ms'] ?? 0);

        $counts = function_exists('pvt_ui_counts')
            ? pvt_ui_counts($pass, $fail, $skip)
            : ('PASS=' . $pass . ' FAIL=' . $fail . ' SKIP=' . $skip);

        echo "\nSummary: {$counts} time_ms={$duration}\n";

        self::printModuleSummary($result);
        self::printFailures($result);
        self::printSlow($result);
        self::printFragility($result);
        self::printPerfViolations($result);
    }

    /**
     * @param array<string,mixed> $meta
     */
    public static function printMeta(array $meta): void
    {
        if (function_exists('pvt_ui_banner')) {
            pvt_ui_banner('META SUMMARY');
        }

        foreach (($meta['suites'] ?? []) as $suite) {
            $name = (string)($suite['suite_id'] ?? 'suite');
            $code = (int)($suite['exit_code'] ?? 1);
            if (function_exists('pvt_ui_summary_line')) {
                echo pvt_ui_summary_line($name, $code);
            } else {
                echo str_pad($name, 24) . ' -> code=' . $code . "\n";
            }
        }

        echo 'Total time_ms=' . (int)($meta['duration_ms'] ?? 0) . "\n";
    }

    /**
     * @param array<string,mixed> $result
     */
    private static function printModuleSummary(array $result): void
    {
        $summary = $result['module_summary'] ?? [];
        if (!is_array($summary) || !$summary) {
            return;
        }

        echo "\nModule summary\n";
        foreach ($summary as $module => $stat) {
            $pass = (int)($stat['pass'] ?? 0);
            $fail = (int)($stat['fail'] ?? 0);
            $skip = (int)($stat['skip'] ?? 0);
            $total = (int)($stat['total'] ?? 0);
            echo '  ' . str_pad((string)$module, 26) . ' total=' . $total . ' pass=' . $pass . ' fail=' . $fail . ' skip=' . $skip . "\n";
        }
    }

    /**
     * @param array<string,mixed> $result
     */
    private static function printFailures(array $result): void
    {
        $failed = $result['failed_tests'] ?? [];
        if (!is_array($failed) || !$failed) {
            return;
        }

        echo "\nFailed tests\n";
        foreach ($failed as $test) {
            $rel = (string)($test['rel'] ?? 'unknown');
            $code = (int)($test['exit_code'] ?? 1);
            echo '  - ' . $rel . ' (exit=' . $code . ")\n";

            $stderr = trim((string)($test['stderr'] ?? ''));
            $stdout = trim((string)($test['stdout'] ?? ''));
            $snippet = $stderr !== '' ? $stderr : $stdout;

            if ($snippet !== '') {
                $lines = preg_split('/\r\n|\r|\n/', $snippet) ?: [];
                $lines = array_slice($lines, 0, 10);
                foreach ($lines as $line) {
                    echo '      ' . $line . "\n";
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $result
     */
    private static function printSlow(array $result): void
    {
        $slow = $result['slow_tests'] ?? [];
        if (!is_array($slow) || !$slow) {
            return;
        }

        echo "\nSlow tests\n";
        foreach ($slow as $test) {
            echo '  - ' . str_pad((string)$test['duration_ms'], 6, ' ', STR_PAD_LEFT) . ' ms  ' . (string)$test['rel'] . "\n";
        }
    }

    /**
     * @param array<string,mixed> $result
     */
    private static function printFragility(array $result): void
    {
        $hints = $result['fragility_hints'] ?? [];
        if (!is_array($hints) || !$hints) {
            return;
        }

        echo "\nFragility hints\n";
        foreach ($hints as $hint) {
            if (($hint['type'] ?? '') !== 'flaky') {
                continue;
            }
            echo '  - flaky: ' . (string)$hint['test'] . ' (pass=' . (int)$hint['pass_count'] . ', fail=' . (int)$hint['fail_count'] . ")\n";
        }
    }

    /**
     * @param array<string,mixed> $result
     */
    private static function printPerfViolations(array $result): void
    {
        $violations = $result['perf_violations'] ?? [];
        if (!is_array($violations) || !$violations) {
            return;
        }

        echo "\nPerformance threshold violations\n";
        foreach ($violations as $entry) {
            $v = $entry['perf_violation'] ?? [];
            echo '  - ' . (string)$entry['rel'] . ' actual=' . (int)($v['actual_ms'] ?? 0) . 'ms max=' . (int)($v['max_ms'] ?? 0) . "ms\n";
        }
    }
}
