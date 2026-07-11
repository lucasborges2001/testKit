<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Gate;

final class MysqlQueryGateSarifWriter
{
    /** @param array<string,mixed> $report @return array<string,mixed> */
    public static function build(array $report): array
    {
        $rules = [];
        $results = [];
        foreach ((array)($report['findings'] ?? []) as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $category = (string)($finding['category'] ?? 'evidence.insufficient');
            $definition = MysqlQueryGateFinding::definition($category);
            $ruleId = (string)$definition['rule_id'];
            if (!isset($rules[$ruleId])) {
                $rules[$ruleId] = [
                    'id' => $ruleId,
                    'name' => str_replace('.', '_', $ruleId),
                    'shortDescription' => [
                        'text' => (string)$definition['description'],
                    ],
                    'defaultConfiguration' => [
                        'level' => self::level((string)($finding['decision_effective'] ?? 'observe')),
                    ],
                    'properties' => [
                        'category' => (string)($finding['category'] ?? ''),
                        'source' => 'testkit-mysql-query-gate',
                    ],
                ];
            }
            $result = [
                'ruleId' => $ruleId,
                'level' => self::level((string)($finding['decision_effective'] ?? 'observe')),
                'message' => [
                    'text' => MysqlQueryGateArtifactWriter::sanitizeText((string)($finding['message'] ?? 'SQL query gate finding.'), 500),
                ],
                'partialFingerprints' => [
                    'primaryLocationLineHash' => (string)($finding['finding_id'] ?? ''),
                ],
                'properties' => [
                    'finding_id' => (string)($finding['finding_id'] ?? ''),
                    'query_identity' => (string)($finding['query_identity'] ?? ''),
                    'severity' => (string)($finding['severity'] ?? ''),
                    'confidence' => (string)($finding['confidence'] ?? ''),
                    'stability_status' => (string)($finding['stability_status'] ?? ''),
                    'suppressed' => (bool)($finding['suppressed'] ?? false),
                ],
            ];
            $location = self::location((array)($finding['location'] ?? []));
            if ($location !== null) {
                $result['locations'] = [$location];
            }
            $results[] = $result;
        }
        ksort($rules, SORT_STRING);
        usort($results, static fn(array $a, array $b): int => [
            (string)($a['ruleId'] ?? ''),
            (string)($a['partialFingerprints']['primaryLocationLineHash'] ?? ''),
        ] <=> [
            (string)($b['ruleId'] ?? ''),
            (string)($b['partialFingerprints']['primaryLocationLineHash'] ?? ''),
        ]);

        return [
            'version' => '2.1.0',
            '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
            'runs' => [[
                'tool' => [
                    'driver' => [
                        'name' => 'testkit-mysql-query-gate',
                        'informationUri' => 'https://github.com/lucasborges2001/testKit',
                        'semanticVersion' => '1.0.0',
                        'rules' => array_values($rules),
                    ],
                ],
                'automationDetails' => [
                    'id' => MysqlQueryGateArtifactWriter::sanitizeText((string)($report['gate_id'] ?? 'mysql-query-gate'), 160),
                ],
                'results' => $results,
            ]],
        ];
    }

    private static function level(string $decision): string
    {
        return match ($decision) {
            'block' => 'error',
            'warn' => 'warning',
            default => 'note',
        };
    }

    /** @param array<string,mixed> $location @return array<string,mixed>|null */
    private static function location(array $location): ?array
    {
        $path = MysqlQueryGateArtifactWriter::safeRelativePath((string)($location['path'] ?? ''));
        if ($path === '') {
            return null;
        }
        $physical = [
            'artifactLocation' => ['uri' => $path, 'uriBaseId' => '%SRCROOT%'],
        ];
        $line = $location['line'] ?? null;
        if (is_int($line) && $line > 0 && $line <= 10000000) {
            $physical['region'] = ['startLine' => $line];
        }
        return ['physicalLocation' => $physical];
    }
}
