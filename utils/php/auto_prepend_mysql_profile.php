<?php
declare(strict_types=1);

require_once __DIR__ . '/auto_prepend.php';

$tkRoot = rtrim((string)(getenv('TESTKIT_ROOT') ?: dirname(__DIR__, 2)), '/\\');
require_once $tkRoot . '/core/php/dbprofiling/public_api.php';

putenv('TESTKIT_DB_PROFILE_BOOTSTRAPPED=1');
$_ENV['TESTKIT_DB_PROFILE_BOOTSTRAPPED'] = '1';
$_SERVER['TESTKIT_DB_PROFILE_BOOTSTRAPPED'] = '1';

\Testkit\Core\DbProfiling\QueryProfileCollector::markBootstrapped();
\Testkit\Core\DbProfiling\QueryProfileCollector::registerShutdown();
