<?php
declare(strict_types=1);

namespace Testkit\Core\SqlStatic;

use Testkit\Core\Reporting\UI;

final class SqlStaticConsoleReporter
{
    public static function compact(array $report): string
    {
        $summary = (array)($report['summary'] ?? []);
        $delta = (array)($report['delta'] ?? []);
        $parts = [
            UI::success('OK'),
            'sql-static-audit',
            UI::gray('files=' . (int)($report['scanned_files'] ?? 0)),
            UI::gray('candidates=' . (int)($report['sql_candidates'] ?? 0)),
            UI::gray('queries=' . (int)($report['extracted_queries'] ?? 0)),
            UI::gray('findings=' . (int)($summary['findings'] ?? 0)),
            UI::warning('warn=' . (int)($summary['warn'] ?? 0)),
            UI::info('watch=' . (int)($summary['watch'] ?? 0)),
        ];
        $partial = ($report['coverage_status'] ?? '') === 'partial';
        $parts[] = $partial ? UI::warning('unresolved=' . (int)($report['unresolved_candidates'] ?? 0)) : UI::gray('unresolved=0');
        $parts[] = $partial ? UI::warning('coverage=partial') : UI::gray('coverage=' . (string)($report['coverage_status'] ?? 'unknown'));
        if (($delta['status'] ?? '') === 'compared') {
            $parts[] = UI::gray('new=' . (int)($delta['new_findings'] ?? 0));
            $parts[] = UI::gray('resolved=' . (int)($delta['resolved_findings'] ?? 0));
            $parts[] = UI::gray('unchanged=' . (int)($delta['unchanged_findings'] ?? 0));
        }
        return implode(' ', $parts) . PHP_EOL;
    }

    public static function human(array $report, string $artifact = ''): string
    {
        $lines = [UI::info('[SQL Static Audit]'), rtrim(self::compact($report))];
        $coverage = (array)($report['coverage_findings'] ?? []);
        if ($coverage !== []) {
            $lines[] = '';
            $lines[] = UI::warning('[Coverage]');
            foreach (array_slice($coverage, 0, 20) as $finding) {
                if (is_array($finding)) {
                    $lines[] = UI::warning(sprintf('COVERAGE %s %s:%d', (string)($finding['reason'] ?? $finding['rule_id'] ?? 'unknown'), (string)($finding['path'] ?? ''), (int)($finding['line'] ?? 0)));
                }
            }
            if (count($coverage) > 20) $lines[] = UI::gray(sprintf('... %d coverage findings adicionales en JSON', count($coverage) - 20));
        }
        $findings = (array)($report['findings'] ?? []);
        if ($findings !== []) {
            $lines[] = '';
            $lines[] = UI::info('[Findings]');
            foreach (array_slice($findings, 0, 30) as $finding) {
                if (!is_array($finding)) continue;
                $text = sprintf('%s/%s %s %s:%d', strtoupper((string)($finding['severity'] ?? 'info')), strtoupper((string)($finding['confidence'] ?? 'unknown')), (string)($finding['rule_id'] ?? 'unknown'), (string)($finding['path'] ?? ''), (int)($finding['line'] ?? 0));
                $lines[] = ($finding['severity'] ?? '') === 'warn' ? UI::warning($text) : UI::info($text);
            }
            if (count($findings) > 30) $lines[] = UI::gray(sprintf('... %d findings adicionales en JSON', count($findings) - 30));
        }
        if ($findings === [] && $coverage === []) $lines[] = UI::success('No findings.');
        if ($artifact !== '') $lines[] = UI::gray('artifact: ' . $artifact);
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}
