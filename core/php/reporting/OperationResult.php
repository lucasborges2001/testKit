<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

require_once __DIR__ . '/../execution/ExitCode.php';

use InvalidArgumentException;
use Testkit\Core\Execution\ExitCode;

final class OperationResult
{
    public const SCHEMA_NAME = 'testkit.operation_result';
    public const SCHEMA_VERSION = 2;

    /**
     * Attach the v2 machine contract at report root.
     *
     * Legacy report fields may still coexist during I8 migration, but they are not
     * consulted to decide the process exit meaning.
     *
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    public static function attach(array $report, string $operation): array
    {
        $operation = trim($operation);
        if ($operation === '') {
            throw new InvalidArgumentException('operation_result operation must not be empty.');
        }

        $code = (int)($report['exit_code'] ?? ExitCode::OPERATIONAL_ERROR);
        if (!ExitCode::isKnown($code)) {
            throw new InvalidArgumentException('operation_result received an unknown exit code: ' . $code);
        }

        $evidenceValid = array_key_exists('evidence_valid', $report)
            ? (bool)$report['evidence_valid']
            : $code !== ExitCode::EVIDENCE_INCOMPLETE;
        $invalidReason = $report['evidence_invalid_reason'] ?? null;
        if (!is_string($invalidReason) || trim($invalidReason) === '') {
            $invalidReason = null;
        } else {
            $invalidReason = trim($invalidReason);
        }

        $report['schema'] = [
            'name' => self::SCHEMA_NAME,
            'version' => self::SCHEMA_VERSION,
        ];
        $report['operation'] = $operation;
        $report['exit'] = [
            'code' => $code,
            'name' => ExitCode::name($code),
        ];
        $report['status'] = self::statusFor($code, $report);
        $report['evidence_valid'] = $evidenceValid;
        $report['evidence_invalid_reason'] = $invalidReason;

        self::validate($report);
        return $report;
    }

    /** @param array<string,mixed> $payload */
    public static function validate(array $payload): void
    {
        $schema = $payload['schema'] ?? null;
        if (!is_array($schema)
            || ($schema['name'] ?? null) !== self::SCHEMA_NAME
            || ($schema['version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('Unsupported operation_result schema.');
        }

        $operation = $payload['operation'] ?? null;
        if (!is_string($operation) || trim($operation) === '') {
            throw new InvalidArgumentException('operation_result operation must be a non-empty string.');
        }

        $exit = $payload['exit'] ?? null;
        if (!is_array($exit) || !is_int($exit['code'] ?? null) || !is_string($exit['name'] ?? null)) {
            throw new InvalidArgumentException('operation_result exit must contain integer code and string name.');
        }
        $code = (int)$exit['code'];
        if (!ExitCode::isKnown($code) || $exit['name'] !== ExitCode::name($code)) {
            throw new InvalidArgumentException('operation_result exit code/name mismatch.');
        }

        $status = $payload['status'] ?? null;
        if (!is_string($status) || !in_array($status, self::allowedStatusesFor($code), true)) {
            throw new InvalidArgumentException('operation_result status is inconsistent with exit code.');
        }

        if (!is_bool($payload['evidence_valid'] ?? null)) {
            throw new InvalidArgumentException('operation_result evidence_valid must be boolean.');
        }

        $invalidReason = $payload['evidence_invalid_reason'] ?? null;
        if ($invalidReason !== null && (!is_string($invalidReason) || trim($invalidReason) === '')) {
            throw new InvalidArgumentException('operation_result evidence_invalid_reason must be null or a non-empty string.');
        }
    }

    /** @param array<string,mixed> $report */
    private static function statusFor(int $code, array $report): string
    {
        if ($code !== ExitCode::OK) {
            return self::allowedStatusesFor($code)[0];
        }

        if ((bool)($report['list_only'] ?? false)) {
            return 'listed';
        }

        $pass = (int)($report['pass'] ?? 0);
        $skip = (int)($report['skip'] ?? 0);
        if ($pass === 0 && $skip > 0) {
            return 'skipped';
        }
        if ($pass > 0 && $skip > 0) {
            return 'partial';
        }

        return 'passed';
    }

    /** @return array<int,string> */
    private static function allowedStatusesFor(int $code): array
    {
        return match ($code) {
            ExitCode::OK => ['passed', 'listed', 'skipped', 'partial'],
            ExitCode::TEST_FAILURE => ['failed'],
            ExitCode::INVALID_REQUEST => ['invalid_request'],
            ExitCode::OPERATIONAL_ERROR => ['operational_error'],
            ExitCode::EVIDENCE_INCOMPLETE => ['evidence_incomplete'],
            ExitCode::POLICY_BLOCKED => ['policy_blocked'],
            ExitCode::NO_TESTS => ['no_tests'],
            ExitCode::CONTENTION => ['contention'],
            ExitCode::TIMEOUT => ['timeout'],
            default => throw new InvalidArgumentException('Unknown TestKit process exit code: ' . $code),
        };
    }
}
