<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Gate;

final class MysqlQueryGateException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $jsonPath = '$',
        private readonly string $gateErrorCode = 'invalid_gate_contract',
        private readonly int $gateExitCode = 3
    ) {
        parent::__construct($message);
    }

    public function jsonPath(): string
    {
        return $this->jsonPath;
    }

    public function errorCode(): string
    {
        return $this->gateErrorCode;
    }

    public function exitCode(): int
    {
        return $this->gateExitCode;
    }
}
