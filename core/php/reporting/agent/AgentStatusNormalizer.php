<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting\Agent;

use Testkit\Core\Common\Paths;

final class AgentStatusNormalizer
{
    public static function finalStatusFromRawStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'passed', 'pass' => 'PASS',
            'failed', 'fail' => 'FAIL',
            'partial' => 'PARTIAL',
            'timeout' => 'TIMEOUT',
            'contention', 'blocked' => 'BLOCKED',
            'infra_error', 'bootstrap_error', 'discovery_error', 'reporting_error', 'error' => 'ERROR',
            'skipped', 'skip', 'all_skipped' => 'SKIP',
            'no_tests' => 'NO_TESTS',
            'listed' => 'LISTED',
            default => '',
        };
    }

    public static function outcomeFromFinalStatus(string $finalStatus): string
    {
        return match (strtoupper(trim($finalStatus))) {
            'PASS' => 'passed',
            'FAIL' => 'failed',
            'TIMEOUT' => 'timeout',
            'BLOCKED' => 'contention',
            'NO_TESTS' => 'no_tests',
            'LISTED' => 'listed',
            'SKIP' => 'skipped',
            'PARTIAL' => 'partial',
            'ERROR' => 'infra_error',
            default => 'failed',
        };
    }

    public static function normalizeOutcome(string $status): string
    {
        return match (strtolower(trim($status))) {
            'pass' => 'passed',
            'fail' => 'failed',
            'blocked' => 'contention',
            default => strtolower(trim($status)),
        };
    }

    public static function failureDomainFromPhase(string $phase): string
    {
        return match (strtolower(trim($phase))) {
            'bootstrap' => 'bootstrap',
            'store_setup' => 'store',
            'discovery' => 'discovery',
            'reporting' => 'reporting',
            'admission' => 'runner',
            'execution' => 'domain',
            default => '',
        };
    }

    /** @param array<string,mixed> $report */
    public static function reportArtifactPath(array $report): string
    {
        $source = trim((string)($report['_source_file'] ?? ''));
        if ($source !== '') {
            return Paths::relativeToRepo($source);
        }
        return (string)($report['report_scope_rel'] ?? $report['report_root'] ?? '');
    }

    /** @return array<int,string> */
    public static function normalizeStackExcerpt(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(
                array_map(static fn(mixed $line): string => trim((string)$line), $value),
                static fn(string $line): bool => $line !== ''
            ));
        }

        $text = trim((string)$value);
        if ($text === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $lines = array_values(array_filter(array_map(static fn(string $line): string => trim($line), $lines), static fn(string $line): bool => $line !== ''));
        return array_slice($lines, 0, 8);
    }
}
