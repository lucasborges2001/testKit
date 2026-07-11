<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling;

final class ProfiledPDOStatement extends \PDOStatement
{
    private string $profileConnectionId = '';
    private string $profileCaptureMethod = MysqlCaptureMethod::PROFILED_PDO_STATEMENT_EXECUTE;

    protected function __construct(
        string $connectionId = '',
        string $captureMethod = MysqlCaptureMethod::PROFILED_PDO_STATEMENT_EXECUTE
    ) {
        $this->profileConnectionId = InstrumentationContext::sanitizeIdentifier($connectionId, 80);
        $this->profileCaptureMethod = MysqlCaptureMethod::normalize($captureMethod);
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
                QueryProfileCollector::inferCaller(),
                [
                    'capture_method' => $this->profileCaptureMethod,
                    'connection_id' => $this->profileConnectionId,
                ]
            );
        }
    }
}
