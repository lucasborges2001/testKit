<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;
use Testkit\Core\Reporting\ReportSummary;

final class MetaOperationalFailureBuilder
{
    /**
     * @param array<string,mixed> $admission
     * @return array<string,mixed>
     */
    public static function build(
        string $target,
        string $category,
        string $reportRoot,
        int $durationMs,
        string $startedAt,
        string $runId,
        array $admission,
        string $phase,
        \Throwable $error
    ): array {
        Paths::ensureDir($reportRoot);

        $lockConflict = self::isLockConflict($admission);
        $analysis = $lockConflict
            ? self::lockConflictAnalysis($admission)
            : self::analyzeThrowable($error, $phase);

        $failurePhase = $lockConflict ? 'store_setup' : $phase;
        $failureDomain = $lockConflict ? 'store' : ($failurePhase === 'reporting' ? 'reporting' : 'infra');
        $causeCode = (string)$analysis['cause_code'];

        $failure = ReportSummary::buildThrowableFailure($error, [
            'test_id' => 'meta.run',
            'test_name' => 'meta.run',
            'case' => 'meta.run',
            'suite_id' => 'meta',
            'suite' => 'meta',
            'scope' => Env::string('TEST_SCOPE', 'all'),
            'category' => $category,
            'kind' => $lockConflict ? 'environment_conflict' : 'setup_failure',
            'phase' => $failurePhase,
            'failure_domain' => $failureDomain,
            'cause_code' => $causeCode,
            'artifact_path' => Paths::relativeToRepo($reportRoot),
        ]);

        $throwablePayload = self::throwablePayload($error, $analysis, $phase, $target);
        $failure = array_merge($failure, $throwablePayload);

        $meta = [
            'target' => $target,
            'category' => $category,
            'started_at' => $startedAt,
            'duration_ms' => $durationMs,
            'report_root' => $reportRoot,
            'report_scope_rel' => Paths::relativeToRepo($reportRoot),
            'selected_module_scope' => '',
            'selected_test_count' => 0,
            'suite_status_counts' => [],
            'outcome_status_counts' => [],
            'summary' => [
                'total' => 0,
                'passed' => 0,
                'failed' => 0,
                'skipped' => 0,
                'duration_ms' => $durationMs,
            ],
            'failures' => [$failure],
            'failure_contract' => [
                'canonical' => 'failures',
                'legacy_fallback' => 'suites[].has_failures',
            ],
            'first_failure' => ReportSummary::summarizeFailure($failure),
            'evidence_valid' => false,
            'evidence_invalid_reason' => $causeCode,
            'failed_files' => [],
            'top_failure_messages' => ReportSummary::topFailureMessages([$failure], 5),
            'suite_ids' => [],
            'has_failures' => true,
            'suites' => [],
            'run_id' => $runId,
            'meta_run_id' => $runId,
            'run_kind' => 'meta',
            'concurrency_admission' => $admission,
            'infra_error' => $throwablePayload,
            'filters' => [
                'target' => $target,
                'scope' => Env::string('TEST_SCOPE', 'all'),
                'category' => $category,
                'match' => Env::string('TEST_MATCH', ''),
            ],
        ];

        return ReportSummary::enrichReport($meta);
    }

    /** @param array<string,mixed> $admission */
    private static function isLockConflict(array $admission): bool
    {
        return in_array((string)($admission['reason'] ?? ''), ['shared_store_locked', 'store_resource_locked'], true);
    }

    /** @param array<string,mixed> $admission @return array<string,string> */
    private static function lockConflictAnalysis(array $admission): array
    {
        return [
            'cause_code' => (string)($admission['reason'] ?? 'shared_store_locked'),
            'root_cause' => 'store_resource_conflict',
            'operator_hint' => 'Hay otro run usando el recurso compartido. Revisar lock/resource en el reporte y reintentar cuando se libere.',
        ];
    }

    /** @return array<string,string> */
    private static function analyzeThrowable(\Throwable $error, string $phase): array
    {
        $message = trim($error->getMessage());
        $class = ltrim(get_class($error), '\\');
        $lower = strtolower($message);

        if (preg_match('/^(class|interface|trait) "[^"]+" not found$/i', $message) === 1) {
            return [
                'cause_code' => 'class_not_found',
                'root_cause' => 'missing_bootstrap_or_autoload',
                'operator_hint' => 'Una clase usada por TestKit no fue cargada. Revisar core/php/bootstrap.php o el bootstrap del módulo nuevo.',
            ];
        }

        if (str_contains($lower, 'failed opening required') || str_contains($lower, 'failed to open stream') || str_contains($lower, 'no such file or directory')) {
            return [
                'cause_code' => 'missing_file',
                'root_cause' => 'missing_required_file',
                'operator_hint' => 'Falta un archivo requerido por TestKit. Revisar rutas require_once/include y que el overlay tenga todos los archivos nuevos.',
            ];
        }

        if ($error instanceof \ParseError) {
            return [
                'cause_code' => 'syntax_error',
                'root_cause' => 'php_parse_error',
                'operator_hint' => 'Hay un error de sintaxis PHP. Ejecutar php -l sobre el archivo reportado antes de rerun.',
            ];
        }

        if ($error instanceof \TypeError) {
            return [
                'cause_code' => 'type_error',
                'root_cause' => 'php_type_error',
                'operator_hint' => 'Hay un TypeError interno. Revisar firma/tipos en el archivo y línea reportados.',
            ];
        }

        if ($phase === 'reporting') {
            return [
                'cause_code' => 'report_write_failed',
                'root_cause' => 'reporting_failure',
                'operator_hint' => 'Falló la escritura/render de reportes. Revisar permisos, JSON y espacio en disco en report_root.',
            ];
        }

        if ($class === 'Error' && str_contains($lower, 'autoload')) {
            return [
                'cause_code' => 'autoload_failure',
                'root_cause' => 'autoload_or_bootstrap_failure',
                'operator_hint' => 'Falló la carga de clases. Revisar bootstrap/autoload y orden de requires.',
            ];
        }

        return [
            'cause_code' => 'runner_exception',
            'root_cause' => 'unclassified_runner_exception',
            'operator_hint' => 'Excepción no clasificada del runner. Revisar throwable_message, location y trace_excerpt en el reporte.',
        ];
    }

    /** @param array<string,string> $analysis @return array<string,mixed> */
    private static function throwablePayload(\Throwable $error, array $analysis, string $phase, string $target): array
    {
        $file = $error->getFile();
        $relativeFile = $file !== '' ? Paths::relativeToRepo($file) : '';

        return [
            'throwable_class' => ltrim(get_class($error), '\\'),
            'throwable_message' => trim($error->getMessage()),
            'throwable_file' => $relativeFile,
            'throwable_line' => $error->getLine() > 0 ? $error->getLine() : null,
            'root_cause' => (string)($analysis['root_cause'] ?? 'unclassified_runner_exception'),
            'operator_hint' => (string)($analysis['operator_hint'] ?? ''),
            'debug' => [
                'phase' => $phase,
                'target' => $target,
                'match' => Env::string('TEST_MATCH', ''),
            ],
        ];
    }
}
