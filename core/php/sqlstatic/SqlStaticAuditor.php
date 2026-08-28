<?php
declare(strict_types=1);

namespace Testkit\Core\SqlStatic;

use RuntimeException;

final class SqlStaticAuditor
{
    public const SCHEMA = 'testkit.sql-static-audit.v1';

    /** @return array<string,mixed> */
    public static function audit(string $root, array $paths = ['.'], array $excludes = []): array
    {
        $files = SqlSourceScanner::scan($root, $paths, $excludes);
        $findings = [];
        $queryCount = 0;

        foreach ($files as $file) {
            $content = file_get_contents($file['path']);
            if (!is_string($content)) {
                throw new RuntimeException('Unable to read SQL audit source: ' . $file['relative']);
            }
            foreach (SqlQueryExtractor::extract($file['path'], $content) as $query) {
                $queryCount++;
                foreach (SqlRuleSet::analyze($query['sql']) as $rule) {
                    $findings[] = self::decorate($file['relative'], $query, $rule);
                }
            }
        }

        usort($findings, static fn(array $a, array $b): int =>
            strcmp((string)$a['path'], (string)$b['path'])
            ?: ((int)$a['line'] <=> (int)$b['line'])
            ?: strcmp((string)$a['rule_id'], (string)$b['rule_id'])
        );

        return [
            'schema_version' => self::SCHEMA,
            'generated_at' => gmdate('c'),
            'root' => basename((string)(realpath($root) ?: $root)),
            'paths' => array_values($paths === [] ? ['.'] : $paths),
            'scanned_files' => count($files),
            'extracted_queries' => $queryCount,
            'summary' => self::summary($findings),
            'findings' => $findings,
        ];
    }

    /** @return array<string,mixed> */
    private static function decorate(string $path, array $query, array $rule): array
    {
        $ruleId = (string)$rule['ruleId'];
        $fingerprint = SqlText::fingerprint((string)$query['sql']);
        return [
            'id' => 'sql-static.' . $ruleId . '.' . substr(sha1($path . ':' . $query['line'] . ':' . $fingerprint), 0, 12),
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
    private static function summary(array $findings): array
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
            'by_rule' => $rules,
            'gate_enabled' => false,
        ];
    }
}
