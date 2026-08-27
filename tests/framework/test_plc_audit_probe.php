#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/php/plc/bootstrap.php';
require_once $root . '/utils/php/assert.php';

use Testkit\Core\Plc\PlcAuditProbe;

try {
    $probe = new PlcAuditProbe();

    $audit = $probe->audit([
        'source' => 'fixture',
        'target' => [
            'state' => 'CORROBORATED',
            'values' => ['model' => 'WAGO 750-8202'],
            'evidence' => ['old project metadata', 'runtime note'],
        ],
        'task' => [
            'state' => 'UNKNOWN',
            'values' => ['name' => 'TASK_Main', 'type' => 'CYCLIC', 'programs' => ['MAIN']],
        ],
        'ioMap' => [
            'state' => 'CANDIDATE',
            'mappings' => [
                ['address' => '%QX0.0', 'symbol' => 'BOX_001', 'state' => 'CANDIDATE'],
                ['address' => '%QX0.1', 'symbol' => 'BOX_002', 'state' => 'INFERRED'],
            ],
        ],
        'readonlySnapshots' => [
            ['windows' => [['function' => 3, 'startAddress' => 0, 'quantity' => 1]]],
        ],
    ]);
    t_eq($audit['schema'], 'testkit.plc.audit.v1', 'schema');
    t_eq($audit['result']['target']['state'], 'CORROBORATED', 'target state');
    t_eq($audit['result']['task']['state'], 'UNKNOWN', 'task state');
    t_eq($audit['result']['ioMap']['state'], 'CANDIDATE', 'io state');
    t_eq($audit['warnings'], [], 'read-only plan warnings');

    $conflict = $probe->audit([
        'ioMap' => [
            'mappings' => [
                ['address' => '%IX0.0', 'symbol' => 'A', 'state' => 'CANDIDATE'],
                ['address' => '%IX0.0', 'symbol' => 'B', 'state' => 'CANDIDATE'],
            ],
        ],
    ]);
    t_eq($conflict['result']['ioMap']['state'], 'CONFLICT', 'conflict state');
    t_eq($conflict['result']['ioMap']['conflicts'][0]['address'], '%IX0.0', 'conflict address');

    $writePlan = $probe->audit([
        'readonlySnapshots' => [
            ['windows' => [['function' => 16, 'startAddress' => 0, 'quantity' => 1]]],
        ],
    ]);
    t_contains('FC16', implode("\n", $writePlan['warnings']), 'write function rejected');

    $cli = shell_exec('php ' . escapeshellarg($root . '/scripts/plc_audit_probe.php') . ' 2>/dev/null');
    t_contains('testkit.plc.audit.v1', (string)$cli, 'CLI emits audit JSON');

    echo "PASS plc audit probe\n";
    exit(0);
} catch (Throwable $e) {
    t_print_fail($e);
    exit(1);
}
