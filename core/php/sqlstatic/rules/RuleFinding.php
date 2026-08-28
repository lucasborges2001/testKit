<?php
declare(strict_types=1);

namespace Testkit\Core\SqlStatic\Rules;

final class RuleFinding
{
    /** @return array<string,mixed> */
    public static function make(
        string $ruleId,
        string $severity,
        string $confidence,
        string $summary,
        string $recommendation,
        array $evidence = []
    ): array {
        return compact('ruleId', 'severity', 'confidence', 'summary', 'recommendation', 'evidence');
    }
}
