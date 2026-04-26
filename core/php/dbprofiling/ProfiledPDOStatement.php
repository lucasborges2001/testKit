<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling;

final class ProfiledPDOStatement extends \PDOStatement
{
    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        if (!QueryProfileCollector::isEnabled()) {
            return $params === null ? parent::execute() : parent::execute($params);
        }

        $started = microtime(true);
        try {
            return $params === null ? parent::execute() : parent::execute($params);
        } finally {
            QueryProfileCollector::record(
                (string)$this->queryString,
                (microtime(true) - $started) * 1000,
                QueryProfileCollector::inferSource(),
                QueryProfileCollector::inferCaller()
            );
        }
    }
}
