<?php
declare(strict_types=1);

require_once __DIR__ . '/../common/Env.php';
require_once __DIR__ . '/../common/Paths.php';
require_once __DIR__ . '/../common/ProjectEnv.php';
require_once __DIR__ . '/../common/Bootstrap.php';
require_once __DIR__ . '/../common/Trace.php';

require_once __DIR__ . '/StoreAdapter.php';
require_once __DIR__ . '/MysqlStoreAdapter.php';
require_once __DIR__ . '/PgsqlStoreAdapter.php';
require_once __DIR__ . '/StoreRegistry.php';
require_once __DIR__ . '/StoreMaintenance.php';

\Testkit\Core\Common\Bootstrap::init();
