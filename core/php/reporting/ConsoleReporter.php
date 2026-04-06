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
            echo "  " . str_pad($name, 24) . " -> " . $status . UI::gray(" (code={$code})") . "\n";
        }

        echo "\n" . UI::bold("Total time_ms=") . UI::gray((string)($meta['duration_ms'] ?? 0)) . "\n";
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
        $failed = $result['failed_tests'] ?? [];
        if (!is_array($failed) || !$failed) {
            return;
        }

        UI::section("Failed Tests");
        foreach ($failed as $test) {
            $rel = (string)($test['rel'] ?? 'unknown');
            $code = (int)($test['exit_code'] ?? 1);
            echo "  " . UI::failure("X") . " {$rel} " . UI::gray("(exit={$code})") . "\n";

            $stderr = trim((string)($test['stderr'] ?? ''));
            $stdout = trim((string)($test['stdout'] ?? ''));
            $snippet = $stderr !== '' ? $stderr : $stdout;

            if ($snippet !== '') {
                $lines = preg_split('/\r\n|\r|\n/', $snippet) ?: [];
                $lines = array_slice($lines, 0, 8);
                foreach ($lines as $line) {
                    echo "    " . UI::gray("|") . " " . $line . "\n";
                }
                echo "    " . UI::gray("+-- (truncated)") . "\n";
            }
        }
        
        if ($failed) {
            $suiteId = (string)($result['suite_id'] ?? '');
            $target = str_replace('_', '-', $suiteId);
            echo "\n" . UI::bold("Next step:") . " " . UI::info("TEST_MATCH='{$failed[0]['rel']}' php runTest.php {$target}") . "\n";
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
            echo sprintf("  " . UI::warning("!") . " %6d ms  %s\n", (int)$test['duration_ms'], (string)$test['rel']);
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
