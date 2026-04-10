<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/bootstrap.php';

exit(\Testkit\Core\Reporting\Inspector::runCli($argv));
