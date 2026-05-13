<?php
declare(strict_types=1);

namespace Testkit\Core\References;

final class ReferenceContractResult
{
    public int $filesConsidered = 0;
    public int $filesScanned = 0;
    public int $referencesFound = 0;
    public int $okReferences = 0;
    public int $brokenReferences = 0;
    public int $dynamicReferences = 0;
    public int $ignoredReferences = 0;
    public int $skippedFiles = 0;
    public bool $truncated = false;

    /** @var array<int,array<string,mixed>> */
    public array $references = [];

    /** @var array<int,array<string,mixed>> */
    public array $skippedFileDetails = [];

    /** @var array<int,array<string,mixed>> */
    public array $warnings = [];

    /** @var array<int,array<string,mixed>> */
    public array $failures = [];

    /** @var array<string,int> */
    public array $phaseTimingsMs = [
        'discovery' => 0,
        'execution' => 0,
        'reporting' => 0,
    ];

    public function __construct(
        public string $scope,
        public string $referenceRoot,
        public string $absoluteRoot,
        public int $startedMs
    ) {
    }

    /** @param array<string,mixed> $reference */
    public function addReference(array $reference): void
    {
        $this->references[] = $reference;
    }

    /** @param array<string,mixed> $file */
    public function addSkippedFile(array $file): void
    {
        $this->skippedFileDetails[] = $file;
    }

    /** @param array<string,mixed> $warning */
    public function addWarning(array $warning): void
    {
        $this->warnings[] = $warning;
    }

    /** @param array<string,mixed> $failure */
    public function addFailure(array $failure): void
    {
        $this->failures[] = $failure;
    }

    public function suiteStatus(): string
    {
        return $this->failures === [] ? 'passed' : 'failed';
    }

    public function durationMs(): int
    {
        return max(0, self::nowMs() - $this->startedMs);
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public function toReport(array $extra = []): array
    {
        $status = $this->suiteStatus();
        $failed = count($this->failures);
        $warn = count($this->warnings);
        $total = max($this->referencesFound + $this->skippedFiles, $failed + $this->skippedFiles);
        $passed = $this->okReferences + $this->ignoredReferences;

        return array_merge([
            'suite_id' => 'reference_contract',
            'scope' => $this->scope,
            'reference_root' => $this->referenceRoot,
            'reference_root_abs' => $this->absoluteRoot,
            'absolute_reference_root' => $this->absoluteRoot,
            'files_considered' => $this->filesConsidered,
            'files_scanned' => $this->filesScanned,
            'references_found' => $this->referencesFound,
            'ok_references' => $this->okReferences,
            'broken_references' => $this->brokenReferences,
            'dynamic_references' => $this->dynamicReferences,
            'ignored_references' => $this->ignoredReferences,
            'skipped_files' => $this->skippedFiles,
            'truncated' => $this->truncated,
            'references' => $this->references,
            'skipped_file_details' => $this->skippedFileDetails,
            'warnings' => $this->warnings,
            'failures' => $this->failures,
            'failed_tests' => $this->failures,
            'tests_total' => $total,
            'pass' => $passed,
            'fail' => $failed,
            'skip' => $this->skippedFiles,
            'suite_status' => $status,
            'outcome_status' => $status,
            'summary' => [
                'total' => $total,
                'passed' => $passed,
                'failed' => $failed,
                'skipped' => $this->skippedFiles,
                'warnings' => $warn,
                'duration_ms' => $this->durationMs(),
                'suite_status' => $status,
                'ok_references' => $this->okReferences,
                'broken_references' => $this->brokenReferences,
                'dynamic_references' => $this->dynamicReferences,
                'ignored_references' => $this->ignoredReferences,
                'skipped_files' => $this->skippedFiles,
                'truncated' => $this->truncated,
            ],
            'phase_timings_ms' => $this->phaseTimingsMs,
            'first_failure' => $this->failures !== [] ? $this->failures[0] : null,
            'has_failures' => $this->failures !== [],
            'evidence_valid' => $status === 'passed',
            'evidence_invalid_reason' => $status === 'passed' ? null : 'reference_contract_failed',
        ], $extra);
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public static function warning(
        string $code,
        string $summary,
        string $file = '',
        int $line = 0,
        array $extra = []
    ): array {
        return array_merge([
            'kind' => strtolower($code),
            'severity' => 'WARN',
            'code' => strtoupper($code),
            'summary' => $summary,
            'message' => $summary,
            'file' => $file,
            'line' => $line,
            'phase' => 'execution',
            'failure_domain' => 'references',
            'cause_code' => strtolower($code),
        ], $extra);
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public static function failure(
        string $kind,
        string $message,
        string $file = '',
        int $line = 0,
        array $extra = []
    ): array {
        $causeCode = (string)($extra['cause_code'] ?? $kind);

        return array_merge([
            'kind' => $kind,
            'test_id' => 'reference_contract.' . ($file !== '' ? str_replace('/', '.', $file) . ':' . $line : $kind),
            'test_name' => 'reference_contract',
            'case' => $file !== '' ? $file . ':' . $line : $kind,
            'suite_id' => 'reference_contract',
            'suite' => 'reference_contract',
            'file' => $file,
            'line' => $line,
            'message' => $message,
            'phase' => 'execution',
            'failure_domain' => 'references',
            'cause_code' => $causeCode,
        ], $extra);
    }

    public static function nowMs(): int
    {
        return (int)round(microtime(true) * 1000);
    }
}
