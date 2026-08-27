#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/php/plc/bootstrap.php';

use Testkit\Core\Plc\PlcAuditProbe;

try {
    $inputPath = $argv[1] ?? 'php://stdin';
    $raw = (string)file_get_contents($inputPath);
    $input = $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        throw new RuntimeException('audit input must be a JSON object');
    }
    $audit = (new PlcAuditProbe())->audit($input);
    echo json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(($audit['warnings'] ?? []) === [] ? 0 : 2);
} catch (Throwable $e) {
    echo json_encode([
        'schema' => 'testkit.plc.audit.v1',
        'timestamp' => gmdate('c'),
        'source' => 'testkit',
        'result' => [],
        'evidence' => [],
        'warnings' => [$e->getMessage()],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
