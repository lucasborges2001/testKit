<?php
declare(strict_types=1);

/**
 * ============================================================================
 * @file    testkit/core/php/reporting/load/ReportJsonArtifactParser.php
 * @brief   Extrae estado, exit code y metadatos desde artefactos JSON.
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

final class ReportJsonArtifactParser implements ReportArtifactParser
{
    public function __construct(private readonly ReportFileReader $reader = new ReportFileReader())
    {
    }

    public function supports(string $file): bool
    {
        return strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'json';
    }

    public function parse(string $file, ReportPaths $paths): ?ReportArtifact
    {
        $content = $this->reader->readLimited($file, 1024 * 1024);
        if ($content === null || trim($content) === '') {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return ReportArtifact::fromData(
                $paths->relative($file),
                'json',
                ReportStatus::REQUIRES_REVIEW,
                null,
                basename($file),
                'JSON inválido o no decodificable.'
            );
        }

        return ReportArtifact::fromData(
            $paths->relative($file),
            'json',
            $this->extractStatus($data),
            $this->extractExitCode($data),
            $this->extractTitle($file, $data),
            $this->extractMessage($data),
            $this->compactMetadata($data)
        );
    }

    /** @param array<string, mixed> $data */
    private function extractStatus(array $data): string
    {
        foreach (['overall_status', 'status', 'result', 'state'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                return ReportStatus::normalize((string) $data[$key]);
            }
        }

        foreach (['failed', 'failures', 'errors'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key]) && (int) $data[$key] > 0) {
                return ReportStatus::FAILED;
            }
        }

        return ReportStatus::UNKNOWN;
    }

    /** @param array<string, mixed> $data */
    private function extractExitCode(array $data): ?int
    {
        foreach (['overall_exit', 'exit_code', 'exitCode', 'code'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (int) $data[$key];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function extractTitle(string $file, array $data): string
    {
        foreach (['title', 'name', 'phase', 'suite'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }

        return basename($file);
    }

    /** @param array<string, mixed> $data */
    private function extractMessage(array $data): string
    {
        foreach (['message', 'reason', 'summary', 'description'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }

        return 'Artefacto JSON cargado.';
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function compactMetadata(array $data): array
    {
        $metadata = [];
        foreach (['blocking', 'fail_on_smoke', 'tests', 'passed', 'failed', 'failures', 'errors', 'skipped'] as $key) {
            if (array_key_exists($key, $data) && is_scalar($data[$key])) {
                $metadata[$key] = $data[$key];
            }
        }

        if (isset($data['phases']) && is_array($data['phases'])) {
            $metadata['phases_count'] = count($data['phases']);
        }

        return $metadata;
    }
}
