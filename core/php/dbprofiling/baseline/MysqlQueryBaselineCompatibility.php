<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Baseline;

final class MysqlQueryBaselineCompatibility
{
    /**
     * @param array<string,mixed> $baselineCompatibility
     * @param array<string,mixed> $currentContext
     * @param array<string,mixed> $currentProfile
     * @return array<string,mixed>
     */
    public static function evaluate(
        array $baselineCompatibility,
        array $currentContext,
        array $currentProfile
    ): array {
        $warnings = [];
        $reasons = [];
        $status = 'compatible';
        $scope = 'full';
        $timingComparable = true;

        if ((int)($currentProfile['report_version'] ?? 0) === 1) {
            return [
                'status' => 'legacy_current',
                'comparison_scope' => 'structural_only',
                'timing_comparable' => false,
                'warnings' => ['Current report is legacy v1.'],
                'reasons' => ['legacy_current'],
                'checks' => [],
            ];
        }

        $checks = [];
        $engine = strtolower((string)($currentContext['engine'] ?? $currentProfile['engine'] ?? ''));
        $baselineEngine = strtolower((string)($baselineCompatibility['engine'] ?? ''));
        $checks['engine'] = self::check($baselineEngine, $engine);
        if ($baselineEngine === '' || $engine === '') {
            $status = 'insufficient_metadata';
            $scope = 'structural_only';
            $timingComparable = false;
            $reasons[] = 'engine_missing';
        } elseif ($baselineEngine !== $engine) {
            $status = 'incompatible';
            $scope = 'none';
            $timingComparable = false;
            $reasons[] = 'engine_mismatch';
        }

        $suiteScope = (string)($baselineCompatibility['suite_scope'] ?? 'exact');
        $baselineSuite = (string)($baselineCompatibility['suite_id'] ?? '');
        $currentSuite = (string)($currentContext['suite_id'] ?? $currentProfile['suite_id'] ?? '');
        $checks['suite_id'] = self::check($baselineSuite, $currentSuite);
        if ($status !== 'incompatible' && $suiteScope !== 'global') {
            if ($baselineSuite === '' || $currentSuite === '') {
                $status = 'insufficient_metadata';
                $scope = 'structural_only';
                $timingComparable = false;
                $reasons[] = 'suite_missing';
            } elseif ($baselineSuite !== $currentSuite) {
                $status = 'incompatible';
                $scope = 'none';
                $timingComparable = false;
                $reasons[] = 'suite_mismatch';
            }
        }

        foreach (['dataset_id', 'dataset_version'] as $field) {
            $baselineValue = (string)($baselineCompatibility[$field] ?? '');
            $currentValue = (string)($currentContext[$field] ?? '');
            $checks[$field] = self::check($baselineValue, $currentValue);
            if ($status === 'incompatible') {
                continue;
            }
            if ($baselineValue === '' || $currentValue === '') {
                $status = 'insufficient_metadata';
                $scope = 'structural_only';
                $timingComparable = false;
                $reasons[] = $field . '_missing';
            } elseif ($baselineValue !== $currentValue) {
                $status = 'incompatible';
                $scope = 'none';
                $timingComparable = false;
                $reasons[] = $field . '_mismatch';
            }
        }

        $baselineHash = (string)($baselineCompatibility['dataset_hash'] ?? '');
        $currentHash = (string)($currentContext['dataset_hash'] ?? '');
        $hashMode = (string)($baselineCompatibility['dataset_hash_mode'] ?? 'exact');
        $checks['dataset_hash'] = self::check($baselineHash, $currentHash);
        if ($status !== 'incompatible' && $hashMode !== 'ignore') {
            if ($baselineHash === '' || $currentHash === '') {
                $status = 'insufficient_metadata';
                $scope = 'structural_only';
                $timingComparable = false;
                $reasons[] = 'dataset_hash_missing';
            } elseif ($baselineHash !== $currentHash) {
                if ($hashMode === 'exact') {
                    $status = 'incompatible';
                    $scope = 'none';
                    $timingComparable = false;
                    $reasons[] = 'dataset_hash_mismatch';
                } else {
                    if ($status === 'compatible') {
                        $status = 'compatible_with_warnings';
                    }
                    $scope = 'structural_only';
                    $timingComparable = false;
                    $warnings[] = 'Dataset hash differs; only structural comparison is reliable.';
                    $reasons[] = 'dataset_hash_warning';
                }
            }
        }

        $versionMode = (string)($baselineCompatibility['engine_version_mode'] ?? 'major_minor');
        $baselineVersion = (string)($baselineCompatibility['engine_version'] ?? '');
        $currentVersion = (string)($currentContext['engine_version'] ?? '');
        $checks['engine_version'] = self::check($baselineVersion, $currentVersion);
        if ($status !== 'incompatible' && $versionMode !== 'ignore') {
            if ($baselineVersion === '' || $currentVersion === '') {
                $status = 'insufficient_metadata';
                $scope = 'structural_only';
                $timingComparable = false;
                $reasons[] = 'engine_version_missing';
            } elseif (!self::versionMatches($baselineVersion, $currentVersion, $versionMode)) {
                $status = 'incompatible';
                $scope = 'structural_only';
                $timingComparable = false;
                $warnings[] = 'Engine version is incompatible for latency comparison.';
                $reasons[] = 'engine_version_mismatch';
            }
        }

        $baselineEnvironment = (string)($baselineCompatibility['environment_id'] ?? '');
        $currentEnvironment = (string)($currentContext['environment_id'] ?? '');
        $checks['environment_id'] = self::check($baselineEnvironment, $currentEnvironment);
        if ($status !== 'incompatible') {
            if ($baselineEnvironment === '' || $currentEnvironment === '') {
                $status = 'insufficient_metadata';
                $scope = 'structural_only';
                $timingComparable = false;
                $reasons[] = 'environment_missing';
            } elseif ($baselineEnvironment !== $currentEnvironment) {
                if ($status === 'compatible') {
                    $status = 'compatible_with_warnings';
                }
                $scope = 'structural_only';
                $timingComparable = false;
                $warnings[] = 'Environment differs; latency metrics are not comparable.';
                $reasons[] = 'environment_mismatch';
            }
        }

        return [
            'status' => $status,
            'comparison_scope' => $scope,
            'timing_comparable' => $timingComparable,
            'warnings' => array_values(array_unique($warnings)),
            'reasons' => array_values(array_unique($reasons)),
            'checks' => $checks,
        ];
    }

    /** @return array{baseline:string,current:string,match:?bool} */
    private static function check(string $baseline, string $current): array
    {
        return [
            'baseline' => $baseline,
            'current' => $current,
            'match' => $baseline === '' || $current === '' ? null : $baseline === $current,
        ];
    }

    private static function versionMatches(string $baseline, string $current, string $mode): bool
    {
        if ($mode === 'exact') {
            return $baseline === $current;
        }
        $left = self::versionParts($baseline);
        $right = self::versionParts($current);
        if ($left === [] || $right === []) {
            return false;
        }
        if ($mode === 'major') {
            return ($left[0] ?? null) === ($right[0] ?? null);
        }
        return ($left[0] ?? null) === ($right[0] ?? null)
            && ($left[1] ?? null) === ($right[1] ?? null);
    }

    /** @return array<int,int> */
    private static function versionParts(string $version): array
    {
        if (preg_match('/(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $version, $match) !== 1) {
            return [];
        }
        return [
            (int)($match[1] ?? 0),
            (int)($match[2] ?? 0),
            (int)($match[3] ?? 0),
        ];
    }
}
