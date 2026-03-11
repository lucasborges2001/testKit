<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/bootstrap.php';

$target = $argv[1] ?? '';
$code = \Testkit\Core\Suites\MetaRunner::run((string)$target);
exit($code);
