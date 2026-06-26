<?php
declare(strict_types=1);

/**
 * ============================================================================
 * @file    testkit/scripts/report.php
 * @brief   Entrypoint CLI liviano para generar reportes consolidados de TestKit.
 * ============================================================================
 */

require_once __DIR__ . '/../core/php/reporting/support/ReportFormat.php';
require_once __DIR__ . '/../core/php/reporting/support/ReportStatus.php';
require_once __DIR__ . '/../core/php/reporting/cli/ReportArguments.php';
require_once __DIR__ . '/../core/php/reporting/cli/ReportPaths.php';
require_once __DIR__ . '/../core/php/reporting/cli/ReportExitCode.php';
require_once __DIR__ . '/../core/php/reporting/model/ReportArtifact.php';
require_once __DIR__ . '/../core/php/reporting/model/ReportSummary.php';
require_once __DIR__ . '/../core/php/reporting/load/ReportArtifactParser.php';
require_once __DIR__ . '/../core/php/reporting/load/ReportFileReader.php';
require_once __DIR__ . '/../core/php/reporting/load/ReportJsonArtifactParser.php';
require_once __DIR__ . '/../core/php/reporting/load/ReportTextArtifactParser.php';
require_once __DIR__ . '/../core/php/reporting/load/ReportXmlArtifactParser.php';
require_once __DIR__ . '/../core/php/reporting/load/ReportArtifactLoader.php';
require_once __DIR__ . '/../core/php/reporting/render/ReportTextRenderer.php';
require_once __DIR__ . '/../core/php/reporting/render/ReportJsonRenderer.php';
require_once __DIR__ . '/../core/php/reporting/render/ReportHtmlRenderer.php';
require_once __DIR__ . '/../core/php/reporting/cli/ReportCommand.php';

use Base\TestKit\Reporting\Cli\ReportArguments;
use Base\TestKit\Reporting\Cli\ReportCommand;
use Base\TestKit\Reporting\Cli\ReportExitCode;
use Base\TestKit\Reporting\Cli\ReportPaths;

try {
    $arguments = ReportArguments::fromArgv($argv);
    $paths = ReportPaths::fromScript(__FILE__, $arguments);
    $command = new ReportCommand($arguments, $paths);

    exit($command->run());
} catch (\InvalidArgumentException $exception) {
    fwrite(STDERR, '[report] ERROR: ' . $exception->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Usar --help para ver opciones disponibles.' . PHP_EOL);
    exit(ReportExitCode::USAGE);
} catch (\Throwable $exception) {
    fwrite(STDERR, '[report] ERROR técnico: ' . $exception->getMessage() . PHP_EOL);
    exit(ReportExitCode::TECHNICAL_ERROR);
}
