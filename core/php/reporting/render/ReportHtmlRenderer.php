<?php
declare(strict_types=1);

/**
 * ============================================================================
 * @file    testkit/core/php/reporting/render/ReportHtmlRenderer.php
 * @brief   Renderiza el reporte consolidado como HTML autosuficiente.
 * ============================================================================
 */

namespace Base\TestKit\Reporting\Render;

require_once __DIR__ . '/../model/ReportArtifact.php';
require_once __DIR__ . '/../model/ReportSummary.php';

use Base\TestKit\Reporting\Model\ReportArtifact;
use Base\TestKit\Reporting\Model\ReportSummary;

final class ReportHtmlRenderer
{
    public function render(ReportSummary $summary): string
    {
        $rows = '';
        foreach ($summary->artifacts as $artifact) {
            $rows .= $this->renderArtifactRow($artifact);
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5">No se cargaron artefactos.</td></tr>';
        }

        $warnings = '';
        foreach ($summary->warnings as $warning) {
            $warnings .= '<li>' . $this->escape($warning) . '</li>';
        }

        $artifactRoots = '';
        foreach ($summary->artifactRoots as $root) {
            $artifactRoots .= '<li><code>' . $this->escape($root) . '</code></li>';
        }

        if ($artifactRoots === '') {
            $artifactRoots = '<li>No se encontraron rutas de artefactos existentes.</li>';
        }

        return '<!doctype html>' . PHP_EOL
            . '<html lang="es"><head><meta charset="utf-8"><title>TestKit Report</title>'
            . '<style>body{font-family:system-ui,sans-serif;line-height:1.45;margin:2rem;max-width:1200px}'
            . 'code{background:#f3f4f6;padding:.12rem .25rem;border-radius:.25rem}'
            . 'table{border-collapse:collapse;width:100%;margin-top:1rem}th,td{border:1px solid #d1d5db;padding:.5rem;text-align:left;vertical-align:top}'
            . 'th{background:#f9fafb}</style></head><body>'
            . '<h1>TestKit Report</h1>'
            . '<p>Estado general: <strong>' . $this->escape($summary->overallStatus) . '</strong></p>'
            . '<p>Workspace: <code>' . $this->escape($summary->workspaceRoot) . '</code></p>'
            . '<p>TestKit: <code>' . $this->escape($summary->testkitRoot) . '</code></p>'
            . '<h2>Rutas inspeccionadas</h2><ul>' . $artifactRoots . '</ul>'
            . ($warnings === '' ? '' : '<h2>Advertencias</h2><ul>' . $warnings . '</ul>')
            . '<h2>Artefactos</h2><table><thead><tr><th>Estado</th><th>Exit</th><th>Tipo</th><th>Artefacto</th><th>Mensaje</th></tr></thead><tbody>'
            . $rows
            . '</tbody></table></body></html>' . PHP_EOL;
    }

    private function renderArtifactRow(ReportArtifact $artifact): string
    {
        return '<tr><td>' . $this->escape($artifact->status) . '</td><td>'
            . $this->escape($artifact->exitCode === null ? '' : (string) $artifact->exitCode) . '</td><td>'
            . $this->escape($artifact->type) . '</td><td><code>'
            . $this->escape($artifact->path) . '</code></td><td>'
            . $this->escape($artifact->message) . '</td></tr>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
