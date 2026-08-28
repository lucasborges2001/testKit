<?php
declare(strict_types=1);

namespace Testkit\Core\SqlStatic\Rules;

interface SqlRule
{
    /** @return array<int,array<string,mixed>> */
    public static function analyze(string $sql): array;
}
