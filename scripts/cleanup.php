<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/bootstrap.php';
require_once __DIR__ . '/../core/php/cleanup/CleanupCommand.php';

exit(\Testkit\Core\Cleanup\CleanupCommand::runCli($argv));
