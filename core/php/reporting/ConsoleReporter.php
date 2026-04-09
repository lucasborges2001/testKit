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
        UI::header($name);

        echo UI::bold("Running {$testsCount} tests");
        echo " (scope=" . UI::gray((string)$config['scope']);
        echo ", category=" . UI::gray((string)$config['category']);
        echo ", failFast=" . UI::gray((bool)$config['fail_fast'] ? '1' : '0');
        echo ", jobs=" . UI::gray((string)$config['jobs']) . ")\n";

        if ((bool)$config['coverage']) {
            echo "Coverage: " . UI::success("on") . " (" . (string)$config['coverage_format'] . ") dir=" . UI::gray((string)$config['coverage_dir']) . "\n";
        }

        if ((string)$config['match'] !== '') {
            echo "Match:    " . UI::info((string)$config['match']) . "\n";
        }
    }

    /**
     * @param array<int,array<string,mixed>> $tests
     */
    public static function printList(array $tests): void
    {
        foreach ($tests as $test) {
            echo "  " . UI::gray((string)$test['rel']) . "\n";
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

        $p = UI::success("PASS={$pass}");
        $f = $fail > 0 ? UI::failure("FAIL={$fail}") : UI::gray("FAIL={$fail}");
        $s = $skip > 0 ? UI::warning("SKIP={$skip}") : UI::gray("SKIP={$skip}");

        echo "\n" . UI::bold("Summary:") . " {$p} {$f} {$s} " . UI::gray("time_ms={$duration}") . "\n";

        self::printModuleSummary($result);
        self::printFailures($result);
        self::printTriage($result);
        self::printSlow($result);
        self::printFragility($result);
        self::printPerfViolations($result);
    }

    /**
     * @param array<string,mixed> $meta
     */
    public static function printMeta(array $meta): void
    {
        UI::header("META SUMMARY");

        foreach (($meta['suites'] ?? []) as $suite) {
            $name = (string)($suite['suite_id'] ?? 'suite');
            $code = (int)($suite['exit_code'] ?? 1);
            $status = $code === 0 ? UI::success("OK") : ($code === 2 ? UI::warning("SKIP") : UI::failure("FAIL"));
            $tests = (int)($suite['selected_test_count'] ?? 0);
            $scope = trim((string)($suite['selected_module_scope'] ?? ''));
            $scopeLabel = $scope !== '' ? $scope : 'global';
            echo "  " . str_pad($name, 24) . " -> " . $status . UI::gray(" (code={$code}, tests={$tests}, scope={$scopeLabel})") . "\n";
        }

        $summary = is_array($meta['summary'] ?? null) ? $meta['summary'] : [];
        $failedFiles = is_array($meta['failed_files'] ?? null) ? $meta['failed_files'] : [];
        echo "\n" . UI::bold("Meta:") . " ";
        echo UI::gray('total=' . (int)($summary['total'] ?? 0));
        echo ' ' . UI::gray('pass=' . (int)($summary['passed'] ?? 0));
        echo ' ' . ((int)($summary['failed'] ?? 0) > 0 ? UI::failure('fail=' . (int)($summary['failed'] ?? 0)) : UI::gray('fail=' . (int)($summary['failed'] ?? 0)));
        echo ' ' . UI::gray('skip=' . (int)($summary['skipped'] ?? 0));
        echo ' ' . UI::gray('time_ms=' . (int)($summary['duration_ms'] ?? $meta['duration_ms'] ?? 0));
        echo "\n";
        echo UI::gray('report_root=' . (string)($meta['report_scope_rel'] ?? $meta['report_root'] ?? '')) . "\n";
        echo UI::gray('selected_tests=' . (int)($meta['selected_test_count'] ?? 0) . ', failed_files=' . count($failedFiles)) . "\n";
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

        UI::section("Module Summary");
        echo UI::gray(str_pad("Module", 30) . " | Total | Pass | Fail | Skip") . "\n";
        UI::separator();
        foreach ($summary as $module => $stat) {
            $pass = (int)($stat['pass'] ?? 0);
            $fail = (int)($stat['fail'] ?? 0);
            $skip = (int)($stat['skip'] ?? 0);
            $total = (int)($stat['total'] ?? 0);

            $moduleStr = str_pad((string)$module, 30);
            $f = $fail > 0 ? UI::failure(sprintf("%4d", $fail)) : sprintf("%4d", $fail);
            echo sprintf("%s | %5d | %4d | %s | %4d\n", $moduleStr, $total, $pass, $f, $skip);
        }
    }

    /**
     * @param array<string,mixed> $result
     */
    private static function printFailures(array $result): void
    {
        $failures = ReportSummary::canonicalFailures($result);
        if ($failures === []) {
            return;
        }

        UI::section("Failed Tests");
        foreach ($failures as $failure) {
            $rel = (string)($failure['file'] ?? $failure['test_id'] ?? 'unknown');
            $code = (string)($failure['error_type'] ?? 'fail');
            echo "  " . UI::failure("X") . " {$rel} " . UI::gray("({$code})") . "\n";

            foreach (['message', 'assertion', 'trace_excerpt', 'stderr_excerpt', 'stdout_excerpt'] as $field) {
                $snippet = trim((string)($failure[$field] ?? ''));
                if ($snippet === '') {
                    continue;
                }
                $lines = preg_split('/\r\n|\r|\n/', $snippet) ?: [];
                $lines = array_slice($lines, 0, 8);
                foreach ($lines as $line) {
                    echo "    " . UI::gray("|") . " " . $line . "\n";
                }
                if (count($lines) > 0) {
                    echo "    " . UI::gray("+-- ({$field})") . "\n";
                }
                break;
            }
        }

        $first = $failures[0];
        $firstFile = (string)($first['file'] ?? $first['test_id'] ?? '');
        if ($firstFile !== '') {
            $suiteId = (string)($result['suite_id'] ?? '');
            $target = str_replace('_', '-', $suiteId);
            echo "\n" . UI::bold("Next step:") . " " . UI::info("TEST_MATCH='{$firstFile}' php runTest.php {$target}") . "\n";
        }
    }

    /**
     * @param array<string,mixed> $result
     */
    private static function printTriage(array $result): void
    {
        $failures = ReportSummary::canonicalFailures($result);
        if ($failures === []) {
            return;
        }

        $summary = $result['triage_summary'] ?? null;
        if (!is_array($summary) || $summary === []) {
            $summary = FailureClassifier::summarize($failures, 4);
        }

        if ($summary === []) {
            return;
        }

        UI::section("Triage Summary");
        foreach ($summary as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = (string)($row['label'] ?? $row['family'] ?? 'unknown');
            $count = (int)($row['count'] ?? 0);
            $next = trim((string)($row['next_step'] ?? ''));
            echo "  - " . UI::warning($label) . " x{$count}\n";

            $examples = is_array($row['examples'] ?? null) ? $row['examples'] : [];
            foreach (array_slice($examples, 0, 2) as $example) {
                if (!is_array($example)) {
                    continue;
                }
                $file = trim((string)($example['file'] ?? ''));
                $message = trim((string)($example['message'] ?? ''));
                if ($file !== '') {
                    echo "      * " . $file;
                    if ($message !== '') {
                        echo ' -> ' . $message;
                    }
                    echo "\n";
                }
            }

            if ($next !== '') {
                echo "      " . UI::gray("action: {$next}") . "\n";
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

        UI::section("Slow Tests");
        foreach ($slow as $test) {
            echo sprintf("  " . UI::warning("!") . " %6d ms  %s\n", (int)$test['duration_ms'], (string)($test['rel'] ?? $test['file'] ?? 'unknown'));
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
            echo '  - flaky: ' . (string)$hint['test'] . ' (pass=' . (int)($hint['pass_count'] ?? 0) . ', fail=' . (int)($hint['fail_count'] ?? 0) . ")\n";
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
