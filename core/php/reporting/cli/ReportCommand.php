<?php
declare(strict_types=1);

/**
 * ============================================================================
 * @file    testkit/core/php/reporting/cli/ReportCommand.php
 * @brief   Orquesta carga, composición, renderizado y salida del reporte TestKit.
 * ============================================================================
 */

namespace Base\TestKit\Reporting\Cli;

require_once __DIR__ . '/ReportArguments.php';
require_once __DIR__ . '/ReportExitCode.php';
require_once __DIR__ . '/ReportPaths.php';
require_once __DIR__ . '/../load/ReportArtifactParser.php';
require_once __DIR__ . '/../load/ReportFileReader.php';
require_once __DIR__ . '/../load/ReportJsonArtifactParser.php';
require_once __DIR__ . '/../load/ReportTextArtifactParser.php';
require_once __DIR__ . '/../load/ReportXmlArtifactParser.php';
require_once __DIR__ . '/../load/ReportArtifactLoader.php';
require_once __DIR__ . '/../model/ReportSummary.php';
require_once __DIR__ . '/../render/ReportHtmlRenderer.php';
require_once __DIR__ . '/../render/ReportJsonRenderer.php';
require_once __DIR__ . '/../render/ReportTextRenderer.php';
require_once __DIR__ . '/../support/ReportFormat.php';

use Base\TestKit\Reporting\Load\ReportArtifactLoader;
use Base\TestKit\Reporting\Model\ReportSummary;
use Base\TestKit\Reporting\Render\ReportHtmlRenderer;
use Base\TestKit\Reporting\Render\ReportJsonRenderer;
use Base\TestKit\Reporting\Render\ReportTextRenderer;
use Base\TestKit\Reporting\Support\ReportFormat;

final class ReportCommand
{
    public function __construct(
        private readonly ReportArguments $arguments,
        private readonly ReportPaths $paths,
        private readonly ReportArtifactLoader $loader = new ReportArtifactLoader(),
        private readonly ReportTextRenderer $textRenderer = new ReportTextRenderer(),
        private readonly ReportJsonRenderer $jsonRenderer = new ReportJsonRenderer(),
        private readonly ReportHtmlRenderer $htmlRenderer = new ReportHtmlRenderer()
    ) {
    }

    public function run(): int
    {
        if ($this->arguments->showHelp) {
            $this->write($this->helpText());
            return ReportExitCode::OK;
        }

        if ($this->arguments->showVersion) {
            $this->write('testkit report refactor 1.0.0' . PHP_EOL);
            return ReportExitCode::OK;
        }

        $summary = ReportSummary::build(
            $this->paths->workspaceRoot,
            $this->paths->testkitRoot,
            $this->paths->artifactRoots,
            $this->loader->load($this->paths),
            $this->arguments->warnings
        );

        $rendered = $this->render($summary);
        $this->write($rendered);

        return ReportExitCode::fromSummary($summary, $this->arguments->failOn);
    }

    private function render(ReportSummary $summary): string
    {
        return match ($this->arguments->format) {
            ReportFormat::JSON => $this->jsonRenderer->render($summary),
            ReportFormat::HTML => $this->htmlRenderer->render($summary),
            default => $this->textRenderer->render($summary),
        };
    }

    private function write(string $content): void
    {
        if ($this->arguments->outputPath === null) {
            fwrite(STDOUT, $content);
            return;
        }

        $directory = dirname($this->arguments->outputPath);
        if ($directory !== '.' && !is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('No se pudo crear el directorio de salida: %s', $directory));
        }

        if (file_put_contents($this->arguments->outputPath, $content) === false) {
            throw new \RuntimeException(sprintf('No se pudo escribir el reporte: %s', $this->arguments->outputPath));
        }
    }

    private function helpText(): string
    {
        return <<<'TXT'
Uso:
  php testkit/scripts/report.php [opciones] [ruta-de-artefactos ...]

Opciones compatibles:
  --help, -h                       Muestra esta ayuda.
  --version                        Muestra la versión del refactor.
  --format text|json|html          Define formato de salida. Default: text.
  --text | --json | --html         Atajos de formato.
  --output <archivo>, -o <archivo> Escribe la salida en un archivo.
  --workspace <dir>, --root <dir>  Define raíz del workspace/host.
  --artifacts <dir>                Define raíz de artefactos a inspeccionar.
  --reports <dir>                  Alias de --artifacts.
  --fail-on <estado|never>         failed, requires_review, unavailable, unknown o never. Default: failed.

Compatibilidad:
  Sin argumentos conserva el contrato operativo usado por CI/host: detecta workspace,
  rutas frecuentes de reports/.testkit y renderiza un resumen textual en STDOUT.
TXT;
    }
}
