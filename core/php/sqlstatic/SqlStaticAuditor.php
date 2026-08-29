<?php
declare(strict_types=1);

namespace Testkit\Core\SqlStatic;

use RuntimeException;
use Testkit\Core\SqlStatic\Rules\QueryInsideLoopRule;

final class SqlStaticAuditor
{
    public const SCHEMA = 'testkit.sql-static-audit.v1';

    /** @return array<string,mixed> */
    public static function audit(string $root, array $paths = ['.'], array $excludes = []): array
    {
        $files = SqlSourceScanner::scan($root, $paths, $excludes);
        $findings = [];
        $coverageFindings = [];
        $queryCount = 0;

        foreach ($files as $file) {
            $content = file_get_contents($file['path']);
            if (!is_string($content)) {
                throw new RuntimeException('Unable to read SQL audit source: ' . $file['relative']);
            }
            $queries = SqlQueryExtractor::extract($file['path'], $content);
            $queryCount += count($queries);
            $loops = self::isPhpLike($file['path']) ? PhpLoopRangeDetector::detect($content) : [];

            foreach ($queries as $query) {
                foreach (SqlRuleRegistry::analyze($query['sql']) as $rule) {
                    $findings[] = self::decorate($file['relative'], $query, $rule);
                }
                foreach (QueryInsideLoopRule::analyze($query, $loops) as $rule) {
                    $findings[] = self::decorate($file['relative'], $query, $rule);
                }
            }

            foreach (SqlCoverageAnalyzer::unresolved($file['path'], $content) as $coverage) {
                $coverageFindings[] = self::decorateCoverage($file['relative'], $coverage);
            }
        }

        self::sortFindings($findings);
        self::sortFindings($coverageFindings);
        $candidateCount = $queryCount + count($coverageFindings);

        return [
            'schema_version' => self::SCHEMA,
            'generated_at' => gmdate('c'),
            'root' => basename((string)(realpath($root) ?: $root)),
            'paths' => array_values($paths === [] ? ['.'] : $paths),
            'scanned_files' => count($files),
            'sql_candidates' => $candidateCount,
            'extracted_queries' => $queryCount,
            'unresolved_candidates' => count($coverageFindings),
            'coverage_status' => $coverageFindings === [] ? 'best_effort' : 'partial',
            'summary' => self::summary($findings, $coverageFindings),
            'findings' => $findings,
            'coverage_findings' => $coverageFindings,
            'delta' => ['status' => 'not_compared', 'gate_enabled' => false],
        ];
    }

    /** @return array<string,mixed> */
    private static function decorate(string $path, array $query, array $rule): array
    {
        $ruleId = (string)$rule['ruleId'];
        $fingerprint = SqlText::fingerprint((string)$query['sql']);
        $stableId = self::stableId($path, $ruleId, $fingerprint);
        return [
            'id' => 'sql-static.' . $ruleId . '.' . substr(sha1($path . ':' . $query['line'] . ':' . $fingerprint), 0, 12),
            'stable_id' => $stableId,
            'rule_id' => $ruleId,
            'severity' => (string)$rule['severity'],
            'confidence' => (string)$rule['confidence'],
            'path' => $path,
            'line' => (int)$query['line'],
            'fingerprint' => $fingerprint,
            'sample_sql' => SqlText::sample((string)$query['sql']),
            'summary' => (string)$rule['summary'],
            'recommendation' => (string)$rule['recommendation'],
            'evidence' => (array)$rule['evidence'],
        ];
    }

    /** @return array<string,mixed> */
    private static function decorateCoverage(string $path, array $finding): array
    {
        $rule = (string)$finding['rule_id'];
        $line = (int)$finding['line'];
        $call = (string)$finding['call'];
        return [
            'id' => 'sql-static-coverage.' . substr(sha1($path . ':' . $line . ':' . $call), 0, 12),
            'stable_id' => 'sql-static-coverage-stable.' . substr(sha1($path . ':' . $call), 0, 16),
            'rule_id' => $rule,
            'severity' => (string)$finding['severity'],
            'confidence' => (string)$finding['confidence'],
            'path' => $path,
            'line' => $line,
            'call' => $call,
            'reason' => (string)$finding['reason'],
            'summary' => (string)$finding['summary'],
            'recommendation' => 'Inspect this SQL construction path or expose a literal/query-builder adapter that the audit can classify.',
            'evidence' => ['call' => $call, 'reason' => (string)$finding['reason']],
        ];
    }

    /** @param array<int,array<string,mixed>> $findings */
    private static function sortFindings(array &$findings): void
    {
        usort($findings, static fn(array $a, array $b): int =>
            strcmp((string)$a['path'], (string)$b['path'])
            ?: ((int)$a['line'] <=> (int)$b['line'])
            ?: strcmp((string)$a['rule_id'], (string)$b['rule_id'])
        );
    }

    /** @return array<string,mixed> */
    private static function summary(array $findings, array $coverageFindings): array
    {
        $severity = ['warn' => 0, 'watch' => 0, 'info' => 0];
        $rules = [];
        foreach ($findings as $finding) {
            $level = (string)($finding['severity'] ?? 'info');
            $rule = (string)($finding['rule_id'] ?? 'unknown');
            $severity[$level] = (int)($severity[$level] ?? 0) + 1;
            $rules[$rule] = (int)($rules[$rule] ?? 0) + 1;
        }
        ksort($rules);
        return [
            'findings' => count($findings),
            'warn' => $severity['warn'],
            'watch' => $severity['watch'],
            'info' => $severity['info'],
            'coverage_findings' => count($coverageFindings),
            'by_rule' => $rules,
            'gate_enabled' => false,
        ];
    }

    private static function stableId(string $path, string $ruleId, string $fingerprint): string
    {
        return 'sql-static-stable.' . substr(sha1($ruleId . '|' . $path . '|' . $fingerprint), 0, 16);
    }

    private static function isPhpLike(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'sql';
    }
}
