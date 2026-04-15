<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use Testkit\Core\Common\Trace;
use Testkit\Core\Store\StoreMaintenance;

require_once __DIR__ . '/../store/bootstrap.php';
require_once __DIR__ . '/BaselineManifest.php';
require_once __DIR__ . '/BaselineModeResolver.php';

final class BaselineReuseDecider
{
    /**
     * @param array<string,mixed> $manifestPlan
     */
    public static function canReuse(string $driver, string $databaseName, string $manifestPath, array $manifestPlan): bool
    {
        if (!BaselineModeResolver::reuseEnabled()) {
            Trace::log('baseline.reuse.disabled', [
                'driver' => $driver,
                'db' => $databaseName,
            ]);
            return false;
        }

        if (BaselineModeResolver::invalidateRequested()) {
            Trace::log('baseline.reuse.disabled', [
                'driver' => $driver,
                'db' => $databaseName,
                'reason' => 'TEST_BASELINE_INVALIDATE=1',
            ]);
            return false;
        }

        $manifest = BaselineManifest::load($manifestPath);
        if ($manifest === null) {
            Trace::log('baseline.reuse.miss', [
                'driver' => $driver,
                'db' => $databaseName,
                'reason' => 'manifest_missing',
                'manifest_path' => self::realPathOrOriginal($manifestPath),
            ]);
            return false;
        }

        $actualFingerprint = (string)($manifestPlan['fingerprint'] ?? '');
        $manifestFingerprint = trim((string)($manifest['baseline_fingerprint'] ?? ''));
        if ($manifestFingerprint === '' || !hash_equals($manifestFingerprint, $actualFingerprint)) {
            Trace::log('baseline.reuse.miss', [
                'driver' => $driver,
                'db' => $databaseName,
                'reason' => 'fingerprint_mismatch',
                'manifest_fingerprint' => $manifestFingerprint,
                'actual_fingerprint' => $actualFingerprint,
            ]);
            return false;
        }

        if (trim((string)($manifest['status'] ?? '')) !== 'ready') {
            Trace::log('baseline.reuse.miss', [
                'driver' => $driver,
                'db' => $databaseName,
                'reason' => 'status_not_ready',
                'status' => (string)($manifest['status'] ?? ''),
            ]);
            return false;
        }

        if (!StoreMaintenance::databaseExists($driver, $databaseName)) {
            Trace::log('baseline.reuse.miss', [
                'driver' => $driver,
                'db' => $databaseName,
                'reason' => 'database_missing',
            ]);
            return false;
        }

        Trace::log('baseline.reuse.hit', [
            'driver' => $driver,
            'db' => $databaseName,
            'manifest_path' => self::realPathOrOriginal($manifestPath),
            'fingerprint' => $actualFingerprint,
        ]);

        return true;
    }

    private static function realPathOrOriginal(string $path): string
    {
        $real = realpath($path);
        return $real !== false ? str_replace('\\', '/', $real) : str_replace('\\', '/', $path);
    }
}
