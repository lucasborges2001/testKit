<?php
declare(strict_types=1);

/**
 * ============================================================================
 * @file    testkit/core/php/reporting/load/ReportXmlArtifactParser.php
 * @brief   Extrae resultados desde artefactos XML compatibles con JUnit/Clover.
 * ============================================================================
 */

namespace Base\TestKit\Reporting\Load;

require_once __DIR__ . '/ReportArtifactParser.php';
require_once __DIR__ . '/ReportFileReader.php';
require_once __DIR__ . '/../cli/ReportPaths.php';
require_once __DIR__ . '/../model/ReportArtifact.php';
require_once __DIR__ . '/../support/ReportStatus.php';

use Base\TestKit\Reporting\Cli\ReportPaths;
use Base\TestKit\Reporting\Model\ReportArtifact;
use Base\TestKit\Reporting\Support\ReportStatus;

final class ReportXmlArtifactParser implements ReportArtifactParser
{
    public function __construct(private readonly ReportFileReader $reader = new ReportFileReader())
    {
    }

    public function supports(string $file): bool
    {
        return strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'xml';
    }

    public function parse(string $file, ReportPaths $paths): ?ReportArtifact
    {
        $content = $this->reader->readLimited($file, 1024 * 1024);
        if ($content === null || trim($content) === '') {
            return null;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        if ($xml === false) {
            libxml_clear_errors();
            return ReportArtifact::fromData(
                $paths->relative($file),
                'xml',
                ReportStatus::REQUIRES_REVIEW,
                null,
                basename($file),
                'XML inválido o no decodificable.',
                ['bytes' => filesize($file) ?: 0]
            );
        }

        $attributes = $xml->attributes();
        $failures = isset($attributes['failures']) ? (int) $attributes['failures'] : 0;
        $errors = isset($attributes['errors']) ? (int) $attributes['errors'] : 0;
        $tests = isset($attributes['tests']) ? (int) $attributes['tests'] : null;
        $skipped = isset($attributes['skipped']) ? (int) $attributes['skipped'] : 0;
        [$status, $exitCode] = $this->statusAndExit($tests, $failures, $errors, $skipped);

        return ReportArtifact::fromData(
            $paths->relative($file),
            'xml',
            $status,
            $exitCode,
            basename($file),
            sprintf('XML detectado: tests=%s failures=%d errors=%d skipped=%d', $tests ?? 'n/a', $failures, $errors, $skipped),
            array_filter([
                'tests' => $tests,
                'failures' => $failures,
                'errors' => $errors,
                'skipped' => $skipped,
                'bytes' => filesize($file) ?: 0,
            ], static fn ($value): bool => $value !== null)
        );
    }

    /** @return array{0: string, 1: ?int} */
    private function statusAndExit(?int $tests, int $failures, int $errors, int $skipped): array
    {
        if ($failures > 0 || $errors > 0) {
            return [ReportStatus::FAILED, 1];
        }

        if ($tests !== null && $tests === $skipped && $tests > 0) {
            return [ReportStatus::SKIPPED, 0];
        }

        if ($tests !== null) {
            return [ReportStatus::OK, 0];
        }

        return [ReportStatus::UNKNOWN, null];
    }
}
