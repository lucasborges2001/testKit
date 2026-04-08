<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;
use Testkit\Core\Config\RunnerConfig;
use Testkit\Core\Reporting\ConsoleReporter;
use Testkit\Core\Reporting\ReportSummary;
use Testkit\Core\Reporting\ResultWriter;

final class MetaRunner
{
    public static function run(string $targetArg): int
    {
        $config = RunnerConfig::meta();
        $target = strtolower(trim($targetArg !== '' ? $targetArg : (string)$config['target']));
        if ($target === '') {
            $target = 'all';
        }

        $categoryTargets = ['smoke', 'perf', 'stress', 'contract', 'critical', 'slow'];
        if (in_array($target, $categoryTargets, true) && Env::string('TEST_CATEGORY', '') === '') {
            putenv('TEST_CATEGORY=' . $target);
        }

        $selected = self::resolveTarget($target);
        if (!$selected) {
            fwrite(STDERR, 'TEST_TARGET invalido: ' . $target . ". Valores: all|back|front|back-php|back-py|front-php|front-js|php|js|smoke|perf|stress|contract|critical|slow\n");
            return 3;
        }

        putenv('TEST_FAIL_FAST=' . ((bool)$config['child_fail_fast'] ? '1' : '0'));

        $metaStartedAt = gmdate('Y-m-d\TH:i:s\Z');
        $metaStart = self::nowMs();
        $suiteRows = [];
        $suiteReports = [];
        $overallFail = false;

        foreach ($selected as $suiteId) {
            $start = self::nowMs();
            $code = self::runSuite($suiteId);
            $duration = max(0, self::nowMs() - $start);

            $suiteRow = [
                'suite_id' => $suiteId,
                'exit_code' => $code,
                'duration_ms' => $duration,
            ];

            $suiteReport = ReportSummary::loadLatestSuiteReport($suiteId, array_filter([
                Paths::reportRootForSuite($suiteId) ?? '',
                Paths::aggregateMetaReportRoot(),
            ]));
            if (is_array($suiteReport)) {
                $suiteReports[] = $suiteReport;
                $suiteRow['report_root'] = (string)($suiteReport['report_root'] ?? '');
                $suiteRow['report_scope_rel'] = (string)($suiteReport['report_scope_rel'] ?? '');
                $suiteRow['selected_module_scope'] = (string)($suiteReport['selected_module_scope'] ?? '');
                $suiteRow['selected_test_count'] = (int)($suiteReport['selected_test_count'] ?? $suiteReport['tests_total'] ?? 0);
                $suiteRow['suite_status'] = (string)($suiteReport['suite_status'] ?? '');
                $suiteRow['no_tests_reason'] = (string)($suiteReport['no_tests_reason'] ?? '');
                $suiteRow['runner_capabilities'] = is_array($suiteReport['runner_capabilities'] ?? null) ? $suiteReport['runner_capabilities'] : [];
                $suiteRow['summary'] = is_array($suiteReport['summary'] ?? null) ? $suiteReport['summary'] : [];
                $suiteRow['has_failures'] = !empty(ReportSummary::canonicalFailures($suiteReport));
            }

            $suiteRows[] = $suiteRow;

            if ($code !== 0 && $code !== 2) {
                $overallFail = true;
                if ((bool)$config['meta_fail_fast']) {
                    break;
                }
            }
        }

        $reportRoot = Paths::aggregateMetaReportRoot();
        Paths::ensureDir($reportRoot);

        $meta = ReportSummary::buildMetaReport(
            $target,
            Env::string('TEST_CATEGORY', 'all'),
            $suiteRows,
            $suiteReports,
            $reportRoot,
            max(0, self::nowMs() - $metaStart),
            $metaStartedAt
        );

        ConsoleReporter::printMeta($meta);
        ResultWriter::writeMeta($meta);

        if ($overallFail) {
            echo "\n[Action Required]\n";
            echo "Alguna suite falló. Revisá los logs de arriba o corré el reporte detallado:\n";
            echo "  php scripts/report.php\n";
        }

        return $overallFail ? 1 : 0;
    }

    /**
     * @param array<int,string> $suites
     */
    private static function printStartupSummary(string $target, array $suites): void
    {
        $category = Env::string('TEST_CATEGORY', 'all');
        $scope = Env::string('TEST_SCOPE', 'all');
        $match = Env::string('TEST_MATCH', '');

        UI::header("TESTKIT STARTUP");
        UI::label("Target", $target . (Env::string('TESTKIT_TARGET_' . strtoupper(str_replace('-', '_', $target)), '') !== '' ? " (override)" : ""));
        UI::label("Suites", implode(', ', $suites));
        UI::label("Filters", "scope={$scope}, category={$category}" . ($match !== '' ? ", match={$match}" : ""));

        $overrides = [];
        foreach (['TK_BACK_PHP_DIR', 'TK_BACK_PYTHON_DIR', 'TK_FRONT_PHP_DIR', 'TK_FRONT_JS_DIR', 'TK_MODULE_LEVEL', 'TK_TAG_MAP'] as $key) {
            $val = Env::string($key, '');
            if ($val !== '') {
                $overrides[] = "{$key}={$val}";
            }
        }

        if ($overrides !== []) {
            UI::label("Context", implode(', ', $overrides));
        }
        UI::separator();
    }

    /**
     * @return array<int,string>
     */
    private static function resolveTarget(string $target): array
    {
        $map = [
            'all' => ['back_php', 'back_python', 'front_php', 'front_js'],
            'back' => ['back_php', 'back_python'],
            'front' => ['front_php', 'front_js'],
            'public_html' => ['front_php', 'front_js'],
            'back-php' => ['back_php'],
            'back-py' => ['back_python'],
            'back-python' => ['back_python'],
            'python' => ['back_python'],
            'py' => ['back_python'],
            'front-php' => ['front_php'],
            'front-js' => ['front_js'],
            'php' => ['back_php', 'front_php'],
            'js' => ['front_js'],
            'smoke' => ['back_php', 'back_python', 'front_php', 'front_js'],
            'perf' => ['back_php', 'back_python', 'front_php', 'front_js'],
            'stress' => ['back_php', 'back_python', 'front_php', 'front_js'],
            'contract' => ['back_php', 'back_python', 'front_php', 'front_js'],
            'critical' => ['back_php', 'back_python', 'front_php', 'front_js'],
            'slow' => ['back_php', 'back_python', 'front_php', 'front_js'],
        ];

        $envKey = 'TESTKIT_TARGET_' . strtoupper(str_replace('-', '_', $target));
        $envVal = Env::string($envKey, '');

        if ($envVal !== '') {
            $parts = array_filter(array_map('trim', explode(',', $envVal)));
            $suites = [];
            $validSuites = ['back_php', 'back_python', 'front_php', 'front_js'];

            foreach ($parts as $suite) {
                if (!in_array($suite, $validSuites, true)) {
                    fwrite(STDERR, "Error en {$envKey}: suite '{$suite}' no reconocida. Valores validos: " . implode('|', $validSuites) . "\n");
                    exit(3);
                }
                $suites[] = $suite;
            }
            return array_values(array_unique($suites));
        }

        return $map[$target] ?? [];
    }

    private static function runSuite(string $suiteId): int
    {
        return match ($suiteId) {
            'back_php' => BackPhpSuite::run(),
            'back_python' => BackPythonSuite::run(),
            'front_php' => FrontPhpSuite::run(),
            'front_js' => FrontJsSuite::run(),
            default => 3,
        };
    }

    private static function nowMs(): int
    {
        return (int)round(microtime(true) * 1000);
    }
}