<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting\Console;

require_once __DIR__ . '/IssueWarningsRenderer.php';
require_once __DIR__ . '/IssueSelectionRenderer.php';
require_once __DIR__ . '/IssueFailureRenderer.php';
require_once __DIR__ . '/IssueDiagnosticsRenderer.php';
require_once __DIR__ . '/IssueTriageRenderer.php';
require_once __DIR__ . '/IssuePerformanceRenderer.php';
require_once __DIR__ . '/IssueModuleSummaryRenderer.php';
require_once __DIR__ . '/IssueRegressionRenderer.php';
require_once __DIR__ . '/RecommendedActionPresenter.php';

final class ConsoleIssueRenderer
{
    public static function printWarnings(array $result): void
    {
        IssueWarningsRenderer::printWarnings($result);
    }

    public static function printSelectionSummary(array $result): void
    {
        IssueSelectionRenderer::printSelectionSummary($result);
    }

    public static function printFirstFailure(array $result): void
    {
        IssueFailureRenderer::printFirstFailure($result);
    }

    public static function printOperatorSummary(array $result, array $diagnostics): void
    {
        RecommendedActionPresenter::printOperatorSummary($result, $diagnostics);
    }

    public static function printRecommendedActions(array $result, array $diagnostics): void
    {
        RecommendedActionPresenter::printRecommendedActions($result, $diagnostics);
    }

    public static function printRegressionDelta(array $result): void
    {
        IssueRegressionRenderer::printRegressionDelta($result);
    }

    public static function shouldPrintModuleSummary(array $result, bool $compactPassed): bool
    {
        return IssueModuleSummaryRenderer::shouldPrint($result, $compactPassed);
    }

    public static function printModuleSummary(array $result): void
    {
        IssueModuleSummaryRenderer::printModuleSummary($result);
    }

    public static function printFailures(array $result): void
    {
        IssueFailureRenderer::printFailures($result);
    }

    public static function printDiagnostics(array $diagnostics, bool $compactPassed = false): void
    {
        IssueDiagnosticsRenderer::printDiagnostics($diagnostics, $compactPassed);
    }

    public static function printTriage(array $result): void
    {
        IssueTriageRenderer::printTriage($result);
    }

    public static function printSlow(array $result): void
    {
        IssuePerformanceRenderer::printSlow($result);
    }

    public static function printFragility(array $result): void
    {
        IssuePerformanceRenderer::printFragility($result);
    }

    public static function printPerfViolations(array $result): void
    {
        IssuePerformanceRenderer::printPerfViolations($result);
    }

    public static function hasActionableDiagnostics(array $diagnostics): bool
    {
        return IssueDiagnosticsRenderer::hasActionableDiagnostics($diagnostics);
    }

    /**
     * @return array<int,array{command:string,reason:string}>
     */
    public static function collectRecommendedActions(array $result, array $diagnostics): array
    {
        return RecommendedActionPresenter::collectRecommendedActions($result, $diagnostics);
    }

    /**
     * @param array<int,array{command:string,reason:string}> $actions
     */
    public static function primaryActionCommand(array $actions): string
    {
        return RecommendedActionPresenter::primaryActionCommand($actions);
    }

    private function __construct()
    {
    }
}
