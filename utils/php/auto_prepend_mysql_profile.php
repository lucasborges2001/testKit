<?php
declare(strict_types=1);

require_once __DIR__ . '/auto_prepend.php';

$tkRoot = rtrim((string)(getenv('TESTKIT_ROOT') ?: dirname(__DIR__, 2)), '/\\');
require_once $tkRoot . '/core/php/dbprofiling/public_api.php';

\Testkit\Core\DbProfiling\QueryProfileCollector::registerShutdown();
