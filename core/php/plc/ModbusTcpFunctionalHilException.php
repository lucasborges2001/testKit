<?php
declare(strict_types=1);

namespace Testkit\Core\Plc;

use RuntimeException;

final class ModbusTcpFunctionalHilException extends RuntimeException
{
    public function __construct(
        private readonly string $stage,
        string $message,
        int $code = 0
    ) {
        parent::__construct($message, $code);
    }

    public function stage(): string
    {
        return $this->stage;
    }
}
