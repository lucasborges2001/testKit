<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Policy;

final class MysqlQueryPolicyException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $jsonPath = '$',
        private readonly string $errorCode = 'invalid_policy'
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
