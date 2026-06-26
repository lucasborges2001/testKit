<?php
declare(strict_types=1);

/**
 * ============================================================================
 * @file    testkit/core/php/reporting/render/ReportJsonRenderer.php
 * @brief   Renderiza el reporte consolidado como JSON estable para CI.
 * ============================================================================
 */

namespace Base\TestKit\Reporting\Render;

require_once __DIR__ . '/../model/ReportSummary.php';

use Base\TestKit\Reporting\Model\ReportSummary;

final class ReportJsonRenderer
{
    public function render(ReportSummary $summary): string
    {
        $json = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($json)) {
            throw new \RuntimeException('No se pudo serializar el reporte como JSON.');
        }

        return $json . PHP_EOL;
    }
}
