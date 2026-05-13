<?php
declare(strict_types=1);

namespace Testkit\Core\References;

use RuntimeException;

final class ReferenceConfigException extends RuntimeException
{
    public function __construct(
        public readonly string $causeCode,
        string $message
    ) {
        parent::__construct($message);
    }
}
