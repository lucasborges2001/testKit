<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting\Console;

use Testkit\Core\Reporting\UI;

final class IssueDiagnosticsRenderer
{
    public static function printDiagnostics(array $diagnostics, bool $compactPassed = false): void
    {
        $phaseCounts = is_array($diagnostics['phase_failure_counts'] ?? null) ? $diagnostics['phase_failure_counts'] : [];
        $causeCounts = is_array($diagnostics['cause_counts'] ?? null) ? $diagnostics['cause_counts'] : [];
        $resource = trim((string)($diagnostics['resource'] ?? ''));
        $lockKey = trim((string)($diagnostics['lock_key'] ?? ''));
        $lockOwnerRunId = trim((string)($diagnostics['lock_owner_run_id'] ?? ''));
        $lockOwnerMetaRunId = trim((string)($diagnostics['lock_owner_meta_run_id'] ?? ''));
        $lockOwnerHost = trim((string)($diagnostics['lock_owner_hostname'] ?? ''));

        if ($compactPassed && !self::hasActionableDiagnostics($diagnostics)) {
            return;
        }

        if ($phaseCounts === [] && $causeCounts === [] && $resource === '' && $lockKey === '') {
            return;
        }

        UI::section('Diagnostics');

        if ($phaseCounts !== []) {
            $parts = [];
            foreach ($phaseCounts as $phase => $count) {
                $parts[] = $phase . '=' . (int)$count;
            }
            echo '  phases: ' . implode(', ', $parts) . "\n";
        }

        if ($causeCounts !== []) {
            $parts = [];
            foreach ($causeCounts as $cause => $count) {
                $parts[] = $cause . '=' . (int)$count;
            }
            echo '  causes: ' . implode(', ', $parts) . "\n";
        }

        if ($resource !== '') {
            echo '  resource: ' . $resource . "\n";
        }

        if ($lockKey !== '') {
            echo '  lock: ' . $lockKey;
            if ($lockOwnerRunId !== '') {
                echo ' owner_run=' . $lockOwnerRunId;
            }
            if ($lockOwnerMetaRunId !== '') {
                echo ' owner_meta=' . $lockOwnerMetaRunId;
            }
            if ($lockOwnerHost !== '') {
                echo ' owner_host=' . $lockOwnerHost;
            }
            echo "\n";
        }
    }

    public static function hasActionableDiagnostics(array $diagnostics): bool
    {
        $phaseCounts = is_array($diagnostics['phase_failure_counts'] ?? null) ? $diagnostics['phase_failure_counts'] : [];
        $causeCounts = is_array($diagnostics['cause_counts'] ?? null) ? $diagnostics['cause_counts'] : [];

        if ($phaseCounts !== [] || $causeCounts !== []) {
            return true;
        }

        foreach (['lock_owner_run_id', 'lock_owner_meta_run_id', 'lock_owner_hostname'] as $field) {
            if (trim((string)($diagnostics[$field] ?? '')) !== '') {
                return true;
            }
        }

        $cause = trim((string)($diagnostics['cause_code'] ?? ''));
        return in_array($cause, ['shared_store_locked', 'store_resource_locked'], true);
    }

    private function __construct()
    {
    }
}
