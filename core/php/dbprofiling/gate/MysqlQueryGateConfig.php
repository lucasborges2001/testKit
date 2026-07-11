<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Gate;

use Testkit\Core\Common\Paths;
use Testkit\Core\DbProfiling\InstrumentationContext;

final class MysqlQueryGateConfig
{
    public const SCHEMA_VERSION = 'mysql-query-gate-v1';
    public const REPORT_SCHEMA_VERSION = 'mysql-query-gate-report-v1';
    public const ALLOWLIST_SCHEMA_VERSION = 'mysql-query-gate-allowlist-v1';
    public const EVIDENCE_SCHEMA_VERSION = 'mysql-query-gate-evidence-v1';
    public const APPROVAL_SCHEMA_VERSION = 'mysql-query-baseline-approval-report-v1';

    public const MODE_OFF = 'off';
    public const MODE_REPORT = 'report';
    public const MODE_WARN = 'warn';
    public const MODE_FAIL = 'fail';

    public const EXIT_OK = 0;
    public const EXIT_OPERATIONAL = 2;
    public const EXIT_INVALID_CONTRACT = 3;
    public const EXIT_INCOMPATIBLE_INPUT = 4;
    public const EXIT_BLOCKED = 5;

    /** @return array<string,mixed> */
    public static function fromEnv(?string $explicitMode = null): array
    {
        $file = self::envString('TESTKIT_DB_PROFILE_GATE_FILE', '');
        $modeOverride = $explicitMode !== null && trim($explicitMode) !== ''
            ? strtolower(trim($explicitMode))
            : strtolower(self::envString('TESTKIT_DB_PROFILE_GATE_MODE', ''));
        if ($modeOverride !== '' && !in_array($modeOverride, self::modes(), true)) {
            throw new MysqlQueryGateException(
                'Unsupported gate mode.',
                '$.gate.mode',
                'unsupported_gate_mode'
            );
        }

        return [
            'enabled' => $file !== '',
            'file' => Paths::normalize($file),
            'mode_override' => $modeOverride,
            'allowlist_file' => Paths::normalize(self::envString('TESTKIT_DB_PROFILE_GATE_ALLOWLIST_FILE', '')),
            'evidence_file' => Paths::normalize(self::envString('TESTKIT_DB_PROFILE_GATE_EVIDENCE_FILE', '')),
            'max_findings' => self::envInt('TESTKIT_DB_PROFILE_GATE_MAX_FINDINGS', 5000, 1, 5000),
            'max_annotations' => self::envInt('TESTKIT_DB_PROFILE_GATE_MAX_ANNOTATIONS', 50, 0, 500),
            'github_annotations' => self::envBool('TESTKIT_DB_PROFILE_GATE_GITHUB_ANNOTATIONS', false),
            'output' => [
                'report_path' => Paths::normalize(self::envString(
                    'TESTKIT_DB_PROFILE_GATE_REPORT_PATH',
                    Paths::reportsRoot() . '/mysql_gate_latest.json'
                )),
                'history_path' => Paths::normalize(self::envString(
                    'TESTKIT_DB_PROFILE_GATE_HISTORY_PATH',
                    Paths::historyRoot() . '/mysql_gate'
                )),
                'junit_path' => Paths::normalize(self::envString(
                    'TESTKIT_DB_PROFILE_GATE_JUNIT_PATH',
                    Paths::reportsRoot() . '/mysql_gate.junit.xml'
                )),
                'sarif_path' => Paths::normalize(self::envString(
                    'TESTKIT_DB_PROFILE_GATE_SARIF_PATH',
                    Paths::reportsRoot() . '/mysql_gate.sarif'
                )),
                'summary_path' => Paths::normalize(self::envString(
                    'TESTKIT_DB_PROFILE_GATE_SUMMARY_PATH',
                    Paths::reportsRoot() . '/mysql_gate_summary.md'
                )),
                'approval_path' => Paths::normalize(self::envString(
                    'TESTKIT_DB_PROFILE_BASELINE_APPROVAL_REPORT_PATH',
                    Paths::reportsRoot() . '/mysql_baseline_approval_latest.json'
                )),
            ],
        ];
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    public static function publicConfig(array $config): array
    {
        return [
            'enabled' => (bool)($config['enabled'] ?? false),
            'file' => InstrumentationContext::normalizePath((string)($config['file'] ?? '')),
            'mode_override' => (string)($config['mode_override'] ?? ''),
            'allowlist_file' => InstrumentationContext::normalizePath((string)($config['allowlist_file'] ?? '')),
            'evidence_file' => InstrumentationContext::normalizePath((string)($config['evidence_file'] ?? '')),
            'max_findings' => (int)($config['max_findings'] ?? 5000),
            'max_annotations' => (int)($config['max_annotations'] ?? 50),
            'github_annotations' => (bool)($config['github_annotations'] ?? false),
            'output' => [
                'report_path' => InstrumentationContext::normalizePath((string)($config['output']['report_path'] ?? '')),
                'history_path' => InstrumentationContext::normalizePath((string)($config['output']['history_path'] ?? '')),
                'junit_path' => InstrumentationContext::normalizePath((string)($config['output']['junit_path'] ?? '')),
                'sarif_path' => InstrumentationContext::normalizePath((string)($config['output']['sarif_path'] ?? '')),
                'summary_path' => InstrumentationContext::normalizePath((string)($config['output']['summary_path'] ?? '')),
                'approval_path' => InstrumentationContext::normalizePath((string)($config['output']['approval_path'] ?? '')),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function disabledResult(): array
    {
        return [
            'enabled' => false,
            'schema_version' => self::REPORT_SCHEMA_VERSION,
            'gate_id' => '',
            'mode' => self::MODE_OFF,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'inputs' => [],
            'summary' => [
                'findings' => 0,
                'blocking' => 0,
                'warnings' => 0,
                'observed' => 0,
                'suppressed' => 0,
                'suppressed_blocking' => 0,
                'pending_stability' => 0,
                'insufficient_evidence' => 0,
            ],
            'decision' => [
                'status' => 'disabled',
                'exit_code' => self::EXIT_OK,
                'reason' => 'gate_disabled',
            ],
            'findings' => [],
            'allowlist' => ['enabled' => false, 'entries' => 0, 'unused' => [], 'expired' => []],
            'stability' => ['enabled' => false],
            'outputs' => [],
            'limitations' => [],
        ];
    }

    /** @return array<int,string> */
    public static function modes(): array
    {
        return [self::MODE_OFF, self::MODE_REPORT, self::MODE_WARN, self::MODE_FAIL];
    }

    private static function envString(string $key, string $default): string
    {
        $value = getenv($key);
        return !is_string($value) || trim($value) === '' ? $default : trim($value);
    }

    private static function envBool(string $key, bool $default): bool
    {
        $value = getenv($key);
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }
        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return $default;
    }

    private static function envInt(string $key, int $default, int $min, int $max): int
    {
        $value = getenv($key);
        if (!is_string($value) || preg_match('/^\d+$/', trim($value)) !== 1) {
            return $default;
        }
        return max($min, min($max, (int)$value));
    }
}
