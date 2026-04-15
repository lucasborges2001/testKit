<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

final class RunIndexWriter
{
    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    public static function buildRunsIndexEntry(
        array $report,
        string $kind,
        string $latestFile,
        string $timestampedFile,
        string $canonicalLatestFile
    ): array {
        $recordId = $kind . '::'
            . trim((string)($report['run_id'] ?? ''))
            . '::'
            . trim((string)($report['suite_id'] ?? $report['target'] ?? 'meta'))
            . '::'
            . trim((string)($report['report_key'] ?? 'default'));

        $filters = is_array($report['filters'] ?? null) ? $report['filters'] : [];
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];

        return [
            'record_id' => $recordId,
            'kind' => $kind,
            'run_id' => (string)($report['run_id'] ?? ''),
            'meta_run_id' => $report['meta_run_id'] ?? null,
            'previous_run_id' => $report['previous_run_id'] ?? null,
            'suite_id' => $report['suite_id'] ?? null,
            'target' => $report['target'] ?? ($filters['target'] ?? null),
            'scope' => $report['scope'] ?? ($filters['scope'] ?? null),
            'category' => $report['category'] ?? ($filters['category'] ?? null),
            'match' => $report['match'] ?? ($filters['match'] ?? null),
            'suite_status' => $report['suite_status'] ?? null,
            'suite_status_counts' => $report['suite_status_counts'] ?? null,
            'summary' => $summary,
            'started_at' => $report['started_at'] ?? null,
            'finished_at' => $report['finished_at'] ?? null,
            'duration_ms' => (int)($report['duration_ms'] ?? 0),
            'selected_module_scope' => (string)($report['selected_module_scope'] ?? ''),
            'report_scope_rel' => (string)($report['report_scope_rel'] ?? ''),
            'report_scope_key' => (string)($report['report_scope_key'] ?? ''),
            'report_key' => (string)($report['report_key'] ?? ''),
            'has_failures' => (bool)($report['has_failures'] ?? ((int)($report['fail'] ?? 0) > 0)),
            'evidence_valid' => (bool)($report['evidence_valid'] ?? true),
            'evidence_invalid_reason' => $report['evidence_invalid_reason'] ?? null,
            'failed_files' => self::failedFilesFromReport($report),
            'top_failure_messages' => self::topFailureMessagesFromReport($report, 3),
            'first_failure' => self::compactFirstFailureFromReport($report),
            'new_failures_count' => (int)($report['new_failures_count'] ?? 0),
            'resolved_failures_count' => (int)($report['resolved_failures_count'] ?? 0),
            'dominant_failure_family' => $report['dominant_failure_family'] ?? null,
            'report_files' => [
                'latest' => $latestFile,
                'timestamped' => $timestampedFile,
                'canonical_latest' => $canonicalLatestFile,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $entry
     */
    public static function updateRunsIndex(string $reportsRoot, array $entry, int $keep): void
    {
        $indexPath = $reportsRoot . '/runs_latest.json';
        $existing = AtomicJsonWriter::loadJsonFile($indexPath);
        $rows = [];

        if (isset($existing['runs']) && is_array($existing['runs'])) {
            $rows = array_values(array_filter($existing['runs'], 'is_array'));
        } elseif (array_is_list($existing)) {
            $rows = array_values(array_filter($existing, 'is_array'));
        }

        $recordId = (string)($entry['record_id'] ?? '');
        $rows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => (string)($row['record_id'] ?? '') !== $recordId
        ));

        array_unshift($rows, $entry);
        $rows = array_slice($rows, 0, max(1, $keep));

        $payload = [
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'runs' => $rows,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        AtomicJsonWriter::writeFileAtomic($indexPath, $json);
    }

    /**
     * @param array<string,mixed> $report
     * @return array<int,string>
     */
    private static function failedFilesFromReport(array $report): array
    {
        $files = [];

        $failedFiles = $report['failed_files'] ?? null;
        if (is_array($failedFiles)) {
            foreach ($failedFiles as $file) {
                $file = trim((string)$file);
                if ($file !== '') {
                    $files[$file] = true;
                }
            }
        }

        $failures = $report['failures'] ?? null;
        if (is_array($failures)) {
            foreach ($failures as $failure) {
                if (!is_array($failure)) {
                    continue;
                }
                $file = trim((string)($failure['file'] ?? ''));
                if ($file !== '') {
                    $files[$file] = true;
                }
            }
        }

        $legacy = $report['failed_tests'] ?? null;
        if (is_array($legacy)) {
            foreach ($legacy as $failure) {
                if (!is_array($failure)) {
                    continue;
                }
                $file = trim((string)($failure['rel'] ?? $failure['file'] ?? ''));
                if ($file !== '') {
                    $files[$file] = true;
                }
            }
        }

        $out = array_keys($files);
        sort($out);

        return $out;
    }

    /**
     * @param array<string,mixed> $report
     * @return array<int,string>
     */
    private static function topFailureMessagesFromReport(array $report, int $limit = 3): array
    {
        $messages = [];

        $topFailureMessages = $report['top_failure_messages'] ?? null;
        if (is_array($topFailureMessages)) {
            foreach ($topFailureMessages as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $message = trim((string)($row['message'] ?? ''));
                if ($message !== '') {
                    $messages[] = $message;
                }
            }
        }

        if ($messages === []) {
            $failures = $report['failures'] ?? null;
            if (is_array($failures)) {
                foreach ($failures as $failure) {
                    if (!is_array($failure)) {
                        continue;
                    }
                    $message = trim((string)($failure['message'] ?? ''));
                    if ($message !== '') {
                        $messages[] = $message;
                    }
                }
            }
        }

        $messages = array_values(array_unique($messages));
        return array_slice($messages, 0, max(0, $limit));
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>|null
     */
    private static function compactFirstFailureFromReport(array $report): ?array
    {
        $first = $report['first_failure'] ?? null;
        if (!is_array($first)) {
            $first = ReportSummary::firstFailure($report);
        }

        if (!is_array($first)) {
            return null;
        }

        return [
            'file' => (string)($first['file'] ?? ''),
            'case' => (string)($first['case'] ?? ''),
            'kind' => (string)($first['kind'] ?? ''),
            'message' => (string)($first['message'] ?? ''),
        ];
    }
}
