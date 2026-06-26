<?php
declare(strict_types=1);

/**
 * ============================================================================
 * @file    testkit/core/php/reporting/load/ReportFileReader.php
 * @brief   Lee artefactos de reporte con límite de tamaño y manejo seguro.
 * ============================================================================
 */

namespace Base\TestKit\Reporting\Load;

final class ReportFileReader
{
    public function readLimited(string $file, int $limit): ?string
    {
        if (!is_readable($file)) {
            return null;
        }

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $content = stream_get_contents($handle, $limit + 1);
            if ($content === false) {
                return null;
            }

            return strlen($content) > $limit ? substr($content, 0, $limit) : $content;
        } finally {
            fclose($handle);
        }
    }
}
