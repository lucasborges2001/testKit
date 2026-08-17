<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/bootstrap.php';
require_once __DIR__ . '/../core/php/cleanup/ResetCommand.php';

exit(\Testkit\Core\Cleanup\ResetCommand::runCli($argv));
