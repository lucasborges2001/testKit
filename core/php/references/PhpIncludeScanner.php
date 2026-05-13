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

        $this->scanDir($this->rootAbs, $result);
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
                'reference_dir_unreadable',
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

        if ($result->filesScanned >= $this->config->maxFiles) {
            $this->truncate($result, 'reference_max_files_exceeded', 'Se alcanzó TESTKIT_REFERENCE_MAX_FILES.');
            return;
        }

        $size = @filesize($file);
        $rel = Paths::relativeToRepo($file);
        if (is_int($size) && $size > $this->config->maxBytesPerFile) {
            $result->skippedFiles++;
            $result->addWarning(ReferenceContractResult::warning(
                'reference_file_too_large',
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
            $result->addWarning(ReferenceContractResult::warning(
                'reference_file_unreadable',
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
            if ((bool)$resolved['dynamic']) {
                $this->recordDynamic($result, $rel, $directive, $resolved);
                continue;
            }

            if (!is_file((string)$resolved['resolved_path'])) {
                $result->brokenReferences++;
                $result->addFailure(ReferenceContractResult::failure(
                    'missing_php_include',
                    (string)$directive['statement'] . ' apunta a archivo inexistente',
                    $rel,
                    (int)$directive['line'],
                    [
                        'reference' => (string)$resolved['reference'],
                        'resolved_as' => (string)$resolved['resolved_as'],
                        'statement' => (string)$directive['statement'],
                        'expression' => (string)$resolved['expression'],
                        'phase' => 'execution',
                        'failure_domain' => 'references',
                        'cause_code' => 'missing_php_include',
                    ]
                ));
            }
        }
    }

    /**
     * @param array<string,mixed> $directive
     * @param array<string,mixed> $resolved
     */
    private function recordDynamic(ReferenceContractResult $result, string $fileRel, array $directive, array $resolved): void
    {
        $result->dynamicReferences++;
        if ($this->config->dynamicSeverity === 'ignore') {
            return;
        }

        $payload = [
            'reference' => (string)$resolved['reference'],
            'statement' => (string)$directive['statement'],
            'expression' => (string)$resolved['expression'],
            'phase' => 'execution',
            'failure_domain' => 'references',
            'cause_code' => 'dynamic_php_include',
        ];

        if ($this->config->dynamicSeverity === 'error') {
            $result->addFailure(ReferenceContractResult::failure(
                'dynamic_php_include',
                (string)$directive['statement'] . ' usa una expresión dinámica no resoluble estáticamente',
                $fileRel,
                (int)$directive['line'],
                $payload
            ));
            return;
        }

        $result->addWarning(ReferenceContractResult::warning(
            'dynamic_php_include',
            (string)$directive['statement'] . ' usa una expresión dinámica no resoluble estáticamente',
            $fileRel,
            (int)$directive['line'],
            $payload
        ));
    }

    private function shouldStop(ReferenceContractResult $result): bool
    {
        if ($result->truncated) {
            return true;
        }

        if ($result->durationMs() > ($this->config->timeoutSec * 1000)) {
            $this->truncate($result, 'reference_timeout_exceeded', 'Se alcanzó TESTKIT_REFERENCE_TIMEOUT_SEC.');
            return true;
        }

        if ((count($result->failures) + count($result->warnings)) >= $this->config->maxViolations) {
            $this->truncate($result, 'reference_max_violations_exceeded', 'Se alcanzó TESTKIT_REFERENCE_MAX_VIOLATIONS.');
            return true;
        }

        return false;
    }

    private function truncate(ReferenceContractResult $result, string $kind, string $message): void
    {
        if ($result->truncated) {
            return;
        }

        $result->truncated = true;
        $result->addWarning(ReferenceContractResult::warning(
            $kind,
            $message,
            $this->rootRel,
            0,
            ['phase' => 'discovery']
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
}
