<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

final class FailureDelta
{
    /**
     * @param array<string,mixed> $previous
     * @param array<string,mixed> $current
     * @return array{new_failures: array<int,string>, resolved_failures: array<int,string>}
     */
    public static function diff(array $previous, array $current): array
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
}
