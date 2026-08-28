<?php
declare(strict_types=1);

namespace Testkit\Core\SqlStatic\Rules;

final class QueryInsideLoopRule
{
    /** @param array{sql:string,line:int} $query @param array<int,array{kind:string,start_line:int,end_line:int}> $ranges */
    public static function analyze(array $query, array $ranges): array
    {
        $line = (int)$query['line'];
        foreach ($ranges as $range) {
            if ($line < $range['start_line'] || $line > $range['end_line']) {
                continue;
            }
            return [RuleFinding::make(
                'query_inside_loop', 'watch', 'medium',
                'A SQL query is declared inside a loop scope and may execute once per iteration.',
                'Verify runtime call counts; batch/eager-load data when this produces an N+1 access pattern.',
                ['loop' => $range['kind'], 'loop_start_line' => $range['start_line']]
            )];
        }
        return [];
    }
}
