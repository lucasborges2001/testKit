<?php
declare(strict_types=1);

require_once __DIR__ . '/../common/Env.php';
require_once __DIR__ . '/../common/Paths.php';
require_once __DIR__ . '/../common/ProjectEnv.php';
require_once __DIR__ . '/../common/Bootstrap.php';
require_once __DIR__ . '/../common/Trace.php';

require_once __DIR__ . '/InfluxConfig.php';
require_once __DIR__ . '/InfluxHttpClient.php';
require_once __DIR__ . '/InfluxLineProtocol.php';
require_once __DIR__ . '/InfluxClient.php';
require_once __DIR__ . '/InfluxTestRuntime.php';

\Testkit\Core\Common\Bootstrap::init();
