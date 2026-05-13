<?php
declare(strict_types=1);

namespace Testkit\Core\References;

use Testkit\Core\Common\Paths;

final class ReferenceContractResult
{
    public int $filesScanned = 0;
    public int $referencesFound = 0;
    public int $brokenReferences = 0;
    public int $dynamicReferences = 0;
    public int $skippedFiles = 0;
    public bool $truncated = false;

    /** @var array<int,array<string,mixed>> */
    public array $warnings = [];

    /** @var array<int,array<string,mixed>> */
    public array $failures = [];

    public function __construct(
        public string $scope,
        public string $referenceRoot,
        public string $absoluteRoot,
        public int $startedMs
    ) {
    }

    public function addWarning(array $warning): void
    {
        $this->warnings[] = $warning;
    }

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
        $passed = max(0, $this->referencesFound - $failed);

        return array_merge([
            'suite_id' => 'reference_contract',
            'scope' => $this->scope,
            'reference_root' => $this->referenceRoot,
            'absolute_reference_root' => $this->absoluteRoot,
            'files_scanned' => $this->filesScanned,
            'references_found' => $this->referencesFound,
            'broken_references' => $this->brokenReferences,
            'dynamic_references' => $this->dynamicReferences,
            'skipped_files' => $this->skippedFiles,
            'truncated' => $this->truncated,
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
            ],
            'first_failure' => $this->failures !== [] ? $this->failures[0] : null,
            'has_failures' => $this->failures !== [],
            'evidence_valid' => $status === 'passed',
            'evidence_invalid_reason' => $status === 'passed' ? null : 'reference_contract_failed',
        ], $extra);
    }

    /**
     * @return array<string,mixed>
     */
    public static function warning(
        string $kind,
        string $message,
        string $file = '',
        int $line = 0,
        array $extra = []
    ): array {
        return array_merge([
            'kind' => $kind,
            'severity' => 'WARN',
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'phase' => 'execution',
            'failure_domain' => 'references',
            'cause_code' => $kind,
        ], $extra);
    }

    /**
     * @return array<string,mixed>
     */
    public static function failure(
        string $kind,
        string $message,
        string $file = '',
        int $line = 0,
        array $extra = []
    ): array {
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
            'cause_code' => $kind,
        ], $extra);
    }

    public static function nowMs(): int
    {
        return (int)round(microtime(true) * 1000);
    }
}
