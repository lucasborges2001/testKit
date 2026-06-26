<?php
declare(strict_types=1);

/**
 * ============================================================================
 * @file    testkit/core/php/reporting/load/ReportArtifactParser.php
 * @brief   Define el contrato para parsers de artefactos de reporte TestKit.
 * ============================================================================
 */

namespace Base\TestKit\Reporting\Load;

require_once __DIR__ . '/../cli/ReportPaths.php';
require_once __DIR__ . '/../model/ReportArtifact.php';

use Base\TestKit\Reporting\Cli\ReportPaths;
use Base\TestKit\Reporting\Model\ReportArtifact;

interface ReportArtifactParser
{
    public function supports(string $file): bool;

    public function parse(string $file, ReportPaths $paths): ?ReportArtifact;
}
