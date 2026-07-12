<?php
declare(strict_types=1);

$pdo = tk_profiled_pdo(
    (string)getenv('TEST_DB_DSN'),
    (string)getenv('TEST_DB_USER'),
    (string)getenv('TEST_DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    ['module_id' => 'fixture.sql', 'scenario_id' => 'blocked_gate']
);
$value = $pdo->query('SELECT value_text FROM fixture_items WHERE id = 1')->fetchColumn();
if ($value !== 'captured') {
    throw new RuntimeException('Fixture query returned an unexpected value.');
}
echo "OK blocked gate fixture functional test\n";

