<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Tarifa\TarifaContractSupport;

$a = TarifaContractSupport::pricingSnapshotFixture('tk1');
$b = TarifaContractSupport::pricingSnapshotFixture('tk1');
if ($a !== $b) {
    throw new RuntimeException('fixture is not deterministic');
}

foreach (['group', 'sede', 'organization'] as $scope) {
    $snapshot = TarifaContractSupport::pricingSnapshotFixture('scope-' . $scope, [
        'scope' => $scope,
        'resolved_scope_reference_id' => 10,
    ]);
    TarifaContractSupport::validateSnapshot($snapshot, (int)$snapshot['organization_id']);
}

foreach ([
    ['currency' => 'uyu'],
    ['total_minor' => 10.5],
    ['scope' => 'global'],
    ['organizacion_id' => 1],
] as $bad) {
    try {
        TarifaContractSupport::validateSnapshot(TarifaContractSupport::pricingSnapshotFixture('bad', $bad), 325);
        throw new RuntimeException('invalid fixture accepted');
    } catch (RuntimeException) {
    }
}

$dir = sys_get_temp_dir() . '/testkit_tarifa_self_' . bin2hex(random_bytes(4));
$payload = TarifaContractSupport::economicOperationFixture($a);
$created = TarifaContractSupport::applyIdempotent($dir . '/state.json', 'k', $payload);
$replay = TarifaContractSupport::applyIdempotent($dir . '/state.json', 'k', $payload);
$conflict = TarifaContractSupport::applyIdempotent($dir . '/state.json', 'k', array_replace($payload, ['total_minor' => 999]));
if (($created['status'] ?? '') !== 'created' || ($replay['status'] ?? '') !== 'replay' || ($conflict['status'] ?? '') !== 'conflict') {
    throw new RuntimeException('idempotency contract failed');
}

$concurrency = TarifaContractSupport::runConcurrency($a, $dir . '/concurrency');
if (($concurrency['result']['effects'] ?? 0) !== 1 || ($concurrency['barrier_ready_count'] ?? 0) !== 2) {
    throw new RuntimeException('real concurrency evidence invalid');
}

echo "Tarifa contract support PASS\n";
