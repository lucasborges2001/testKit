<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/bootstrap.php';
require_once __DIR__ . '/../core/php/reporting/DefinitionOfDoneValidator.php';

exit(\Testkit\Core\Reporting\DefinitionOfDoneValidator::runCli($argv));
