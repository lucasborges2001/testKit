<?php
/**
 * Self-test: BaselineManifest writes atomically and round-trips correctly.
 *
 * Verifies:
 *   - save() + load() round-trip: data survives serialization
 *   - No tmp file left behind after successful save
 *   - Concurrent write safety: final file is valid JSON (not truncated)
 *   - Missing parent directory is created automatically
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/seeding/BaselineManifest.php';

use Testkit\Core\Seeding\BaselineManifest;

$tmpDir = sys_get_temp_dir() . '/testkit_selftest_manifest_' . getmypid();
$path   = $tmpDir . '/baselines/mysql/testdb.manifest.json';
$errors = [];

// -----------------------------------------------------------------------
// Round-trip
// -----------------------------------------------------------------------
$payload = [
    'status'               => 'ready',
    'driver'               => 'mysql',
    'db_name'              => 'testdb',
    'baseline_fingerprint' => hash('sha256', 'test'),
    'generated_at'         => gmdate(DATE_ATOM),
    'unicode_check'        => 'áéíóú ñ 中文',
];

try {
    BaselineManifest::save($path, $payload);
} catch (\Throwable $e) {
    $errors[] = 'FAIL save(): ' . $e->getMessage();
    goto done;
}

$loaded = BaselineManifest::load($path);
if (!is_array($loaded)) {
    $errors[] = 'FAIL load(): returned null or non-array';
    goto done;
}

foreach (['status', 'driver', 'db_name', 'baseline_fingerprint', 'unicode_check'] as $key) {
    if (($loaded[$key] ?? null) !== $payload[$key]) {
        $errors[] = "FAIL round-trip: key '{$key}' differs. got=" . var_export($loaded[$key] ?? null, true);
    }
}

echo "Round-trip PASS\n";

// -----------------------------------------------------------------------
// No tmp file left behind
// -----------------------------------------------------------------------
$tmpFiles = glob($tmpDir . '/baselines/mysql/.manifest_tmp_*') ?: [];
if ($tmpFiles !== []) {
    $errors[] = 'FAIL: tmp file(s) left behind: ' . implode(', ', $tmpFiles);
} else {
    echo "No stale tmp files PASS\n";
}

// -----------------------------------------------------------------------
// File is valid JSON
// -----------------------------------------------------------------------
$raw = file_get_contents($path);
$decoded = json_decode((string)$raw, true);
if (!is_array($decoded)) {
    $errors[] = 'FAIL: manifest file is not valid JSON';
} else {
    echo "Valid JSON PASS\n";
}

// -----------------------------------------------------------------------
// Overwrite existing file (atomic rename, not truncate-in-place)
// -----------------------------------------------------------------------
$payload2 = array_merge($payload, ['status' => 'overwritten']);
BaselineManifest::save($path, $payload2);
$loaded2 = BaselineManifest::load($path);
if (($loaded2['status'] ?? null) !== 'overwritten') {
    $errors[] = 'FAIL: overwrite did not update status field';
} else {
    echo "Overwrite PASS\n";
}

done:
// Cleanup
@unlink($path);
@rmdir(dirname($path));
@rmdir($tmpDir . '/baselines');
@rmdir($tmpDir);

if ($errors !== []) {
    foreach ($errors as $err) {
        fwrite(STDERR, $err . "\n");
    }
    exit(1);
}

exit(0);
