<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

final class FailureGrouping
{
    /**
     * @param array<int,array<string,mixed>> $failures
     * @return array<string,mixed>
     */
    public static function groupFailures(array $failures): array
    {
        $byFile = [];
        $byErrorType = [];
        $byMessage = [];

        foreach ($failures as $failure) {
            $testId = (string)($failure['test_id'] ?? $failure['file'] ?? 'unknown');
            $file = (string)($failure['file'] ?? 'unknown');
            $errorType = (string)($failure['error_type'] ?? 'unknown');
            $message = (string)($failure['message'] ?? '');

            $byFile[$file][] = $testId;
            $byErrorType[$errorType][] = $testId;

            if ($message !== '') {
                $normalized = substr((string)preg_replace('/\s+/', ' ', $message), 0, 160);
                $byMessage[$normalized][] = $testId;
            }
        }

        ksort($byFile);
        ksort($byErrorType);
        ksort($byMessage);

        return [
            'by_file' => $byFile,
            'by_error_type' => $byErrorType,
            'by_message' => $byMessage,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $failures
     * @return array<int,string>
     */
    public static function failedFiles(array $failures): array
    {
        $files = [];
        foreach ($failures as $failure) {
            $file = trim((string)($failure['file'] ?? ''));
            if ($file !== '') {
                $files[$file] = true;
            }
        }

        $rows = array_keys($files);
        sort($rows);
        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $failures
     * @return array<int,array<string,mixed>>
     */
    public static function topFailureMessages(array $failures, int $limit = 5): array
    {
        $aggregate = [];
        foreach ($failures as $failure) {
            $message = trim((string)($failure['message'] ?? ''));
            if ($message === '') {
                continue;
            }

            $key = substr((string)preg_replace('/\s+/', ' ', $message), 0, 200);
            if (!isset($aggregate[$key])) {
                $aggregate[$key] = [
                    'message' => $key,
                    'count' => 0,
                    'files' => [],
                    'suite_ids' => [],
                ];
            }

            $aggregate[$key]['count']++;

            $file = trim((string)($failure['file'] ?? ''));
            if ($file !== '') {
                $aggregate[$key]['files'][$file] = true;
            }

            $suiteId = trim((string)($failure['suite_id'] ?? $failure['suite'] ?? ''));
            if ($suiteId !== '') {
                $aggregate[$key]['suite_ids'][$suiteId] = true;
            }
        }

        $rows = array_values(array_map(
            static function (array $row): array {
                $row['files'] = array_values(array_keys($row['files']));
                sort($row['files']);
                $row['suite_ids'] = array_values(array_keys($row['suite_ids']));
                sort($row['suite_ids']);
                return $row;
            },
            $aggregate
        ));

        usort($rows, static function (array $left, array $right): int {
            $countCompare = ((int)$right['count']) <=> ((int)$left['count']);
            if ($countCompare !== 0) {
                return $countCompare;
            }

            return strcmp((string)$left['message'], (string)$right['message']);
        });

        return array_slice($rows, 0, max(0, $limit));
    }
}
