<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Gate;

final class MysqlQueryGateEvaluator
{
    private const DECISION_RANK = ['observe' => 0, 'warn' => 1, 'block' => 2];
    private const SEVERITY_RANK = ['info' => 0, 'warning' => 1, 'error' => 2];

    /**
     * @param array<string,mixed> $normalized
     * @param array<string,mixed> $gateRoot
     * @param array<string,mixed> $allowlistRoot
     * @param string|null $modeOverride
     * @return array<string,mixed>
     */
    public static function evaluate(
        array $normalized,
        array $gateRoot,
        array $allowlistRoot = [],
        ?string $modeOverride = null
    ): array {
        $gate = is_array($gateRoot['gate'] ?? null) ? $gateRoot['gate'] : [];
        $mode = $modeOverride !== null && $modeOverride !== '' ? strtolower($modeOverride) : (string)($gate['mode'] ?? MysqlQueryGateConfig::MODE_OFF);
        if (!in_array($mode, MysqlQueryGateConfig::modes(), true)) {
            throw new MysqlQueryGateException('Unsupported gate mode.', '$.gate.mode', 'unsupported_gate_mode');
        }
        if ($mode === MysqlQueryGateConfig::MODE_OFF) {
            return MysqlQueryGateConfig::disabledResult();
        }

        $rules = is_array($gate['rules'] ?? null) ? $gate['rules'] : [];
        $preliminary = [];
        $typeOverrides = [];
        foreach ((array)($normalized['findings'] ?? []) as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $resolved = self::resolveRule($finding, $rules);
            $finding['_matched_rule'] = $resolved;
            $preliminary[] = $finding;
            if (($resolved['stability_type'] ?? 'auto') !== 'auto') {
                $typeOverrides[(string)$finding['finding_id']] = (string)$resolved['stability_type'];
            }
        }

        $stability = MysqlQueryGateStabilityEvaluator::evaluate(
            $preliminary,
            (array)($normalized['evidence_runs'] ?? []),
            $gate,
            $typeOverrides
        );

        $allowlist = is_array($allowlistRoot['allowlist'] ?? null) ? $allowlistRoot['allowlist'] : [];
        $entries = is_array($allowlist['entries'] ?? null) ? $allowlist['entries'] : [];
        $expiredFindings = self::expiredAllowlistFindings($entries, (array)($normalized['evidence_runs'] ?? []));
        foreach ($expiredFindings as $expired) {
            $expired['_matched_rule'] = self::resolveRule($expired, $rules);
        }
        $allFindings = array_merge((array)$stability['findings'], $expiredFindings);

        $minimumSeverity = (string)($gate['defaults']['minimum_severity'] ?? 'warning');
        $evaluated = [];
        foreach ($allFindings as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $rule = is_array($finding['_matched_rule'] ?? null)
                ? $finding['_matched_rule']
                : self::resolveRule($finding, $rules);
            unset($finding['_matched_rule']);
            $requested = (string)($rule['decision'] ?? 'observe');
            if ((self::SEVERITY_RANK[(string)($finding['severity'] ?? 'warning')] ?? 1) < (self::SEVERITY_RANK[$minimumSeverity] ?? 1)) {
                $requested = 'observe';
            }
            $finding['decision_requested'] = $requested;
            $finding['matched_rule_id'] = (string)($rule['id'] ?? '');
            $finding['matched_rule_precedence_rank'] = (int)($rule['precedence_rank'] ?? 0);

            $suppression = self::findSuppression($finding, $entries);
            if ($suppression !== null && MysqlQueryGateFinding::isSuppressible($finding)) {
                $finding['suppressed'] = true;
                $finding['suppression'] = [
                    'suppression_id' => (string)$suppression['id'],
                    'reason' => (string)$suppression['reason'],
                    'owner' => (string)$suppression['owner'],
                    'ticket' => (string)($suppression['ticket'] ?? ''),
                    'expires_at' => (string)$suppression['expires_at'],
                ];
            }

            $eligible = self::eligibleToBlock($finding, $rule, $gate);
            $finding['decision_effective'] = self::effectiveDecision(
                $requested,
                $mode,
                $eligible,
                (bool)$finding['suppressed']
            );
            $finding['eligibility'] = $eligible;
            $evaluated[] = $finding;
        }

        $usedSuppressions = [];
        foreach ($evaluated as $finding) {
            if (!empty($finding['suppressed']) && is_array($finding['suppression'] ?? null)) {
                $usedSuppressions[(string)$finding['suppression']['suppression_id']] = true;
            }
        }
        $unused = [];
        $expired = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = (string)($entry['id'] ?? '');
            if (!empty($entry['expired'])) {
                $expired[] = $id;
            } elseif (!isset($usedSuppressions[$id])) {
                $unused[] = $id;
            }
        }
        sort($unused, SORT_STRING);
        sort($expired, SORT_STRING);

