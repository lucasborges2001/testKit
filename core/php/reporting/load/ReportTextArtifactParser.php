<?php
declare(strict_types=1);

/**
 * ============================================================================
 * @file    testkit/core/php/reporting/load/ReportTextArtifactParser.php
 * @brief   Extrae señales de estado desde artefactos Markdown y texto plano.
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

final class ReportTextArtifactParser implements ReportArtifactParser
{
    public function __construct(private readonly ReportFileReader $reader = new ReportFileReader())
    {
    }

    public function supports(string $file): bool
    {
        return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['md', 'txt'], true);
    }

    public function parse(string $file, ReportPaths $paths): ?ReportArtifact
    {
        $content = $this->reader->readLimited($file, 300 * 1024);
        if ($content === null || trim($content) === '') {
            return null;
        }

        return ReportArtifact::fromData(
            $paths->relative($file),
            strtolower(pathinfo($file, PATHINFO_EXTENSION)),
            $this->extractStatus($content),
            $this->extractExitCode($content),
            $this->extractTitle($file, $content),
            $this->extractMessage($content),
            ['bytes' => filesize($file) ?: 0]
        );
    }

    private function extractStatus(string $content): string
    {
        $patterns = [
            '/Estado(?:\s+general)?\s*:\s*\*\*?([a-zA-Z_ -]+)\*\*?/u',
            '/status\s*[:=]\s*([a-zA-Z_ -]+)/i',
            '/overall_status\s*[:=]\s*([a-zA-Z_ -]+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches) === 1) {
                return ReportStatus::normalize($matches[1]);
            }
        }

        if (preg_match('/\b(error|failed|failure|fall[oó]|fallos)\b/iu', $content) === 1) {
            return ReportStatus::REQUIRES_REVIEW;
        }

        if (preg_match('/\b(ok|success|passed|completado correctamente)\b/iu', $content) === 1) {
            return ReportStatus::OK;
        }

        return ReportStatus::UNKNOWN;
    }

    private function extractExitCode(string $content): ?int
    {
        $pattern = '/exit\s*code(?:\s+real)?\s*(?:de[^:]+)?\s*[:=]?\s*\*?\*?(\d+)/iu';
        if (preg_match($pattern, $content, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function extractTitle(string $file, string $content): string
    {
        if (preg_match('/^#\s+(.+)$/m', $content, $matches) === 1) {
            return trim($matches[1]);
        }

        return basename($file);
    }

    private function extractMessage(string $content): string
    {
        $lines = preg_split('/\R/', trim($content)) ?: [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || str_contains($trimmed, '|---')) {
                continue;
            }

            return mb_substr($trimmed, 0, 240);
        }

        return 'Artefacto textual cargado.';
    }
}
