<?php
declare(strict_types=1);

namespace Testkit\Core\SqlStatic\Rules;

final class SelectStarRule implements SqlRule
{
    public static function analyze(string $sql): array
    {
        if (preg_match('/\bSELECT\s+(?:DISTINCT\s+)?(?:[`"A-Za-z_][`"A-Za-z0-9_$]*\.)?\*(?=\s|,|\bFROM\b)/i', $sql) !== 1) {
            return [];
        }
        return [RuleFinding::make(
            'select_star', 'warn', 'high',
            'SELECT * reads every projected column and may prevent covering-index plans.',
            'Project only the columns required by the caller.'
        )];
    }
}
