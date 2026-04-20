<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

final class RecommendedActionBuilder
{
    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed>|null $diagnostics
     * @return array<int,array<string,mixed>>
     */
    public static function build(array $report, ?array $diagnostics = null): array
    {
        $diagnostics ??= OutcomeDiagnostics::diagnostics($report);
        $actions = [];

        $suiteId = trim((string)($report['suite_id'] ?? ''));
        $target = $suiteId !== '' ? str_replace('_', '-', $suiteId) : ((string)($report['target'] ?? 'all') ?: 'all');
        $firstFailure = is_array($report['first_failure'] ?? null) ? $report['first_failure'] : FailureNormalizer::firstFailure($report);
        $firstFile = trim((string)($firstFailure['file'] ?? ''));

        if ($firstFile !== '') {
            $actions[] = [
                'kind' => 'rerun_filtered',
                'command' => CommandSuggestion::rerun($target, $firstFile),
                'reason' => 'aislar el primer archivo fallido',
            ];
        }

        $primaryPhase = (string)($diagnostics['primary_phase'] ?? 'none');
        if (in_array($primaryPhase, ['bootstrap', 'store_setup'], true)) {
            $actions[] = [
                'kind' => 'enable_seed_trace',
                'command' => CommandSuggestion::traceMigrations($target),
                'reason' => 'ampliar evidencia en bootstrap/seeding',
            ];
        }

        if ($primaryPhase === 'discovery') {
            $actions[] = [
                'kind' => 'list_selection',
                'command' => CommandSuggestion::listSelection($target),
                'reason' => 'ver selección efectiva y validar filtros',
            ];
        }

        $reportRoot = trim((string)($report['report_scope_rel'] ?? $report['report_root'] ?? ''));
        if ($reportRoot !== '') {
            $actions[] = [
                'kind' => 'open_report_root',
                'command' => $reportRoot,
                'reason' => 'inspeccionar artefactos generados por la corrida',
            ];
        }

        $actions[] = [
            'kind' => 'aggregate_report',
            'command' => CommandSuggestion::report(),
            'reason' => 'ver resumen consolidado de fallas y coverage',
        ];

        return self::dedupeAndLimit($actions, 5);
    }

    /**
     * @param array<int,array<string,mixed>> $actions
     * @return array<int,array<string,mixed>>
     */
    private static function dedupeAndLimit(array $actions, int $limit): array
    {
        $unique = [];
        $deduped = [];

        foreach ($actions as $action) {
            $key = (string)($action['kind'] ?? '') . '::' . (string)($action['command'] ?? '');
            if (isset($unique[$key])) {
                continue;
            }

            $unique[$key] = true;
            $deduped[] = $action;
        }

        return array_slice($deduped, 0, max(0, $limit));
    }
}
