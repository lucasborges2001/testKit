<?php
declare(strict_types=1);

require_once __DIR__ . '/../common/Env.php';
require_once __DIR__ . '/../common/Paths.php';
require_once __DIR__ . '/MysqlProfileConfig.php';
require_once __DIR__ . '/SqlFingerprint.php';
require_once __DIR__ . '/QueryProfileCollector.php';
require_once __DIR__ . '/ProfiledPDOStatement.php';
require_once __DIR__ . '/ProfiledPDO.php';
require_once __DIR__ . '/MysqlExplainPlanParser.php';
require_once __DIR__ . '/MysqlExplainAnalyzer.php';
require_once __DIR__ . '/MysqlProfileReporter.php';
