<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Testkit\Core\Common\Paths;

final class ResultWriter
{
    /**
     * @param array<string,mixed> $result
     */
    public static function writeSuite(array $result): void
    {
        $suiteId = (string)($result['suite_id'] ?? 'suite');
        $reportsRoot = (string)($result['report_root'] ?? '');
        if ($reportsRoot === '') {
            $reportsRoot = Paths::reportsRoot();
        }
        Paths::ensureDir($reportsRoot);

        $safeSuite = preg_replace('/[^a-z0-9._-]+/i', '_', strtolower($suiteId)) ?: 'suite';
        $timestamp = gmdate('Ymd_His');

        $latestPath = $reportsRoot . '/' . $safeSuite . '_latest.json';
        $tsPath     = $reportsRoot . '/' . $safeSuite . '_' . $timestamp . '.json';

        $reportKeep = self::resolveKeep($result['report_keep'] ?? null, 5);
        $runsIndexKeep = self::resolveKeep($result['runs_index_keep'] ?? null, $reportKeep);
        $previous = self::loadJsonFile($latestPath);

        $report = self::decorateReport(
            $result,
            $previous,
            $latestPath,
            $tsPath,
            $reportKeep,
            $runsIndexKeep,
            'suite'
        );

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        file_put_contents($latestPath, $json);
        file_put_contents($tsPath, $json);
        self::pruneOldRuns($reportsRoot, $safeSuite, $reportKeep);
        self::updateRunsIndex(
            $reportsRoot,
            self::buildRunsIndexEntry($report, 'suite', basename($latestPath), basename($tsPath)),
            $runsIndexKeep
        );
    }

    /**
     * @param array<string,mixed> $meta
     */
    public static function writeMeta(array $meta): void
    {
        $reportsRoot = (string)($meta['report_root'] ?? '');
        if ($reportsRoot === '') {
            $reportsRoot = Paths::reportsRoot();
        }
        Paths::ensureDir($reportsRoot);

        $timestamp = gmdate('Ymd_His');
        $latestPath = $reportsRoot . '/meta_latest.json';
        $tsPath     = $reportsRoot . '/meta_' . $timestamp . '.json';

        $reportKeep = self::resolveKeep($meta['report_keep'] ?? null, 5);
        $runsIndexKeep = self::resolveKeep($meta['runs_index_keep'] ?? null, $reportKeep);
        $previous = self::loadJsonFile($latestPath);

        $report = self::decorateReport(
            $meta,
            $previous,
            $latestPath,
            $tsPath,
            $reportKeep,
            $runsIndexKeep,
            'meta'
        );

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        file_put_contents($latestPath, $json);
        file_put_contents($tsPath, $json);
        self::pruneOldRuns($reportsRoot, 'meta', $reportKeep);
        self::updateRunsIndex(
            $reportsRoot,
            self::buildRunsIndexEntry($report, 'meta', basename($latestPath), basename($tsPath)),
            $runsIndexKeep
        );
    }

