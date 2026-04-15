<?php
declare(strict_types=1);

require_once __DIR__ . '/common/Env.php';
require_once __DIR__ . '/common/Paths.php';
require_once __DIR__ . '/common/Bootstrap.php';
require_once __DIR__ . '/common/ProjectEnv.php';
require_once __DIR__ . '/common/Trace.php';
require_once __DIR__ . '/common/Lock.php';

require_once __DIR__ . '/config/SuiteContractRegistry.php';
require_once __DIR__ . '/config/RunnerConfig.php';
require_once __DIR__ . '/discovery/TestTagger.php';
require_once __DIR__ . '/discovery/TestDiscovery.php';
require_once __DIR__ . '/discovery/TestSeedMetadata.php';

require_once __DIR__ . '/execution/ProcessRunner.php';
require_once __DIR__ . '/execution/SuiteExecutor.php';
require_once __DIR__ . '/execution/ParallelGuard.php';

require_once __DIR__ . '/coverage/CoverageMerger.php';
require_once __DIR__ . '/coverage/CoverageDiagnostics.php';

require_once __DIR__ . '/store/bootstrap.php';
require_once __DIR__ . '/seeding/BaselineManifest.php';
require_once __DIR__ . '/seeding/BaselineModeResolver.php';
require_once __DIR__ . '/seeding/BaselineReuseDecider.php';
require_once __DIR__ . '/seeding/ManifestPlanBuilder.php';
require_once __DIR__ . '/seeding/MigrationCatalog.php';
require_once __DIR__ . '/seeding/MigrationPlanResolver.php';
require_once __DIR__ . '/seeding/SuiteSeedState.php';

require_once __DIR__ . '/reporting/UI.php';
require_once __DIR__ . '/reporting/StructuredWarnings.php';
require_once __DIR__ . '/reporting/FailureExcerpt.php';
require_once __DIR__ . '/reporting/FailureNormalizer.php';
require_once __DIR__ . '/reporting/FailureGrouping.php';
require_once __DIR__ . '/reporting/OutcomeDiagnostics.php';
require_once __DIR__ . '/reporting/SelectionManifestBuilder.php';
require_once __DIR__ . '/reporting/RegressionDeltaBuilder.php';
require_once __DIR__ . '/reporting/ArtifactNormalizer.php';
require_once __DIR__ . '/reporting/RecommendedActionBuilder.php';
require_once __DIR__ . '/reporting/AgentSummaryBuilder.php';
require_once __DIR__ . '/reporting/MetaReportBuilder.php';
require_once __DIR__ . '/reporting/ReportLocator.php';
require_once __DIR__ . '/reporting/ReportSummary.php';
require_once __DIR__ . '/reporting/CanonicalReport.php';
require_once __DIR__ . '/reporting/FailureClassifier.php';
require_once __DIR__ . '/reporting/ConsoleReporter.php';
require_once __DIR__ . '/reporting/HistoryRepository.php';
require_once __DIR__ . '/reporting/AtomicJsonWriter.php';
require_once __DIR__ . '/reporting/ReportDecorator.php';
require_once __DIR__ . '/reporting/ReportFileNamer.php';
require_once __DIR__ . '/reporting/RunIndexWriter.php';
require_once __DIR__ . '/reporting/FailureDelta.php';
require_once __DIR__ . '/reporting/ResultWriter.php';
require_once __DIR__ . '/reporting/AgentRunArtifact.php';
require_once __DIR__ . '/reporting/Inspector.php';
require_once __DIR__ . '/reporting/AgentRunExecute.php';
require_once __DIR__ . '/reporting/AgentRun.php';

require_once __DIR__ . '/suites/SuiteOrchestrator.php';
require_once __DIR__ . '/suites/ContractWorldBootstrap.php';
require_once __DIR__ . '/suites/TargetResolver.php';
require_once __DIR__ . '/suites/MetaActionRequiredRenderer.php';
require_once __DIR__ . '/suites/MetaOperationalFailureBuilder.php';
require_once __DIR__ . '/suites/BackPhpSuite.php';
require_once __DIR__ . '/suites/FrontPhpSuite.php';
require_once __DIR__ . '/suites/FrontJsSuite.php';
require_once __DIR__ . '/suites/BackPythonSuite.php';
require_once __DIR__ . '/suites/MigrationContractSuite.php';
require_once __DIR__ . '/suites/MetaRunner.php';

\Testkit\Core\Common\Bootstrap::init();
