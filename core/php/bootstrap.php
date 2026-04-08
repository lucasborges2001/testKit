<?php
declare(strict_types=1);

require_once __DIR__ . '/common/Env.php';
require_once __DIR__ . '/common/Paths.php';
require_once __DIR__ . '/common/Bootstrap.php';
require_once __DIR__ . '/common/ProjectEnv.php';
require_once __DIR__ . '/common/Trace.php';

require_once __DIR__ . '/config/RunnerConfig.php';
require_once __DIR__ . '/discovery/TestTagger.php';
require_once __DIR__ . '/discovery/TestDiscovery.php';

require_once __DIR__ . '/execution/ProcessRunner.php';
require_once __DIR__ . '/execution/SuiteExecutor.php';

require_once __DIR__ . '/coverage/CoverageMerger.php';
require_once __DIR__ . '/coverage/CoverageDiagnostics.php';

require_once __DIR__ . '/reporting/UI.php';
require_once __DIR__ . '/reporting/ReportSummary.php';
require_once __DIR__ . '/reporting/ConsoleReporter.php';
require_once __DIR__ . '/reporting/HistoryRepository.php';
require_once __DIR__ . '/reporting/ResultWriter.php';

require_once __DIR__ . '/suites/SuiteOrchestrator.php';
require_once __DIR__ . '/suites/ContractWorldBootstrap.php';
require_once __DIR__ . '/suites/BackPhpSuite.php';
require_once __DIR__ . '/suites/FrontPhpSuite.php';
require_once __DIR__ . '/suites/FrontJsSuite.php';
require_once __DIR__ . '/suites/BackPythonSuite.php';
require_once __DIR__ . '/suites/MetaRunner.php';

\Testkit\Core\Common\Bootstrap::init();