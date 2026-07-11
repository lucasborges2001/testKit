<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling;

final class ProfiledPDO extends \PDO
{
    private string $profileConnectionId = '';

    /**
     * @param array<mixed,mixed> $options
     * @param array<string,mixed> $profileContext
     */
    public function __construct(
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        array $options = [],
        array $profileContext = []
    ) {
        parent::__construct($dsn, $username, $password, $options);
        $engine = strtolower((string)strtok($dsn, ':'));
        $this->profileConnectionId = ConnectionRegistry::register(
            $this,
            'profiled_pdo',
            $engine !== '' ? $engine : 'mysql',
            [
                'query' => true,
                'exec' => true,
                'prepare_execute' => true,
                'transactions' => true,
            ],
            true
        );
        $this->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [
            ProfiledPDOStatement::class,
            [$this->profileConnectionId, MysqlCaptureMethod::PROFILED_PDO_STATEMENT_EXECUTE],
        ]);

        if ($profileContext !== []) {
            QueryProfileCollector::addFinding(
                'connection_context_registered',
                'info',
                'Se registró contexto opcional para una conexión PDO instrumentada.',
                $profileContext
            );
        }
    }

    public function exec(string $statement): int|false
    {
        if (!QueryProfileCollector::isEnabled()) {
            return parent::exec($statement);
        }

        $started = microtime(true);
        try {
            return parent::exec($statement);
        } finally {
            QueryProfileCollector::record(
                $statement,
                (microtime(true) - $started) * 1000,
                QueryProfileCollector::inferSource(),
                QueryProfileCollector::inferCaller(),
                [
                    'capture_method' => MysqlCaptureMethod::PROFILED_PDO_EXEC,
                    'connection_id' => $this->profileConnectionId,
                ]
            );
        }
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $statement = parent::prepare($query, $options);
        if ($statement instanceof \PDOStatement) {
            ConnectionRegistry::prepared($this->profileConnectionId);
        }
        return $statement;
    }

    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs)
    {
        if (!QueryProfileCollector::isEnabled()) {
            return $fetchMode === null
                ? parent::query($query)
                : parent::query($query, $fetchMode, ...$fetchModeArgs);
        }

        $started = microtime(true);
        try {
            return $fetchMode === null
                ? parent::query($query)
                : parent::query($query, $fetchMode, ...$fetchModeArgs);
        } finally {
            QueryProfileCollector::record(
                $query,
                (microtime(true) - $started) * 1000,
                QueryProfileCollector::inferSource(),
                QueryProfileCollector::inferCaller(),
                [
                    'capture_method' => MysqlCaptureMethod::PROFILED_PDO_QUERY,
                    'connection_id' => $this->profileConnectionId,
                ]
            );
        }
    }

    public function beginTransaction(): bool
    {
        $result = parent::beginTransaction();
        if ($result) {
            ConnectionRegistry::transaction($this->profileConnectionId);
        }
        return $result;
    }

    public function commit(): bool
    {
        $result = parent::commit();
        if ($result) {
            ConnectionRegistry::transaction($this->profileConnectionId);
        }
        return $result;
    }

    public function rollBack(): bool
    {
        $result = parent::rollBack();
        if ($result) {
            ConnectionRegistry::transaction($this->profileConnectionId);
        }
        return $result;
    }
}
