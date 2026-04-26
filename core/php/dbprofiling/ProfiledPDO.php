<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling;

final class ProfiledPDO extends \PDO
{
    /**
     * @param array<mixed,mixed> $options
     */
    public function __construct(string $dsn, ?string $username = null, ?string $password = null, array $options = [])
    {
        parent::__construct($dsn, $username, $password, $options);
        $this->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [ProfiledPDOStatement::class, []]);
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
                QueryProfileCollector::inferCaller()
            );
        }
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        return parent::prepare($query, $options);
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
                QueryProfileCollector::inferCaller()
            );
        }
    }
}
