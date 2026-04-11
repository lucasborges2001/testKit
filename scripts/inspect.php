<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/bootstrap.php';
require_once __DIR__ . '/../core/php/reporting/SeedStateInspector.php';

$seedStateExit = \Testkit\Core\Reporting\SeedStateInspector::maybeHandleCli($argv);
if (is_int($seedStateExit)) {
    exit($seedStateExit);
}

exit(\Testkit\Core\Reporting\Inspector::runCli($argv));
