<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

final class SelectionManifestBuilder
{
    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    public static function build(array $report): array
    {
        $selection = $report['selection_manifest'] ?? null;
        if (is_array($selection)) {
            $selection['selected_test_files'] = array_values(array_filter((array)($selection['selected_test_files'] ?? []), 'is_string'));
            return $selection;
        }

        return [
            'suite_id' => (string)($report['suite_id'] ?? ''),
            'scope' => (string)($report['scope'] ?? ($report['filters']['scope'] ?? 'all')),
            'category' => (string)($report['category'] ?? ($report['filters']['category'] ?? 'all')),
            'match' => (string)($report['match'] ?? ($report['filters']['match'] ?? '')),
            'list_only' => (bool)($report['list_only'] ?? false),
            'selected_test_count' => (int)($report['selected_test_count'] ?? $report['tests_total'] ?? 0),
            'selected_module_scope' => (string)($report['selected_module_scope'] ?? ''),
            'selected_common_dir' => (string)($report['selected_common_dir'] ?? ''),
            'selected_test_files' => array_values(array_filter((array)($report['selected_test_files'] ?? []), 'is_string')),
            'source' => 'report_summary_fallback',
        ];
    }
}
