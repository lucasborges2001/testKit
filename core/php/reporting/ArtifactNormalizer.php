<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

final class ArtifactNormalizer
{
    /**
     * @param array<string,mixed> $report
     * @return array<int,array<string,mixed>>
     */
    public static function normalize(array $report): array
    {
        $items = [];

        $push = static function (array &$items, string $kind, mixed $path): void {
            if (!is_string($path) || trim($path) === '') {
                return;
            }

            $path = str_replace('\\', '/', trim($path));
            $items[] = [
                'kind' => $kind,
                'path' => $path,
                'exists' => null,
            ];
        };

        $push($items, 'report_root', $report['report_root'] ?? null);
        $push($items, 'history_file', $report['history_file'] ?? null);
        $push($items, 'manifest_path', $report['manifest_path'] ?? null);
        $push($items, 'snapshot_file', $report['snapshot_file'] ?? null);
        $push($items, 'coverage_json', $report['coverage_json'] ?? null);
        $push($items, 'coverage_lcov', $report['coverage_lcov'] ?? null);

        $reportLinks = $report['report_links'] ?? null;
        if (is_array($reportLinks)) {
            foreach ($reportLinks as $kind => $path) {
                $push($items, 'report_link:' . (string)$kind, $path);
            }
        }

        return $items;
    }
}
