<?php
declare(strict_types=1);

require_once __DIR__ . '/SqlText.php';
require_once __DIR__ . '/SqlSourceScanner.php';
require_once __DIR__ . '/SqlQueryExtractor.php';
require_once __DIR__ . '/SqlPredicates.php';
require_once __DIR__ . '/rules/SqlRule.php';
require_once __DIR__ . '/rules/RuleFinding.php';
require_once __DIR__ . '/rules/SelectStarRule.php';
require_once __DIR__ . '/rules/UnboundedSelectRule.php';
require_once __DIR__ . '/rules/NonSargablePredicateRule.php';
require_once __DIR__ . '/rules/LeadingWildcardLikeRule.php';
require_once __DIR__ . '/rules/OrderByRandomRule.php';
require_once __DIR__ . '/rules/OffsetPaginationRule.php';
require_once __DIR__ . '/rules/QueryInsideLoopRule.php';
require_once __DIR__ . '/SqlRuleRegistry.php';
require_once __DIR__ . '/SqlRuleSet.php';
require_once __DIR__ . '/PhpLoopRangeDetector.php';
require_once __DIR__ . '/SqlCoverageAnalyzer.php';
require_once __DIR__ . '/SqlBaselineComparator.php';
require_once __DIR__ . '/SqlStaticAuditor.php';