        $maxFindings = max(1, min(5000, (int)($gateRoot['_runtime']['max_findings'] ?? 5000)));
        $evaluated = self::sortFindings($evaluated);
        $truncated = max(0, count($evaluated) - $maxFindings);
        $evaluated = array_slice($evaluated, 0, $maxFindings);

        $summary = self::summary($evaluated);
        $summary['truncated'] = $truncated;
        $decision = self::decision($mode, $summary);

        return [
            'enabled' => true,
            'schema_version' => MysqlQueryGateConfig::REPORT_SCHEMA_VERSION,
            'gate_id' => (string)($gate['id'] ?? ''),
            'mode' => $mode,
            'generated_at' => self::generatedAt((array)($normalized['evidence_runs'] ?? [])),
            'inputs' => (array)($normalized['inputs'] ?? []),
            'summary' => $summary,
            'decision' => $decision,
            'findings' => $evaluated,
            'allowlist' => [
                'enabled' => $allowlist !== [],
                'id' => (string)($allowlist['id'] ?? ''),
                'entries' => count($entries),
                'used' => array_values(array_keys($usedSuppressions)),
                'unused' => $unused,
                'expired' => $expired,
            ],
            'stability' => (array)($stability['summary'] ?? []),
            'outputs' => [],
            'limitations' => array_values(array_unique(array_merge(
                (array)($normalized['limitations'] ?? []),
                ['The gate consumes policy and comparison evidence and does not recalculate them.']
            ))),
        ];
    }

    /** @param array<string,mixed> $finding @param array<int,array<string,mixed>> $rules @return array<string,mixed> */
    private static function resolveRule(array $finding, array $rules): array
    {
        $matches = [];
        foreach ($rules as $rule) {
            if (is_array($rule) && self::matches($finding, (array)($rule['selectors'] ?? []))) {
                $matches[] = $rule;
            }
        }
        if ($matches === []) {
            return [
                'id' => '',
                'decision' => 'observe',
                'precedence_rank' => 0,
                'allow_structural_only' => false,
                'stability_type' => 'auto',
            ];
        }
        $maxRank = max(array_map(static fn(array $rule): int => (int)($rule['precedence_rank'] ?? 0), $matches));
        $top = array_values(array_filter($matches, static fn(array $rule): bool => (int)($rule['precedence_rank'] ?? 0) === $maxRank));
        $decisions = array_values(array_unique(array_map(static fn(array $rule): string => (string)($rule['decision'] ?? 'observe'), $top)));
        if (count($decisions) > 1) {
            throw new MysqlQueryGateException(
                'Matching gate rules have conflicting decisions at equal precedence.',
                '$.gate.rules',
                'gate_rule_precedence_conflict',
                MysqlQueryGateConfig::EXIT_INVALID_CONTRACT
            );
        }
        usort($top, static fn(array $a, array $b): int => strcmp((string)$a['id'], (string)$b['id']));
        return $top[0];
    }

    /** @param array<string,mixed> $finding @param array<string,array<int,string>> $selectors */
    private static function matches(array $finding, array $selectors): bool
    {
        foreach ($selectors as $key => $expected) {
            $actual = $finding[$key] ?? '';
            $actualValues = is_array($actual) ? array_map('strval', $actual) : [(string)$actual];
            if (array_intersect((array)$expected, $actualValues) === []) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $finding @param array<int,array<string,mixed>> $entries @return array<string,mixed>|null */
    private static function findSuppression(array $finding, array $entries): ?array
    {
        $matches = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !empty($entry['expired'])) {
                continue;
            }
            if (self::matches($finding, (array)($entry['selectors'] ?? []))) {
                $matches[] = $entry;
            }
        }
        if ($matches === []) {
            return null;
        }
        usort($matches, static fn(array $a, array $b): int => strcmp((string)$a['id'], (string)$b['id']));
        return $matches[0];
    }

    /** @param array<string,mixed> $finding @param array<string,mixed> $rule @param array<string,mixed> $gate @return array<string,mixed> */
    private static function eligibleToBlock(array $finding, array $rule, array $gate): array
    {
        $reasons = [];
        $status = (string)($finding['stability_status'] ?? 'not_required');
        if (!in_array($status, ['confirmed', 'not_required'], true)) {
            $reasons[] = 'stability_' . $status;
        }
        $scope = (string)($finding['evidence']['comparison_scope'] ?? '');
        $temporal = (string)($finding['stability_type'] ?? '') === 'temporal';
        if ($temporal && $scope !== '' && $scope !== 'full') {
            $reasons[] = 'timing_scope_not_full';
        }
        if (!$temporal && $scope === 'structural_only' && empty($rule['allow_structural_only'])) {
            $reasons[] = 'structural_only_not_allowed_by_rule';
        }
        if ((string)($finding['category'] ?? '') === 'baseline.incompatible_context') {
            $reasons[] = 'incompatible_context';
        }
        if ((string)($finding['category'] ?? '') === 'evidence.insufficient') {
            $reasons[] = 'insufficient_evidence';
        }
        if ((string)($finding['category'] ?? '') === 'evidence.invalid') {
            $reasons[] = 'invalid_embedded_evidence';
        }
        return [
            'eligible' => $reasons === [],
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    /** @param array<string,mixed> $eligibility */
    private static function effectiveDecision(string $requested, string $mode, array $eligibility, bool $suppressed): string
    {
        if ($suppressed) {
            return 'observe';
        }
        $eligible = (bool)($eligibility['eligible'] ?? false);
        if ($requested === 'block' && !$eligible) {
            return $mode === MysqlQueryGateConfig::MODE_REPORT ? 'observe' : 'warn';
        }
        if ($mode === MysqlQueryGateConfig::MODE_REPORT) {
            return 'observe';
        }
        if ($mode === MysqlQueryGateConfig::MODE_WARN) {
            return $requested === 'observe' ? 'observe' : 'warn';
        }
        return $requested;
    }

    /** @param array<int,array<string,mixed>> $entries @param array<int,array<string,mixed>> $runs @return array<int,array<string,mixed>> */
    private static function expiredAllowlistFindings(array $entries, array $runs): array
    {
        $run = is_array($runs[0] ?? null) ? $runs[0] : [];
        $out = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || empty($entry['expired'])) {
                continue;
            }
            $out[] = MysqlQueryGateFinding::make([
                'category' => 'allowlist.expired',
                'subcategory' => 'expired_entry',
                'source' => 'allowlist',
                'source_finding_id' => (string)($entry['id'] ?? ''),
                'severity' => 'warning',
                'confidence' => 'high',
                'stability_type' => 'none',
                'message' => 'Allowlist entry expired: ' . (string)($entry['id'] ?? ''),
                'evidence' => [
                    'artifact_hash' => (string)($run['artifact_hash'] ?? ''),
                    'run_id' => (string)($run['run_id'] ?? ''),
                    'generated_at' => (string)($run['generated_at'] ?? gmdate('Y-m-d\TH:i:s\Z')),
                    'entry_id' => (string)($entry['id'] ?? ''),
                    'owner' => (string)($entry['owner'] ?? ''),
                    'expires_at' => (string)($entry['expires_at'] ?? ''),
                ],
            ]);
        }
        return $out;
    }

    /** @param array<int,array<string,mixed>> $findings @return array<string,int> */
    private static function summary(array $findings): array
    {
        $summary = [
            'findings' => count($findings),
            'blocking' => 0,
            'warnings' => 0,
            'observed' => 0,
            'suppressed' => 0,
            'suppressed_blocking' => 0,
            'pending_stability' => 0,
            'insufficient_evidence' => 0,
        ];
        foreach ($findings as $finding) {
            $decision = (string)($finding['decision_effective'] ?? 'observe');
            if ($decision === 'block') {
                $summary['blocking']++;
            } elseif ($decision === 'warn') {
                $summary['warnings']++;
            } else {
                $summary['observed']++;
            }
            if (!empty($finding['suppressed'])) {
                $summary['suppressed']++;
                if ((string)($finding['decision_requested'] ?? '') === 'block') {
                    $summary['suppressed_blocking']++;
                }
            }
            if (in_array((string)($finding['stability_status'] ?? ''), ['insufficient_runs', 'insufficient_samples', 'unstable'], true)) {
                $summary['pending_stability']++;
            }
            if (in_array((string)($finding['category'] ?? ''), ['evidence.insufficient', 'baseline.incompatible_context'], true)
                || (string)($finding['stability_status'] ?? '') === 'incompatible_evidence') {
                $summary['insufficient_evidence']++;
            }
        }
        return $summary;
    }

    /** @param array<string,int> $summary @return array<string,mixed> */
    private static function decision(string $mode, array $summary): array
    {
        if ($mode === MysqlQueryGateConfig::MODE_FAIL && ($summary['blocking'] ?? 0) > 0) {
            return ['status' => 'blocked', 'exit_code' => MysqlQueryGateConfig::EXIT_BLOCKED, 'reason' => 'confirmed_blocking_findings'];
        }
        if (($summary['pending_stability'] ?? 0) > 0) {
            return ['status' => 'pending_stability', 'exit_code' => 0, 'reason' => 'findings_require_more_stable_evidence'];
        }
        if (($summary['warnings'] ?? 0) > 0) {
            return ['status' => 'warn', 'exit_code' => 0, 'reason' => 'warning_findings'];
        }
        if (($summary['insufficient_evidence'] ?? 0) > 0) {
            return ['status' => 'insufficient_evidence', 'exit_code' => 0, 'reason' => 'evidence_not_sufficient_for_blocking'];
        }
        return ['status' => 'pass', 'exit_code' => 0, 'reason' => 'no_blocking_findings'];
    }

    /** @param array<int,array<string,mixed>> $findings @return array<int,array<string,mixed>> */
    private static function sortFindings(array $findings): array
    {
        $rank = ['block' => 0, 'warn' => 1, 'observe' => 2];
        usort($findings, static function (array $a, array $b) use ($rank): int {
            return [
                $rank[(string)($a['decision_effective'] ?? 'observe')] ?? 9,
                (string)($a['category'] ?? ''),
                (string)($a['query_identity'] ?? ''),
                (string)($a['finding_id'] ?? ''),
            ] <=> [
                $rank[(string)($b['decision_effective'] ?? 'observe')] ?? 9,
                (string)($b['category'] ?? ''),
                (string)($b['query_identity'] ?? ''),
                (string)($b['finding_id'] ?? ''),
            ];
        });
        return $findings;
    }

    /** @param array<int,array<string,mixed>> $runs */
    private static function generatedAt(array $runs): string
    {
        $values = [];
        foreach ($runs as $run) {
            $value = (string)($run['generated_at'] ?? '');
            if ($value !== '') {
                $values[] = $value;
            }
        }
        sort($values, SORT_STRING);
        return $values === [] ? gmdate('Y-m-d\TH:i:s\Z') : $values[count($values) - 1];
    }
}
