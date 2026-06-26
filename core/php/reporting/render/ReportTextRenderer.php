<?php
declare(strict_types=1);

/**
 * ============================================================================
 * @file    testkit/core/php/reporting/render/ReportTextRenderer.php
 * @brief   Renderiza el reporte consolidado en formato Markdown/texto legible.
 * ============================================================================
 */

namespace Base\TestKit\Reporting\Render;

require_once __DIR__ . '/../model/ReportArtifact.php';
require_once __DIR__ . '/../model/ReportSummary.php';

use Base\TestKit\Reporting\Model\ReportArtifact;
use Base\TestKit\Reporting\Model\ReportSummary;

final class ReportTextRenderer
{
    public function render(ReportSummary $summary): string
    {
        $lines = [];
        $lines[] = '# TestKit Report';
        $lines[] = '';
        $lines[] = sprintf('Estado general: **%s**', $summary->overallStatus);
        $lines[] = sprintf('Workspace: `%s`', $summary->workspaceRoot);
        $lines[] = sprintf('TestKit: `%s`', $summary->testkitRoot);
        $lines[] = '';
        $lines[] = '## Rutas de artefactos inspeccionadas';
        $lines[] = '';

        if ($summary->artifactRoots === []) {
            $lines[] = '- No se encontraron rutas de artefactos existentes.';
        } else {
            foreach ($summary->artifactRoots as $root) {
                $lines[] = sprintf('- `%s`', $root);
            }
        }

        $lines[] = '';
        $lines[] = '## Conteo por estado';
        $lines[] = '';
        $lines[] = '| Estado | Cantidad |';
        $lines[] = '|---|---:|';
        foreach ($summary->statusCounts() as $status => $count) {
            if ($count > 0) {
                $lines[] = sprintf('| %s | %d |', $status, $count);
            }
        }

        if ($summary->warnings !== []) {
            $lines[] = '';
            $lines[] = '## Advertencias';
            $lines[] = '';
            foreach ($summary->warnings as $warning) {
                $lines[] = sprintf('- %s', $warning);
            }
        }

        $lines[] = '';
        $lines[] = '## Artefactos';
        $lines[] = '';

        if ($summary->artifacts === []) {
            $lines[] = 'No se cargaron artefactos.';
        } else {
            $lines[] = '| Estado | Exit | Tipo | Artefacto | Mensaje |';
            $lines[] = '|---|---:|---|---|---|';
            foreach ($summary->artifacts as $artifact) {
                $lines[] = $this->renderArtifactRow($artifact);
            }
        }

        $lines[] = '';
        return implode(PHP_EOL, $lines);
    }

    private function renderArtifactRow(ReportArtifact $artifact): string
    {
        return sprintf(
            '| %s | %s | %s | `%s` | %s |',
            $this->escapeTable($artifact->status),
            $artifact->exitCode === null ? '' : (string) $artifact->exitCode,
            $this->escapeTable($artifact->type),
            $this->escapeTable($artifact->path),
            $this->escapeTable($artifact->message)
        );
    }

    private function escapeTable(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        return str_replace('|', '\\|', $value);
    }
}
