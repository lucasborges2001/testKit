<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting\Agent;

final class AgentSelectionDeriver
{
    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     * @return array<string,mixed>
     */
    public static function deriveSelection(?array $meta, array $suiteReports): array
    {
        $suiteIds = [];
        $selectedTestCount = 0;
        $match = '';
        $scope = '';
        $category = '';
        $moduleScope = '';
        $target = '';

        foreach (array_values(array_filter([$meta], 'is_array')) as $report) {
            $selection = self::selectionFromReport($report);
            $target = (string)($selection['target'] ?? $target);
            $match = (string)($selection['match'] ?? $match);
            $scope = (string)($selection['scope'] ?? $scope);
            $category = (string)($selection['category'] ?? $category);
            $moduleScope = (string)($selection['selected_module_scope'] ?? $moduleScope);
            $selectedTestCount = max($selectedTestCount, (int)($selection['selected_test_count'] ?? 0));
        }

        foreach ($suiteReports as $report) {
            $selection = self::selectionFromReport($report);
            $suiteId = trim((string)($selection['suite_id'] ?? $report['suite_id'] ?? ''));
            if ($suiteId !== '') {
                $suiteIds[$suiteId] = true;
            }
            if ($selectedTestCount === 0) {
                $selectedTestCount += (int)($selection['selected_test_count'] ?? 0);
            }
            if ($match === '') {
                $match = (string)($selection['match'] ?? '');
            }
            if ($scope === '') {
                $scope = (string)($selection['scope'] ?? '');
            }
            if ($category === '') {
                $category = (string)($selection['category'] ?? '');
            }
            if ($moduleScope === '') {
                $moduleScope = (string)($selection['selected_module_scope'] ?? '');
            }
        }

        return [
            'target' => $target,
            'scope' => $scope,
            'category' => $category,
            'match' => $match,
            'selected_test_count' => $selectedTestCount,
            'selected_module_scope' => $moduleScope,
            'suite_ids' => array_values(array_keys($suiteIds)),
            'primary_suite_id' => count($suiteIds) === 1 ? (string)array_key_first($suiteIds) : '',
        ];
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    public static function selectionFromReport(array $report): array
    {
        $canonical = is_array($report['canonical_report'] ?? null) ? $report['canonical_report'] : [];
        if (is_array($canonical['selection'] ?? null)) {
            return $canonical['selection'];
        }

        return [
            'suite_id' => (string)($report['suite_id'] ?? ''),
            'target' => (string)($report['target'] ?? ''),
            'scope' => (string)($report['scope'] ?? ($report['filters']['scope'] ?? '')),
            'category' => (string)($report['category'] ?? ($report['filters']['category'] ?? '')),
            'match' => (string)($report['match'] ?? ($report['filters']['match'] ?? '')),
            'selected_test_count' => (int)($report['selected_test_count'] ?? $report['tests_total'] ?? ($report['summary']['total'] ?? 0)),
            'selected_test_files' => array_values((array)($report['selected_test_files'] ?? [])),
            'selected_module_scope' => (string)($report['selected_module_scope'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $selection @param array<int,array<string,mixed>> $suiteReports */
    public static function primarySuiteId(array $selection, array $suiteReports): string
    {
        $primary = trim((string)($selection['primary_suite_id'] ?? ''));
        if ($primary !== '') {
            return $primary;
        }
        foreach ($suiteReports as $report) {
            $selectionFromReport = self::selectionFromReport($report);
            $suiteId = trim((string)($report['suite_id'] ?? $selectionFromReport['suite_id'] ?? ''));
            if ($suiteId !== '') {
                return $suiteId;
            }
        }
        return '';
    }

    /** @param array<string,mixed> $selection @param array<int,array<string,mixed>> $suiteReports */
    public static function targetHint(array $selection, array $suiteReports): string
    {
        $target = trim((string)($selection['target'] ?? ''));
        if ($target !== '') {
            return $target;
        }
        $suiteId = self::primarySuiteId($selection, $suiteReports);
        if ($suiteId !== '') {
            return str_replace('_', '-', $suiteId);
        }
        return 'all';
    }
}
