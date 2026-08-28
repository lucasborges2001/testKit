<?php
declare(strict_types=1);

namespace Testkit\Core\SqlStatic;

use Testkit\Core\SqlStatic\Rules\LeadingWildcardLikeRule;
use Testkit\Core\SqlStatic\Rules\NonSargablePredicateRule;
use Testkit\Core\SqlStatic\Rules\OffsetPaginationRule;
use Testkit\Core\SqlStatic\Rules\OrderByRandomRule;
use Testkit\Core\SqlStatic\Rules\SelectStarRule;
use Testkit\Core\SqlStatic\Rules\UnboundedSelectRule;

final class SqlRuleRegistry
{
    private const RULES = [
        SelectStarRule::class,
        UnboundedSelectRule::class,
        NonSargablePredicateRule::class,
        LeadingWildcardLikeRule::class,
        OrderByRandomRule::class,
        OffsetPaginationRule::class,
    ];

    /** @return array<int,array<string,mixed>> */
    public static function analyze(string $sql): array
    {
        $sql = SqlText::inspectable($sql);
        if ($sql === '' || preg_match('/\bSELECT\b/i', $sql) !== 1) {
            return [];
        }
        $findings = [];
        foreach (self::RULES as $rule) {
            foreach ($rule::analyze($sql) as $finding) {
                $findings[] = $finding;
            }
        }
        return $findings;
    }
}
