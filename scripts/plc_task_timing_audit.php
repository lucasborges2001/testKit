#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/php/plc/TaskTimingDesignAudit.php';

use Testkit\Core\Plc\TaskTimingDesignAudit;

if ($argc !== 2) {
    fwrite(STDERR, "usage: plc_task_timing_audit.php input.json\n");
    exit(2);
}

$input = json_decode((string)file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
echo json_encode((new TaskTimingDesignAudit())->audit($input), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
