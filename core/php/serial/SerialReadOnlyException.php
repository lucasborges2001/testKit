<?php
declare(strict_types=1);
namespace Testkit\Core\Serial;
final class SerialReadOnlyException extends \RuntimeException
{
    public function __construct(private readonly string $stage, string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
    public function stage(): string { return $this->stage; }
}
