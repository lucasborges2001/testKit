<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Baseline;

final class MysqlQueryBaselineException extends \RuntimeException
{
    public function __construct(
        string $message,
        private string $jsonPath = '$',
        private string $errorCode = 'baseline_contract_invalid'
    ) {
        parent::__construct($message);
    }

    public function jsonPath(): string
    {
        return $this->jsonPath;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
