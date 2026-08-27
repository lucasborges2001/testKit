#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/php/plc/TaskTimingDesignAudit.php';
require_once $root . '/utils/php/assert.php';

use Testkit\Core\Plc\TaskTimingDesignAudit;

try {
    $audit = (new TaskTimingDesignAudit())->audit([
        'task' => [
            'name' => 'TASK_Main',
            'mode' => 'CYCLIC',
            'programs' => ['MAIN'],
            'interval' => 'T#20ms',
            'priority' => '15',
            'watchdog' => 'T#100ms',
        ],
        'staticAnalysis' => ['timers' => [['name' => 'frameIdleTimer']], 'files' => []],
        'candidates' => [
            ['candidate' => 'A', 'interval' => 'T#10ms', 'result' => 'FAIL'],
            ['candidate' => 'B', 'interval' => 'T#20ms', 'result' => 'PASS', 'selected' => true],
        ],
        'priority' => ['selected' => '15', 'valid_range' => '0..31', 'semantics' => 'lower number is higher priority'],
        'watchdog' => ['selected' => 'T#100ms', 'margin_ms' => 60],
    ]);

    t_eq($audit['schema'], 'testkit.plc.task-timing-design.v1', 'schema');
    t_eq($audit['gates']['TASK_CONTRACT'], 'PASS', 'contract gate');
    t_eq($audit['gates']['TASK_RUNTIME_READONLY'], 'NO_EJECUTADO', 'runtime gate default');
    t_eq($audit['selected']['interval'], 'T#20ms', 'selected interval');

    $blocked = (new TaskTimingDesignAudit())->audit(['task' => ['name' => 'TASK_Main']]);
    t_eq($blocked['gates']['TASK_CONTRACT'], 'BLOCKED', 'blocked contract');
    t_eq($blocked['gates']['TASK_STATIC_AUDIT'], 'BLOCKED', 'blocked static audit');

    echo "PASS plc task timing design audit\n";
    exit(0);
} catch (Throwable $e) {
    t_print_fail($e);
    exit(1);
}
