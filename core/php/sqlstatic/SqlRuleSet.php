<?php
declare(strict_types=1);

namespace Testkit\Core\SqlStatic;

/** @deprecated Compatibility facade. New rules belong in SqlRuleRegistry. */
final class SqlRuleSet
{
    /** @return array<int,array<string,mixed>> */
    public static function analyze(string $sql): array
    {
        return SqlRuleRegistry::analyze($sql);
    }
}