    /**
     * Files matching <prefix>_YYYYmmdd_HHmmss.json are pruned; *_latest.json is never touched.
     */
    private static function pruneOldRuns(string $dir, string $prefix, int $keep): void
    {
        $safePfx = preg_replace('/[^a-z0-9._-]+/i', '_', strtolower($prefix)) ?: 'run';
        $pattern = $dir . '/' . $safePfx . '_[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]_[0-9][0-9][0-9][0-9][0-9][0-9].json';
        $files   = glob($pattern) ?: [];
        sort($files); // lexicographic order = chronological for Ymd_His format

        $excess = count($files) - $keep;
        for ($i = 0; $i < $excess; $i++) {
            @unlink($files[$i]);
        }
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed> $previous
     * @return array<string,mixed>
     */
    private static function decorateReport(
        array $report,
        array $previous,
        string $latestPath,
        string $tsPath,
        int $reportKeep,
        int $runsIndexKeep,
        string $kind
    ): array {
        $runId = trim((string)($report['run_id'] ?? ''));
        if ($runId === '') {
            $runId = self::buildRunId();
        }
        $report['run_id'] = $runId;

        $metaRunId = trim((string)($report['meta_run_id'] ?? ''));
        if ($kind === 'meta') {
            $metaRunId = $metaRunId !== '' ? $metaRunId : $runId;
            $report['meta_run_id'] = $metaRunId;
        } elseif ($metaRunId !== '') {
            $report['meta_run_id'] = $metaRunId;
        }

        $previousRunId = trim((string)($previous['run_id'] ?? $previous['meta_run_id'] ?? ''));
        $report['previous_run_id'] = $previousRunId !== '' ? $previousRunId : null;

        $delta = self::diffFailures($previous, $report);
        $report['new_failures'] = $delta['new_failures'];
        $report['resolved_failures'] = $delta['resolved_failures'];
        $report['new_failures_count'] = count($delta['new_failures']);
        $report['resolved_failures_count'] = count($delta['resolved_failures']);

        $report['report_keep'] = $reportKeep;
        $report['runs_index_keep'] = $runsIndexKeep;
        $report['report_links'] = [
            'latest' => basename($latestPath),
            'timestamped' => basename($tsPath),
            'runs_index' => 'runs_latest.json',
        ];

        return $report;
    }

    /**
     * @param array<string,mixed> $previous
     * @param array<string,mixed> $current
     * @return array{new_failures: array<int,string>, resolved_failures: array<int,string>}
     */
    private static function diffFailures(array $previous, array $current): array
    {
        $previousFailures = self::failureKeys($previous);
        $currentFailures = self::failureKeys($current);

        $newFailures = array_values(array_diff($currentFailures, $previousFailures));
        sort($newFailures);

        $resolvedFailures = array_values(array_diff($previousFailures, $currentFailures));
        sort($resolvedFailures);

        return [
            'new_failures' => $newFailures,
            'resolved_failures' => $resolvedFailures,
        ];
    }

    /**
     * @param array<string,mixed> $report
     * @return array<int,string>
     */
    private static function failureKeys(array $report): array
    {
        $keys = [];
        $suiteId = trim((string)($report['suite_id'] ?? ''));

        $failures = $report['failures'] ?? null;
        if (is_array($failures)) {
            foreach ($failures as $failure) {
                if (!is_array($failure)) {
                    continue;
                }

                $testId = trim((string)($failure['test_id'] ?? $failure['file'] ?? ''));
                if ($testId === '') {
                    continue;
                }

                $errorType = trim((string)($failure['error_type'] ?? ''));
                $key = ($suiteId !== '' ? $suiteId . '::' : '') . $testId;
                if ($errorType !== '') {
                    $key .= '::' . $errorType;
                }
                $keys[$key] = true;
            }
        }

        if ($keys === []) {
            $failedTests = $report['failed_tests'] ?? null;
            if (is_array($failedTests)) {
                foreach ($failedTests as $failure) {
                    if (!is_array($failure)) {
                        continue;
                    }

                    $testId = trim((string)($failure['rel'] ?? $failure['file'] ?? ''));
                    if ($testId === '') {
                        continue;
                    }

                    $key = ($suiteId !== '' ? $suiteId . '::' : '') . $testId;
                    $keys[$key] = true;
                }
            }
        }

        if ($keys === []) {
            $failedFiles = $report['failed_files'] ?? null;
            if (is_array($failedFiles)) {
                foreach ($failedFiles as $file) {
                    $file = trim((string)$file);
                    if ($file === '') {
                        continue;
                    }

                    $keys[$file] = true;
                }
            }
        }

        $out = array_keys($keys);
        sort($out);

        return $out;
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
     * @return array<string,mixed>
     */
    private static function buildRunsIndexEntry(array $report, string $kind, string $latestFile, string $timestampedFile): array
    {
        $recordId = $kind . '::'
            . trim((string)($report['run_id'] ?? ''))
            . '::'
            . trim((string)($report['suite_id'] ?? $report['target'] ?? 'meta'));

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
            'has_failures' => (bool)($report['has_failures'] ?? ((int)($report['fail'] ?? 0) > 0)),
            'failed_files' => self::failedFilesFromReport($report),
            'top_failure_messages' => self::topFailureMessagesFromReport($report, 3),
            'new_failures_count' => (int)($report['new_failures_count'] ?? 0),
            'resolved_failures_count' => (int)($report['resolved_failures_count'] ?? 0),
            'report_files' => [
                'latest' => $latestFile,
                'timestamped' => $timestampedFile,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $entry
     */
    private static function updateRunsIndex(string $reportsRoot, array $entry, int $keep): void
    {
        $indexPath = $reportsRoot . '/runs_latest.json';
        $existing = self::loadJsonFile($indexPath);
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

        file_put_contents($indexPath, $json);
    }

    /**
     * @return array<string,mixed>
     */
    private static function loadJsonFile(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $raw = file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }

    /**
     * @param mixed $value
     */
    private static function resolveKeep(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return max(1, $value);
        }

        if (is_string($value) && ctype_digit($value)) {
            return max(1, (int)$value);
        }

        return max(1, $default);
    }

    private static function buildRunId(): string
    {
        try {
            $suffix = bin2hex(random_bytes(3));
        } catch (\Throwable) {
            $suffix = substr((string)sha1(uniqid('', true)), 0, 6);
        }

        return gmdate('Ymd\THis\Z') . '_' . $suffix;
    }
}
