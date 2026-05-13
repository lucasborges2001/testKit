<?php
declare(strict_types=1);

namespace Testkit\Core\References;

final class ReferenceConsoleRenderer
{
    /**
     * @param array<string,mixed> $report
     */
    public static function render(array $report, ReferenceConfig $config, int $maxBroken = 8): string
    {
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $references = is_array($report['references'] ?? null) ? $report['references'] : [];
        $broken = array_values(array_filter(
            $references,
            static fn(mixed $ref): bool => is_array($ref) && (string)($ref['status'] ?? '') === 'missing'
        ));

        $lines = [];
        $lines[] = '';
        $lines[] = 'REFERENCE CONTRACT';
        $lines[] = '';
        $lines[] = 'Selection';
        $lines[] = '  scope: ' . (string)($report['scope'] ?? $config->scope);
        $lines[] = '  root: ' . (string)($report['reference_root'] ?? '');
        $lines[] = '  files_scanned: ' . (string)($report['files_scanned'] ?? 0);
        $lines[] = '  ignored_dirs: ' . implode(',', $config->ignoreDirs);
        $lines[] = '';
        $lines[] = 'Reference Summary';
        $lines[] = '  ok: ' . (string)($report['ok_references'] ?? $summary['ok_references'] ?? 0);
        $lines[] = '  missing: ' . (string)($report['broken_references'] ?? $summary['broken_references'] ?? 0);
        $lines[] = '  dynamic: ' . (string)($report['dynamic_references'] ?? $summary['dynamic_references'] ?? 0);
        $lines[] = '  ignored: ' . (string)($report['ignored_references'] ?? $summary['ignored_references'] ?? 0);
        $lines[] = '  skipped_files: ' . (string)($report['skipped_files'] ?? $summary['skipped_files'] ?? 0);
        $lines[] = '  truncated: ' . ((bool)($report['truncated'] ?? false) ? 'true' : 'false');

        if ($broken !== []) {
            $lines[] = '';
            $lines[] = 'Broken References';
            $shown = 0;
            foreach ($broken as $ref) {
                if ($shown >= $maxBroken) {
                    break;
                }
                $lines[] = '  - ' . (string)($ref['file'] ?? '') . ':' . (string)($ref['line'] ?? 0);
                $lines[] = '    ' . trim((string)($ref['type'] ?? $ref['reference_type'] ?? 'include') . ' ' . (string)($ref['reference'] ?? ''));
                $lines[] = '    resolved_as ' . (string)($ref['resolved_as'] ?? '');
                $shown++;
            }

            $hidden = count($broken) - $shown;
            if ($hidden > 0) {
                $lines[] = '';
                $lines[] = '  ... ' . $hidden . ' more hidden; see report JSON';
            }
        }

        $lines[] = '';
        $lines[] = 'Result';
        $lines[] = '  outcome: ' . strtoupper((string)($report['suite_status'] ?? 'unknown'));
        $lines[] = '  time_ms: ' . (string)($summary['duration_ms'] ?? $report['duration_ms'] ?? 0);
        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }
}
