<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling;

final class ConnectionRegistry
{
    /** @var array<string,array<string,mixed>> */
    private static array $connections = [];

    public static function resetForTests(): void
    {
        self::$connections = [];
    }

    /**
     * @param array<string,bool> $capabilities
     * @return string
     */
    public static function register(
        object $connection,
        string $adapter,
        string $engine = 'mysql',
        array $capabilities = [],
        bool $instrumented = true
    ): string {
        if (!QueryProfileCollector::isEnabled()) {
            return '';
        }
        $config = MysqlProfileConfig::fromEnv();
        if (!(bool)($config['capture']['connections_enabled'] ?? true)) {
            return '';
        }

        $id = InstrumentationContext::connectionId($connection, $adapter);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        if (!isset(self::$connections[$id])) {
            self::$connections[$id] = [
                'connection_id' => $id,
                'adapter' => InstrumentationContext::sanitizeIdentifier($adapter, 80),
                'engine' => InstrumentationContext::sanitizeIdentifier($engine, 40),
                'capture_capabilities' => [
                    'query' => (bool)($capabilities['query'] ?? false),
                    'exec' => (bool)($capabilities['exec'] ?? false),
                    'prepare_execute' => (bool)($capabilities['prepare_execute'] ?? false),
                    'transactions' => (bool)($capabilities['transactions'] ?? false),
                ],
                'created_at' => $now,
                'first_query_at' => null,
                'last_query_at' => null,
                'query_count' => 0,
                'prepared_statement_count' => 0,
                'transaction_count' => 0,
                'instrumented' => $instrumented,
            ];
        }
        return $id;
    }

    public static function query(string $connectionId): void
    {
        if ($connectionId === '' || !isset(self::$connections[$connectionId])) {
            return;
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        self::$connections[$connectionId]['query_count']++;
        self::$connections[$connectionId]['first_query_at'] ??= $now;
        self::$connections[$connectionId]['last_query_at'] = $now;
    }

    public static function prepared(string $connectionId): void
    {
        if ($connectionId !== '' && isset(self::$connections[$connectionId])) {
            self::$connections[$connectionId]['prepared_statement_count']++;
        }
    }

    public static function transaction(string $connectionId): void
    {
        if ($connectionId !== '' && isset(self::$connections[$connectionId])) {
            self::$connections[$connectionId]['transaction_count']++;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function snapshot(): array
    {
        $rows = array_values(self::$connections);
        usort($rows, static fn(array $a, array $b): int => strcmp((string)$a['connection_id'], (string)$b['connection_id']));
        return $rows;
    }
}
