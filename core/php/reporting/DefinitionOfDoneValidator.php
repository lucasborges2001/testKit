<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Testkit\Core\Common\Paths;

final class DefinitionOfDoneValidator
{
    /**
     * @param array<int,string> $argv
     */
    public static function runCli(array $argv): int
    {
        [$json, $runId] = self::parseArgs($argv);
        $payload = self::evaluate($runId);

        if ($json) {
            $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($encoded) || $encoded === '') {
                throw new \RuntimeException('No se pudo serializar definition_of_done.');
            }
            echo $encoded . PHP_EOL;
        } else {
            self::printText($payload);
        }

        return (bool)($payload['closable'] ?? false) ? 0 : 1;
    }

    /**
     * @return array<string,mixed>
     */
    public static function evaluate(string $requestedRunId = ''): array
    {
        $context = self::resolveRunContext($requestedRunId);
        $meta = self::loadMetaReport($context['report_root']);
        $suiteReports = self::loadSuiteReports($context['report_root']);
        $source = self::sourceSnapshot();

        $checks = [
            self::checkConcurrencyIsolation($suiteReports, $source),
            self::checkConcurrencyReporting($suiteReports, $source),
            self::checkSeedStateVisible($suiteReports, $source),
            self::checkTestsStopParsingBootstrapEnv($source),
            self::checkFirstFailureTopLevel($suiteReports, $source),
            self::checkFailureKindsDifferentiated($source),
            self::checkCanonicalJsonVersioned($meta, $suiteReports),
            self::checkInspectCoverage($source),
            self::checkWarningsStructured($suiteReports, $source),
            self::checkSignalNoise($source),
            self::checkAgentCanChooseNextStep($source),
            self::checkAgentRunNotOpaque($source),
        ];

        $summary = [
            'total' => count($checks),
            'passed' => 0,
            'failed' => 0,
        ];
        $blocking = [];
        foreach ($checks as $check) {
            if ((string)($check['status'] ?? 'fail') === 'pass') {
                $summary['passed']++;
                continue;
            }
            $summary['failed']++;
            $blocking[] = (string)($check['label'] ?? 'check');
        }

        return [
            'ok' => true,
            'kind' => 'definition_of_done',
            'closable' => $summary['failed'] === 0,
            'run_id' => $context['run_id'],
            'report_root' => $context['report_root'],
            'report_scope_rel' => $context['report_scope_rel'],
            'summary' => $summary,
            'blocking_items' => $blocking,
            'checks' => $checks,
        ];
    }

    /**
     * @param array<int,string> $argv
     * @return array{0:bool,1:string}
     */
    private static function parseArgs(array $argv): array
    {
        $json = false;
        $runId = '';
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--json') {
                $json = true;
                continue;
            }
            if (str_starts_with($arg, '--run=')) {
                $runId = trim((string)substr($arg, strlen('--run=')));
            }
        }

        return [$json, $runId];
    }

    /**
     * @return array<string,mixed>
     */
    private static function resolveRunContext(string $requestedRunId = ''): array
    {
        $requestedRunId = trim($requestedRunId);
        if ($requestedRunId !== '') {
            $root = Paths::reportRunRoot($requestedRunId);
            return [
                'run_id' => $requestedRunId,
                'report_root' => Paths::normalize($root),
                'report_scope_rel' => Paths::relativeToRepo($root),
            ];
        }

        $manifest = self::loadJsonFile(Paths::latestRunManifestPath());
        if (is_array($manifest)) {
            $root = trim((string)($manifest['report_root'] ?? ''));
            if ($root !== '') {
                return [
                    'run_id' => (string)($manifest['run_id'] ?? ''),
                    'report_root' => Paths::normalize($root),
                    'report_scope_rel' => (string)($manifest['report_scope_rel'] ?? Paths::relativeToRepo($root)),
                ];
            }
        }

        return [
            'run_id' => '',
            'report_root' => Paths::reportsRoot(),
            'report_scope_rel' => Paths::relativeToRepo(Paths::reportsRoot()),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function loadMetaReport(string $reportRoot): ?array
    {
        return self::loadJsonFile(rtrim($reportRoot, '/\\') . '/meta_latest.json');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function loadSuiteReports(string $reportRoot): array
    {
        $reports = [];
        foreach (glob(rtrim($reportRoot, '/\\') . '/*_latest.json') ?: [] as $file) {
            $name = basename($file);
            if ($name === 'meta_latest.json' || str_contains($name, '__')) {
                continue;
            }

            $json = self::loadJsonFile($file);
            if (!is_array($json) || !isset($json['suite_id'])) {
                continue;
            }
            $json['_source_file'] = $file;
            $reports[] = $json;
        }

        usort($reports, static fn(array $a, array $b): int => strcmp((string)($a['suite_id'] ?? ''), (string)($b['suite_id'] ?? '')));
        return $reports;
    }

    /**
     * @return array<string,mixed>
     */
    private static function sourceSnapshot(): array
    {
        return [
            'parallel_guard' => Paths::relativeToRepo(Paths::testkitRoot() . '/core/php/execution/ParallelGuard.php'),
            'suite_orchestrator' => Paths::relativeToRepo(Paths::testkitRoot() . '/core/php/suites/SuiteOrchestrator.php'),
            'front_js_suite' => Paths::relativeToRepo(Paths::testkitRoot() . '/core/php/suites/FrontJsSuite.php'),
            'canonical_report' => Paths::relativeToRepo(Paths::testkitRoot() . '/core/php/reporting/CanonicalReport.php'),
            'structured_warnings' => Paths::relativeToRepo(Paths::testkitRoot() . '/core/php/reporting/StructuredWarnings.php'),
            'console_reporter' => Paths::relativeToRepo(Paths::testkitRoot() . '/core/php/reporting/ConsoleReporter.php'),
            'report_summary' => Paths::relativeToRepo(Paths::testkitRoot() . '/core/php/reporting/ReportSummary.php'),
            'inspector' => Paths::relativeToRepo(Paths::testkitRoot() . '/core/php/reporting/Inspector.php'),
            'agent_run' => Paths::relativeToRepo(Paths::testkitRoot() . '/core/php/reporting/AgentRun.php'),
            'suite_seed_state' => Paths::relativeToRepo(Paths::testkitRoot() . '/core/php/seeding/SuiteSeedState.php'),
            'suite_contract_registry' => Paths::relativeToRepo(Paths::testkitRoot() . '/core/php/config/SuiteContractRegistry.php'),
            'test_seed_metadata' => Paths::relativeToRepo(Paths::testkitRoot() . '/core/php/discovery/TestSeedMetadata.php'),
            'back_php_suite' => Paths::relativeToRepo(Paths::testkitRoot() . '/core/php/suites/BackPhpSuite.php'),
            'front_php_suite' => Paths::relativeToRepo(Paths::testkitRoot() . '/core/php/suites/FrontPhpSuite.php'),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $suiteReports
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private static function checkConcurrencyIsolation(array $suiteReports, array $source): array
    {
        $sourceOk = self::fileContains($source['parallel_guard'], 'acquireSuiteStoreLock(')
            && self::fileContains($source['parallel_guard'], 'rejectedByLockState(')
            && self::fileContains($source['parallel_guard'], 'rejectedByPolicyState(');

        $runtimeOk = $suiteReports !== [] && self::allReportsSatisfy($suiteReports, static function (array $report): bool {
            $admission = $report['concurrency_admission'] ?? null;
            $canonical = $report['canonical_report'] ?? null;
            $evidence = is_array($canonical['evidence'] ?? null) ? $canonical['evidence'] : [];
            return is_array($admission)
                && array_key_exists('run_admitted', $admission)
                && array_key_exists('reason', $admission)
                && array_key_exists('valid', $evidence);
        });

        return self::check(
            'Concurrencia',
            'una corrida incompatible no puede contaminar silenciosamente otra',
            $sourceOk && $runtimeOk,
            $sourceOk && !$runtimeOk
                ? 'La defensa existe en código, pero no hay evidencia suficiente en reportes latest de que se esté emitiendo en runtime.'
                : 'Falta defensa explícita de lock/admisión o falta evidencia runtime en reportes canónicos.',
            [$source['parallel_guard'], 'runtime:' . count($suiteReports) . '_suite_reports']
        );
    }

    /**
     * @param array<int,array<string,mixed>> $suiteReports
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private static function checkConcurrencyReporting(array $suiteReports, array $source): array
    {
        $sourceOk = self::fileContains($source['suite_orchestrator'], 'concurrency_admission')
            && self::fileContains($source['suite_orchestrator'], 'parallel_policy')
            && self::fileContains($source['front_js_suite'], 'concurrency_admission')
            && self::fileContains($source['front_js_suite'], 'parallel_policy');

        $runtimeOk = $suiteReports !== [] && self::allReportsSatisfy($suiteReports, static function (array $report): bool {
            $admission = $report['concurrency_admission'] ?? null;
            $policy = $report['parallel_policy'] ?? null;
            return is_array($admission)
                && is_array($policy)
                && array_key_exists('store_mode', $admission)
                && array_key_exists('concurrency_policy', $admission)
                && array_key_exists('db_strategy', $policy);
        });

        return self::check(
            'Concurrencia',
            'el runner informa si rechazó, serializó o aisló',
            $sourceOk && $runtimeOk,
            'Faltan campos de admisión/política en runtime o falta paridad entre suites.',
            [$source['suite_orchestrator'], $source['front_js_suite']]
        );
    }

    /**
     * @param array<int,array<string,mixed>> $suiteReports
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private static function checkSeedStateVisible(array $suiteReports, array $source): array
    {
        $sourceOk = self::fileExists($source['suite_seed_state'])
            && self::fileContains($source['suite_orchestrator'], 'SuiteSeedState::attachToReport(')
            && self::fileContains($source['front_js_suite'], 'SuiteSeedState::attachToReport(')
            && self::fileContains($source['canonical_report'], "'seed_state' => self::seedState(");

        $runtimeOk = $suiteReports !== [] && self::allReportsSatisfy($suiteReports, static function (array $report): bool {
            $canonical = $report['canonical_report'] ?? null;
            return is_array($canonical) && array_key_exists('seed_state', $canonical);
        });

        return self::check(
            'Seed/bootstrap',
            'el seed mode real del run es visible y estable',
            $sourceOk && $runtimeOk,
            'El wiring de seed_state existe, pero todavía no hay suficiente evidencia runtime o falta el campo en reportes canónicos.',
            [$source['suite_seed_state'], $source['suite_orchestrator'], $source['front_js_suite']]
        );
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private static function checkTestsStopParsingBootstrapEnv(array $source): array
    {
        $stillParsing = self::fileExists($source['test_seed_metadata'])
            || self::fileContains($source['back_php_suite'], 'TestSeedMetadata::applySeedEnv(')
            || self::fileContains($source['front_php_suite'], 'TestSeedMetadata::applySeedEnv(');

        return self::check(
            'Seed/bootstrap',
            'los tests dejan de parsear env vars para entender bootstrap',
            !$stillParsing,
            'Sigue existiendo acople entre discovery/tests y bootstrap via TestSeedMetadata / TEST_SEED_MIGRATIONS. Este punto no está cerrado.',
            [$source['test_seed_metadata'], $source['back_php_suite'], $source['front_php_suite']]
        );
    }

    /**
     * @param array<int,array<string,mixed>> $suiteReports
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private static function checkFirstFailureTopLevel(array $suiteReports, array $source): array
    {
        $sourceOk = self::fileContains($source['canonical_report'], "'first_failure' =>")
            && self::fileContains($source['report_summary'], 'public static function firstFailure(')
            && self::fileContains($source['report_summary'], 'public static function summarizeFailure(');

        $runtimeOk = $suiteReports !== [] && self::allReportsSatisfy($suiteReports, static function (array $report): bool {
            $canonical = $report['canonical_report'] ?? null;
            $evidence = is_array($canonical['evidence'] ?? null) ? $canonical['evidence'] : [];
            return array_key_exists('first_failure', $evidence);
        });

        return self::check(
            'Diagnóstico',
            'el primer fallo útil sale como dato de primer nivel',
            $sourceOk && $runtimeOk,
            'Falta first_failure en capa canónica o no aparece de forma estable en reportes latest.',
            [$source['canonical_report'], $source['report_summary']]
        );
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private static function checkFailureKindsDifferentiated(array $source): array
    {
        $ok = self::fileContains($source['report_summary'], 'setup_failure')
            && self::fileContains($source['report_summary'], 'test_failure')
            && self::fileContains($source['report_summary'], 'environment_conflict');

        return self::check(
            'Diagnóstico',
            'setup failure y domain failure quedan diferenciados',
            $ok,
            'No aparecen clases de fallo suficientemente diferenciadas en ReportSummary.',
            [$source['report_summary']]
        );
    }

    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     * @return array<string,mixed>
     */
    private static function checkCanonicalJsonVersioned(?array $meta, array $suiteReports): array
    {
        $reports = $suiteReports;
        if (is_array($meta)) {
            $reports[] = $meta;
        }

        $ok = $reports !== [] && self::allReportsSatisfy($reports, static function (array $report): bool {
            $canonical = $report['canonical_report'] ?? null;
            return is_array($canonical) && (int)($canonical['report_version'] ?? 0) > 0;
        });

        return self::check(
            'Observabilidad',
            'existe una salida JSON canónica versionada',
            $ok,
            'No hay reportes canónicos versionados suficientes en latest run.',
            ['runtime:' . count($reports) . '_reports']
        );
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private static function checkInspectCoverage(array $source): array
    {
        $ok = self::fileContains($source['inspector'], "'latest' =>")
            && self::fileContains($source['inspector'], "'failure' =>")
            && self::fileContains($source['inspector'], "'seed-state' =>")
            && self::fileContains($source['inspector'], "'concurrency' =>");

        return self::check(
            'Observabilidad',
            '`inspect` cubre latest, failure, seed-state y concurrency',
            $ok,
            'Inspector no expone uno o más subcomandos requeridos por 0200.',
            [$source['inspector']]
        );
    }

    /**
     * @param array<int,array<string,mixed>> $suiteReports
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private static function checkWarningsStructured(array $suiteReports, array $source): array
    {
        $sourceOk = self::fileContains($source['structured_warnings'], 'classification')
            && self::fileContains($source['canonical_report'], "'warnings' => self::warnings(");

        $runtimeOk = $suiteReports === [] ? false : self::allWarningsStructured($suiteReports);

        return self::check(
            'Ruido operacional',
            'los warnings frecuentes tienen código, severidad y clasificación',
            $sourceOk && $runtimeOk,
            'La estructura de warnings no está cerrada o no aparece consistentemente en reportes runtime.',
            [$source['structured_warnings'], $source['canonical_report']]
        );
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private static function checkSignalNoise(array $source): array
    {
        $ok = self::fileContains($source['console_reporter'], 'First failure:')
            && self::fileContains($source['console_reporter'], 'Triage Summary')
            && self::fileContains($source['console_reporter'], 'Summary:');

        return self::check(
            'Ruido operacional',
            'la salida principal mejora señal/ruido',
            $ok,
            'ConsoleReporter todavía no muestra suficiente priorización de summary / first_failure / triage.',
            [$source['console_reporter']]
        );
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private static function checkAgentCanChooseNextStep(array $source): array
    {
        $ok = self::fileContains($source['agent_run'], 'first_actionable_failure')
            && self::fileContains($source['agent_run'], "'next_action' =>")
            && self::fileContains($source['agent_run'], 'rerun_single_file')
            && self::fileContains($source['agent_run'], 'inspect_concurrency');

        return self::check(
            'Uso por agentes',
            'un agente puede decidir el siguiente paso sin abrir artefactos secundarios en la mayoría de los casos',
            $ok,
            'AgentRun no deja suficientemente explícita la decisión de siguiente paso.',
            [$source['agent_run']]
        );
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private static function checkAgentRunNotOpaque(array $source): array
    {
        $ok = self::fileContains($source['agent_run'], 'decision_basis')
            && self::fileContains($source['agent_run'], 'uses_canonical_report_only')
            && self::fileContains($source['agent_run'], 'rules');

        return self::check(
            'Uso por agentes',
            '`agent-run` no oculta estados reales ni inventa heurísticas opacas',
            $ok,
            'AgentRun no declara suficientemente su base de decisión o sigue siendo opaco.',
            [$source['agent_run']]
        );
    }

    /**
     * @param array<int,array<string,mixed>> $suiteReports
     */
    private static function allWarningsStructured(array $suiteReports): bool
    {
        foreach ($suiteReports as $report) {
            $canonical = $report['canonical_report'] ?? null;
            $warnings = is_array($canonical['warnings'] ?? null) ? $canonical['warnings'] : [];
            foreach ($warnings as $warning) {
                if (!is_array($warning)) {
                    return false;
                }
                foreach (['code', 'severity', 'classification', 'blocking', 'summary'] as $key) {
                    if (!array_key_exists($key, $warning)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * @param array<int,array<string,mixed>> $reports
     */
    private static function allReportsSatisfy(array $reports, callable $predicate): bool
    {
        foreach ($reports as $report) {
            if (!$predicate($report)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    private static function check(string $section, string $label, bool $pass, string $failMessage, array $evidence = []): array
    {
        return [
            'section' => $section,
            'label' => $label,
            'status' => $pass ? 'pass' : 'fail',
            'message' => $pass ? 'OK' : $failMessage,
            'evidence' => array_values(array_filter(array_map('strval', $evidence), static fn(string $item): bool => $item !== '')),
        ];
    }

    private static function fileExists(string $relativePath): bool
    {
        return is_file(Paths::repoRoot() . '/' . $relativePath) || is_file(Paths::testkitRoot() . '/' . ltrim($relativePath, '/'));
    }

    private static function fileContains(string $relativePath, string $needle): bool
    {
        $candidates = [
            Paths::repoRoot() . '/' . $relativePath,
            Paths::testkitRoot() . '/' . ltrim($relativePath, '/'),
        ];

        foreach ($candidates as $path) {
            if (!is_file($path)) {
                continue;
            }
            $raw = file_get_contents($path);
            if (is_string($raw) && str_contains($raw, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function loadJsonFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $json = json_decode($raw, true);
        return is_array($json) ? $json : null;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function printText(array $payload): void
    {
        echo 'definition_of_done' . PHP_EOL;
        echo str_repeat('=', 72) . PHP_EOL;
        echo 'closable: ' . ((bool)($payload['closable'] ?? false) ? 'true' : 'false') . PHP_EOL;
        echo 'run_id: ' . (string)($payload['run_id'] ?? '') . PHP_EOL;
        echo 'report_root: ' . (string)($payload['report_scope_rel'] ?? $payload['report_root'] ?? '') . PHP_EOL;

        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        echo 'summary: passed=' . (int)($summary['passed'] ?? 0)
            . ' failed=' . (int)($summary['failed'] ?? 0)
            . ' total=' . (int)($summary['total'] ?? 0)
            . PHP_EOL;

        echo PHP_EOL . 'checks' . PHP_EOL;
        echo str_repeat('-', 72) . PHP_EOL;
        foreach ((array)($payload['checks'] ?? []) as $check) {
            if (!is_array($check)) {
                continue;
            }
            $status = strtoupper((string)($check['status'] ?? 'fail'));
            echo '[' . $status . '] ' . (string)($check['section'] ?? '') . ' :: ' . (string)($check['label'] ?? '') . PHP_EOL;
            echo '  ' . (string)($check['message'] ?? '') . PHP_EOL;
            foreach ((array)($check['evidence'] ?? []) as $evidence) {
                echo '    - ' . (string)$evidence . PHP_EOL;
            }
        }

        $blocking = array_values((array)($payload['blocking_items'] ?? []));
        if ($blocking !== []) {
            echo PHP_EOL . 'blocking_items' . PHP_EOL;
            echo str_repeat('-', 72) . PHP_EOL;
            foreach ($blocking as $item) {
                echo '- ' . (string)$item . PHP_EOL;
            }
        }
    }
}
