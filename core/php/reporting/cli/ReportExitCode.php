<?php
declare(strict_types=1);

/**
 * ============================================================================
 * @file    testkit/core/php/reporting/cli/ReportExitCode.php
 * @brief   Calcula códigos de salida preservando semántica configurable de CI.
 * ============================================================================
 */

namespace Base\TestKit\Reporting\Cli;

require_once __DIR__ . '/../model/ReportSummary.php';
require_once __DIR__ . '/../support/ReportStatus.php';

use Base\TestKit\Reporting\Model\ReportSummary;
use Base\TestKit\Reporting\Support\ReportStatus;

final class ReportExitCode
{
    public const OK = 0;
    public const REQUIRES_REVIEW = 1;
    public const USAGE = 2;
    public const TECHNICAL_ERROR = 3;

    public static function fromSummary(ReportSummary $summary, string $failOn): int
    {
        if ($failOn === 'never') {
            return self::OK;
        }

        $thresholdRank = ReportStatus::rank($failOn);
        $overallRank = ReportStatus::rank($summary->overallStatus);

        if ($overallRank >= $thresholdRank) {
            foreach ($summary->artifacts as $artifact) {
                if ($artifact->exitCode !== null && $artifact->exitCode !== 0 && ReportStatus::rank($artifact->status) >= $thresholdRank) {
                    return $artifact->exitCode;
                }
            }

            return self::REQUIRES_REVIEW;
        }

        return self::OK;
    }
}
