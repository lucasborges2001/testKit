<?php
declare(strict_types=1);

namespace Testkit\Core\Execution;

use InvalidArgumentException;

final class ExitCode
{
    public const OK = 0;
    public const TEST_FAILURE = 1;
    public const INVALID_REQUEST = 2;
    public const OPERATIONAL_ERROR = 3;
    public const EVIDENCE_INCOMPLETE = 4;
    public const POLICY_BLOCKED = 5;
    public const NO_TESTS = 6;
    public const CONTENTION = 7;
    public const TIMEOUT = 8;

    /** @var array<int,string> */
    private const NAMES = [
        self::OK => 'OK',
        self::TEST_FAILURE => 'TEST_FAILURE',
        self::INVALID_REQUEST => 'INVALID_REQUEST',
        self::OPERATIONAL_ERROR => 'OPERATIONAL_ERROR',
        self::EVIDENCE_INCOMPLETE => 'EVIDENCE_INCOMPLETE',
        self::POLICY_BLOCKED => 'POLICY_BLOCKED',
        self::NO_TESTS => 'NO_TESTS',
        self::CONTENTION => 'CONTENTION',
        self::TIMEOUT => 'TIMEOUT',
    ];

    public static function isKnown(int $code): bool
    {
        return array_key_exists($code, self::NAMES);
    }

    public static function name(int $code): string
    {
        if (!self::isKnown($code)) {
            throw new InvalidArgumentException('Unknown TestKit process exit code: ' . $code);
        }

        return self::NAMES[$code];
    }

    public static function normalize(int $code): int
    {
        return self::isKnown($code) ? $code : self::OPERATIONAL_ERROR;
    }

    /** @return array<int,string> */
    public static function table(): array
    {
        return self::NAMES;
    }
}
