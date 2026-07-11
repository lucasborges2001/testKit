<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling;

final class MysqlCaptureMethod
{
    public const PROFILED_PDO_QUERY = 'profiled_pdo.query';
    public const PROFILED_PDO_EXEC = 'profiled_pdo.exec';
    public const PROFILED_PDO_STATEMENT_EXECUTE = 'profiled_pdo.statement_execute';
    public const EXISTING_PDO_STATEMENT_EXECUTE = 'existing_pdo.statement_execute';
    public const MYSQLI_QUERY_MANUAL = 'mysqli.query.manual';
    public const MYSQLI_STATEMENT_EXECUTE_MANUAL = 'mysqli.statement_execute.manual';
    public const MANUAL_RECORD = 'manual.record';
    public const UNKNOWN = 'unknown';

    /** @return array<int,string> */
    public static function all(): array
    {
        return [
            self::PROFILED_PDO_QUERY,
            self::PROFILED_PDO_EXEC,
            self::PROFILED_PDO_STATEMENT_EXECUTE,
            self::EXISTING_PDO_STATEMENT_EXECUTE,
            self::MYSQLI_QUERY_MANUAL,
            self::MYSQLI_STATEMENT_EXECUTE_MANUAL,
            self::MANUAL_RECORD,
            self::UNKNOWN,
        ];
    }

    public static function normalize(string $method): string
    {
        $method = strtolower(trim($method));
        return in_array($method, self::all(), true) ? $method : self::UNKNOWN;
    }
}
