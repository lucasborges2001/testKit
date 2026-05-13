<?php
declare(strict_types=1);

namespace Testkit\Core\References;

use Testkit\Core\Common\Paths;

final class PhpIncludeScanner
{
    private PhpIncludeExtractor $extractor;
    private PhpIncludeResolver $resolver;

    public function __construct(
        private ReferenceConfig $config,
        private string $repoRoot,
        private string $rootAbs,
        private string $rootRel
    ) {
        $this->extractor = new PhpIncludeExtractor();
        $this->resolver = new PhpIncludeResolver();
    }

    public function scan(): ReferenceContractResult
    {
        $result = new ReferenceContractResult(
            scope: $this->config->scope,
            referenceRoot: $this->rootRel,
            absoluteRoot: $this->rootAbs,
            startedMs: ReferenceContractResult::nowMs()
        );

        $executionStart = ReferenceContractResult::nowMs();
        $this->scanDir($this->rootAbs, $result);
        $result->phaseTimingsMs['execution'] = max(0, ReferenceContractResult::nowMs() - $executionStart);

        return $result;
    }

    private function scanDir(string $dir, ReferenceContractResult $result): void
    {
        if ($this->shouldStop($result)) {
            return;
        }

        $entries = @scandir($dir);
        if (!is_array($entries)) {
            $result->addWarning(ReferenceContractResult::warning(
                'REFERENCE_DIR_UNREADABLE',
                'No se pudo leer directorio de referencias',
                Paths::relativeToRepo($dir),
                0,
                ['phase' => 'discovery']
            ));
            return;
        }

        sort($entries, SORT_STRING);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if ($this->shouldStop($result)) {
                return;
            }

            $path = Paths::normalize($dir . '/' . $entry);
            if (is_dir($path)) {
                if ($this->shouldIgnoreDir($path)) {
                    continue;
                }
                if (is_link($path)) {
                    continue;
                }
                $this->scanDir($path, $result);
                continue;
            }

            if (!is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }

            $this->scanFile($path, $result);
        }
    }

    private function scanFile(string $file, ReferenceContractResult $result): void
    {
        if ($this->shouldStop($result)) {
            return;
        }

        $result->filesConsidered++;
        $rel = Paths::relativeToRepo($file);

        $fileIgnoreReason = $this->fileIgnoreReason($file);
        if ($fileIgnoreReason !== null) {
            $result->skippedFiles++;
            $result->addSkippedFile([
                'file' => $rel,
                'status' => 'skipped',
                'reason' => $fileIgnoreReason,
            ]);
            return;
        }

        if ($result->filesScanned >= $this->config->maxFiles) {
            $this->stopWithFailure($result, 'reference_max_files_exceeded', 'Se alcanzó TESTKIT_REFERENCE_MAX_FILES.', 'discovery');
            return;
        }

        $size = @filesize($file);
        if (is_int($size) && $size > $this->config->maxBytesPerFile) {
            $result->skippedFiles++;
            $result->addSkippedFile([
                'file' => $rel,
                'status' => 'skipped',
                'reason' => 'max_bytes',
                'size_bytes' => $size,
                'max_bytes' => $this->config->maxBytesPerFile,
            ]);
            $result->addWarning(ReferenceContractResult::warning(
                'REFERENCE_FILE_TOO_LARGE',
                'Archivo PHP omitido por superar TESTKIT_REFERENCE_MAX_BYTES_PER_FILE.',
                $rel,
                0,
                ['size_bytes' => $size, 'max_bytes' => $this->config->maxBytesPerFile]
            ));
            return;
        }

        $source = @file_get_contents($file);
        if (!is_string($source)) {
            $result->skippedFiles++;
            $result->addSkippedFile([
                'file' => $rel,
                'status' => 'skipped',
                'reason' => 'unreadable',
            ]);
            $result->addWarning(ReferenceContractResult::warning(
                'REFERENCE_FILE_UNREADABLE',
                'Archivo PHP omitido porque no se pudo leer.',
                $rel
            ));
            return;
        }

        $result->filesScanned++;
        foreach ($this->extractor->extract($source) as $directive) {
            if ($this->shouldStop($result)) {
                return;
            }

            $result->referencesFound++;
            $resolved = $this->resolver->resolve($directive, $file, $this->repoRoot);
            $ignoreReason = $this->referenceIgnoreReason($file, $directive, $resolved);
            if ($ignoreReason !== null) {
                $this->recordIgnored($result, $rel, $directive, $resolved, $ignoreReason);
                continue;
            }

            if ((bool)$resolved['dynamic']) {
                $this->recordDynamic($result, $rel, $directive, $resolved);
                continue;
            }

            if (!is_file((string)$resolved['resolved_path'])) {
                $this->recordMissing($result, $rel, $directive, $resolved);
                continue;
            }

            $result->okReferences++;
            $result->addReference($this->referencePayload('ok', $rel, $directive, $resolved));
        }
    }

    /**
     * @param array<string,mixed> $directive
     * @param array<string,mixed> $resolved
     */
    private function recordMissing(ReferenceContractResult $result, string $fileRel, array $directive, array $resolved): void
    {
        $result->brokenReferences++;
        $result->addReference($this->referencePayload('missing', $fileRel, $directive, $resolved));
        $statement = (string)$directive['statement'];

        $result->addFailure(ReferenceContractResult::failure(
            'missing_php_include',
            $statement . ' apunta a archivo inexistente',
            $fileRel,
            (int)$directive['line'],
            [
                'reference_type' => $statement,
                'reference' => (string)$resolved['reference'],
                'literal_reference' => (string)$resolved['literal_reference'],
                'resolved_as' => (string)$resolved['resolved_as'],
                'resolved_path' => (string)$resolved['resolved_path'],
                'expression' => (string)$resolved['expression'],
                'phase' => 'execution',
                'failure_domain' => 'references',
                'cause_code' => 'missing_php_include',
            ]
        ));
    }

    /**
     * @param array<string,mixed> $directive
     * @param array<string,mixed> $resolved
     */
    private function recordDynamic(ReferenceContractResult $result, string $fileRel, array $directive, array $resolved): void
    {
        $result->dynamicReferences++;
        $result->addReference($this->referencePayload('dynamic', $fileRel, $directive, $resolved));
        if ($this->config->dynamicSeverity === 'ignore') {
            return;
        }

        $statement = (string)$directive['statement'];
        $payload = [
            'reference_type' => $statement,
            'reference' => (string)$resolved['reference'],
            'literal_reference' => '',
            'resolved_as' => '',
            'resolved_path' => '',
            'expression' => (string)$resolved['expression'],
            'phase' => 'execution',
            'failure_domain' => 'references',
            'cause_code' => 'dynamic_php_include',
        ];

        if ($this->config->dynamicSeverity === 'error') {
            $result->addFailure(ReferenceContractResult::failure(
                'dynamic_php_include',
                'Include PHP dinámico no verificable estáticamente',
                $fileRel,
                (int)$directive['line'],
                $payload
            ));
            return;
        }

        $result->addWarning(ReferenceContractResult::warning(
            'DYNAMIC_PHP_INCLUDE',
            'Include PHP dinámico no verificable estáticamente',
            $fileRel,
            (int)$directive['line'],
            $payload
        ));
    }

    /**
     * @param array<string,mixed> $directive
     * @param array<string,mixed> $resolved
     */
    private function recordIgnored(ReferenceContractResult $result, string $fileRel, array $directive, array $resolved, string $reason): void
    {
        $result->ignoredReferences++;
        $payload = $this->referencePayload('ignored', $fileRel, $directive, $resolved);
        $payload['ignore_reason'] = $reason;
        $result->addReference($payload);
    }

    /**
     * @param array<string,mixed> $directive
     * @param array<string,mixed> $resolved
     * @return array<string,mixed>
     */
    private function referencePayload(string $status, string $fileRel, array $directive, array $resolved): array
    {
        return [
            'type' => (string)$directive['statement'],
            'reference_type' => (string)$directive['statement'],
            'file' => $fileRel,
            'line' => (int)$directive['line'],
            'expression' => (string)($resolved['expression'] ?? $directive['expression'] ?? ''),
            'reference' => (string)($resolved['reference'] ?? ''),
            'literal_reference' => (string)($resolved['literal_reference'] ?? ''),
            'resolved_path' => (string)($resolved['resolved_path'] ?? ''),
            'resolved_as' => (string)($resolved['resolved_as'] ?? ''),
            'status' => $status,
        ];
    }

    private function shouldStop(ReferenceContractResult $result): bool
    {
        if ($result->truncated) {
            return true;
        }

        if ($result->durationMs() > ($this->config->timeoutSec * 1000)) {
            $this->stopWithFailure($result, 'reference_scan_timeout', 'Se alcanzó TESTKIT_REFERENCE_TIMEOUT_SEC.', 'execution');
            return true;
        }

        if ((count($result->failures) + count($result->warnings)) >= $this->config->maxViolations) {
            $this->stopWithFailure($result, 'reference_max_violations_exceeded', 'Se alcanzó TESTKIT_REFERENCE_MAX_VIOLATIONS.', 'execution');
            return true;
        }

        return false;
    }

    private function stopWithFailure(ReferenceContractResult $result, string $kind, string $message, string $phase): void
    {
        if ($result->truncated) {
            return;
        }

        $result->truncated = true;
        $result->addFailure(ReferenceContractResult::failure(
            $kind,
            $message,
            $this->rootRel,
            0,
            [
                'phase' => $phase,
                'failure_domain' => 'references',
                'cause_code' => $kind,
            ]
        ));
    }

    private function shouldIgnoreDir(string $dir): bool
    {
        $base = basename($dir);
        $relToRepo = trim(Paths::relativeToRepo($dir), '/');
        $relToRoot = trim(substr(Paths::normalize($dir), strlen(rtrim($this->rootAbs, '/'))), '/');

        foreach ($this->config->ignoreDirs as $ignore) {
            $ignore = trim(str_replace('\\', '/', $ignore), '/');
            if ($ignore === '') {
                continue;
            }
            if ($base === $ignore || $relToRepo === $ignore || $relToRoot === $ignore) {
                return true;
            }
            if (str_starts_with($relToRepo, $ignore . '/') || str_starts_with($relToRoot, $ignore . '/')) {
                return true;
            }
        }

        return false;
    }

    private function fileIgnoreReason(string $file): ?string
    {
        $relToRepo = trim(Paths::relativeToRepo($file), '/');
        $relToRoot = trim(substr(Paths::normalize($file), strlen(rtrim($this->rootAbs, '/'))), '/');

        foreach ($this->config->ignoreFiles as $ignore) {
            $ignore = trim(str_replace('\\', '/', $ignore), '/');
            if ($ignore === '') {
                continue;
            }
            if ($relToRepo === $ignore || $relToRoot === $ignore) {
                return 'TESTKIT_REFERENCE_IGNORE_FILES';
            }
        }

        foreach ($this->config->ignoreFileRegexes as $regex) {
            if (preg_match($regex, $relToRepo) === 1 || preg_match($regex, $relToRoot) === 1) {
                return 'TESTKIT_REFERENCE_IGNORE_FILE_REGEX';
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $directive
     * @param array<string,mixed> $resolved
     */
    private function referenceIgnoreReason(string $file, array $directive, array $resolved): ?string
    {
        $literal = trim((string)($resolved['literal_reference'] ?? ''));
        $reference = trim((string)($resolved['reference'] ?? ''));
        $expression = trim((string)($resolved['expression'] ?? $directive['expression'] ?? ''));

        foreach ($this->config->ignoreRefs as $ignore) {
            $ignore = trim(str_replace('\\', '/', $ignore));
            if ($ignore === '') {
                continue;
            }
            if ($literal === $ignore || $reference === $ignore) {
                return 'TESTKIT_REFERENCE_IGNORE_REFS';
            }
        }

        foreach ($this->config->ignoreRefRegexes as $regex) {
            if (
                preg_match($regex, $literal) === 1
                || preg_match($regex, $reference) === 1
                || preg_match($regex, $expression) === 1
            ) {
                return 'TESTKIT_REFERENCE_IGNORE_REF_REGEX';
            }
        }

        return null;
    }
}
