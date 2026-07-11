<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Gate;

final class MysqlQueryGateJUnitWriter
{
    /** @param array<string,mixed> $report */
    public static function render(array $report): string
    {
        $findings = is_array($report['findings'] ?? null) ? $report['findings'] : [];
        $tests = count($findings);
        $failures = 0;
        $skipped = 0;
        $cases = [];

        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $decision = (string)($finding['decision_effective'] ?? 'observe');
            $stability = (string)($finding['stability_status'] ?? 'not_required');
            $name = self::limit(
                (string)($finding['category'] ?? 'sql.gate')
                . ':' . (string)($finding['query_identity'] ?? $finding['finding_id'] ?? 'finding'),
                220
            );
            $class = self::limit('testkit.mysql.query-gate.' . (string)($finding['category'] ?? 'finding'), 220);
            $message = self::safeMessage($finding);
            $body = '    <testcase classname="' . self::xml($class) . '" name="' . self::xml($name) . '" time="0">' . "\n";

            if ($decision === 'block') {
                $failures++;
                $body .= '      <failure type="mysql-query-gate" message="' . self::xml($message) . '">'
                    . self::xml(self::details($finding)) . '</failure>' . "\n";
            } elseif (in_array($stability, ['insufficient_runs', 'insufficient_samples', 'unstable', 'incompatible_evidence'], true)
                || in_array((string)($finding['category'] ?? ''), ['evidence.insufficient', 'baseline.incompatible_context'], true)) {
                $skipped++;
                $body .= '      <skipped message="' . self::xml($message) . '" />' . "\n";
            } elseif ($decision === 'warn') {
                $body .= '      <system-out>' . self::xml(self::details($finding)) . '</system-out>' . "\n";
            }
            $body .= "    </testcase>\n";
            $cases[] = $body;
        }

        $header = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $header .= '<testsuite name="testkit.mysql.query-gate" tests="' . $tests
            . '" failures="' . $failures . '" errors="0" skipped="' . $skipped . '" time="0">' . "\n";
        $properties = '    <properties>' . "\n"
            . '      <property name="gate.mode" value="' . self::xml((string)($report['mode'] ?? 'off')) . '" />' . "\n"
            . '      <property name="gate.decision" value="' . self::xml((string)($report['decision']['status'] ?? 'disabled')) . '" />' . "\n"
            . '    </properties>' . "\n";

        return $header . $properties . implode('', $cases) . "</testsuite>\n";
    }

    /** @param array<string,mixed> $finding */
    private static function safeMessage(array $finding): string
    {
        return MysqlQueryGateArtifactWriter::sanitizeText((string)($finding['message'] ?? 'SQL query gate finding.'), 500);
    }

    /** @param array<string,mixed> $finding */
    private static function details(array $finding): string
    {
        $parts = [
            'finding_id=' . (string)($finding['finding_id'] ?? ''),
            'category=' . (string)($finding['category'] ?? ''),
            'decision=' . (string)($finding['decision_effective'] ?? ''),
            'severity=' . (string)($finding['severity'] ?? ''),
            'confidence=' . (string)($finding['confidence'] ?? ''),
            'stability=' . (string)($finding['stability_status'] ?? ''),
        ];
        if ((string)($finding['query_identity'] ?? '') !== '') {
            $parts[] = 'query_identity=' . (string)$finding['query_identity'];
        }
        if (!empty($finding['suppressed'])) {
            $parts[] = 'suppressed=true';
            $parts[] = 'suppression_id=' . (string)($finding['suppression']['suppression_id'] ?? '');
        }
        $parts[] = 'message=' . self::safeMessage($finding);
        return implode("\n", $parts);
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars(MysqlQueryGateArtifactWriter::sanitizeText($value, 2000), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function limit(string $value, int $max): string
    {
        return MysqlQueryGateArtifactWriter::sanitizeText($value, $max);
    }
}
