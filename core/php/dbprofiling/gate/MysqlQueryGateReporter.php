<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Gate;

use Testkit\Core\DbProfiling\InstrumentationContext;

final class MysqlQueryGateReporter
{
    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $runtimeConfig
     * @param array<string,mixed> $policyReport
     * @param array<int,array<string,mixed>> $comparisons
     * @return array<string,mixed>
     */
    public static function evaluate(
        array $profile,
        array $runtimeConfig,
        array $policyReport = [],
        array $comparisons = []
    ): array {
        if (empty($runtimeConfig['enabled'])) {
            return MysqlQueryGateConfig::disabledResult();
        }

        $gatePath = (string)($runtimeConfig['file'] ?? '');
        $gateRoot = MysqlQueryGateLoader::load($gatePath);
        $gateRoot['_runtime'] = [
            'max_findings' => (int)($runtimeConfig['max_findings'] ?? 5000),
        ];
        $modeOverride = trim((string)($runtimeConfig['mode_override'] ?? ''));
        $effectiveMode = $modeOverride !== '' ? strtolower($modeOverride) : (string)($gateRoot['gate']['mode'] ?? MysqlQueryGateConfig::MODE_OFF);
        if ($effectiveMode === MysqlQueryGateConfig::MODE_OFF) {
            $disabled = MysqlQueryGateConfig::disabledResult();
            $disabled['gate_id'] = (string)($gateRoot['gate']['id'] ?? '');
            $disabled['inputs'] = [
                'gate_hash' => (string)($gateRoot['_file_hash'] ?? MysqlQueryGateArtifactWriter::fileHash($gatePath)),
            ];
            return $disabled;
        }

        $allowlistRoot = [];
        $allowlistPath = (string)($runtimeConfig['allowlist_file'] ?? '');
        if ($allowlistPath !== '') {
            $allowlistRoot = MysqlQueryGateAllowlistLoader::load($allowlistPath);
        }

        $evidencePath = (string)($runtimeConfig['evidence_file'] ?? '');
        if ($evidencePath !== '') {
            $comparisons = array_merge(
                $comparisons,
                MysqlQueryGateEvidenceLoader::load($evidencePath, dirname($evidencePath))
            );
        }
        $comparisons = self::uniqueComparisons($comparisons);

        $normalized = MysqlQueryGateFindingNormalizer::normalize($profile, $policyReport, $comparisons);
        $report = MysqlQueryGateEvaluator::evaluate($normalized, $gateRoot, $allowlistRoot, $modeOverride);
        $report['inputs']['gate_hash'] = (string)($gateRoot['_file_hash'] ?? MysqlQueryGateArtifactWriter::fileHash($gatePath));
        $report['inputs']['allowlist_hash'] = $allowlistPath === '' ? '' : MysqlQueryGateArtifactWriter::fileHash($allowlistPath);
        $report['inputs']['evidence_manifest_hash'] = $evidencePath === '' ? '' : MysqlQueryGateArtifactWriter::fileHash($evidencePath);
        $report['inputs']['profile_schema_version'] = (string)($profile['schema_version'] ?? '');
        $report['inputs']['policy_schema_version'] = (string)($profile['policy_evaluation']['schema_version'] ?? $policyReport['schema_version'] ?? '');
        $report['inputs']['comparison_schema_version'] = self::comparisonSchema($comparisons, $profile);
        $report['run_id'] = (string)($profile['run_id'] ?? '');
        $report['suite_id'] = (string)($profile['suite_id'] ?? $profile['run_metadata']['suite_id'] ?? '');
        $report['config'] = [
            'gate_file' => MysqlQueryGateFinding::safePath($gatePath),
            'allowlist_file' => MysqlQueryGateFinding::safePath($allowlistPath),
            'evidence_file' => MysqlQueryGateFinding::safePath($evidencePath),
            'outputs' => (array)($gateRoot['gate']['outputs'] ?? []),
        ];
        $report['outputs'] = self::outputManifest($runtimeConfig, (array)($gateRoot['gate']['outputs'] ?? []));

        $approvalConfig = is_array($gateRoot['gate']['baseline_approval'] ?? null)
            ? $gateRoot['gate']['baseline_approval']
            : [];
        if (!empty($approvalConfig['enabled'])) {
            $approval = MysqlQueryBaselineApprovalEvaluator::evaluate(
                $report,
                is_array($comparisons[0] ?? null) ? $comparisons[0] : [],
                $profile,
                self::approvalCriteria($approvalConfig)
            );
            $report['baseline_approval'] = [
                'enabled' => true,
                'status' => (string)($approval['status'] ?? 'insufficient_evidence'),
                'eligible' => (bool)($approval['eligible'] ?? false),
                'report_path' => (string)($report['outputs']['approval'] ?? ''),
            ];
            $report['_approval_report'] = $approval;
        } else {
            $report['baseline_approval'] = ['enabled' => false, 'status' => 'not_evaluated', 'eligible' => false];
        }
        return $report;
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $gateReport @return array<string,mixed> */
    public static function attachToProfile(array $profile, array $gateReport): array
    {
        $profile['mysql_gate'] = self::profileAttachment($gateReport);
        $profile['quality_gate'] = $profile['mysql_gate'];
        foreach ((array)($profile['queries'] ?? []) as $index => $query) {
            if (!is_array($query)) {
                continue;
            }
            $identity = self::queryIdentity($query);
            if ($identity === '') {
                continue;
            }
            $matching = array_values(array_filter((array)($gateReport['findings'] ?? []), static fn(mixed $finding): bool =>
                is_array($finding) && (string)($finding['query_identity'] ?? '') === $identity
            ));
            if ($matching === []) {
                continue;
            }
            $profile['queries'][$index]['gate_status'] = self::highestDecision($matching);
            $profile['queries'][$index]['gate_findings_count'] = count($matching);
            $profile['queries'][$index]['gate_blocking_count'] = count(array_filter($matching, static fn(array $finding): bool =>
                (string)($finding['decision_effective'] ?? '') === 'block'
            ));
        }
        return $profile;
    }

    /** @param array<string,mixed> $gateReport @return array<string,mixed> */
    public static function profileAttachment(array $gateReport): array
    {
        return [
            'enabled' => (bool)($gateReport['enabled'] ?? false),
            'schema_version' => (string)($gateReport['schema_version'] ?? MysqlQueryGateConfig::REPORT_SCHEMA_VERSION),
            'gate_id' => (string)($gateReport['gate_id'] ?? ''),
            'mode' => (string)($gateReport['mode'] ?? MysqlQueryGateConfig::MODE_OFF),
            'decision' => (array)($gateReport['decision'] ?? []),
            'summary' => (array)($gateReport['summary'] ?? []),
            'stability' => (array)($gateReport['stability'] ?? []),
            'allowlist' => [
                'enabled' => (bool)($gateReport['allowlist']['enabled'] ?? false),
                'used' => count((array)($gateReport['allowlist']['used'] ?? [])),
                'unused' => count((array)($gateReport['allowlist']['unused'] ?? [])),
                'expired' => count((array)($gateReport['allowlist']['expired'] ?? [])),
            ],
            'outputs' => (array)($gateReport['outputs'] ?? []),
            'baseline_approval' => (array)($gateReport['baseline_approval'] ?? []),
            'limitations' => array_slice((array)($gateReport['limitations'] ?? []), 0, 20),
        ];
    }

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    public static function suiteAttachment(array $profile): array
    {
        return is_array($profile['mysql_gate'] ?? null)
            ? $profile['mysql_gate']
            : MysqlQueryGateConfig::disabledResult();
    }

    /** @param array<string,mixed> &$suiteResult @param array<string,mixed> $gateAttachment */
    public static function applyToSuiteResult(array &$suiteResult, array $gateAttachment): void
    {
        $gateExit = max(0, (int)($gateAttachment['decision']['exit_code'] ?? 0));
        $suiteResult['mysql_gate'] = $gateAttachment;
        $suiteResult['quality_gate'] = $gateAttachment;
        $suiteResult['blocking_findings'] = (int)($gateAttachment['summary']['blocking'] ?? 0);
        $suiteResult['gate_mode'] = (string)($gateAttachment['mode'] ?? MysqlQueryGateConfig::MODE_OFF);
        $suiteResult['gate_exit_code'] = $gateExit;
        $originalExit = (int)($suiteResult['exit_code'] ?? 0);
        if ($originalExit === 0 && in_array($gateExit, [
            MysqlQueryGateConfig::EXIT_OPERATIONAL,
            MysqlQueryGateConfig::EXIT_INVALID_CONTRACT,
            MysqlQueryGateConfig::EXIT_INCOMPATIBLE_INPUT,
            MysqlQueryGateConfig::EXIT_BLOCKED,
        ], true)) {
            $suiteResult['exit_code'] = $gateExit;
            $suiteResult['suite_status'] = 'failed';
            $suiteResult['quality_gate_status'] = 'blocked';
            return;
        }
        $suiteResult['quality_gate_status'] = (string)($gateAttachment['decision']['status'] ?? 'disabled');
    }

    /** @param array<string,mixed> $report @param array<string,mixed> $runtimeConfig */
    public static function writeArtifacts(array &$report, array $runtimeConfig, int $top = 20): void
    {
        if (empty($report['enabled'])) {
            return;
        }
        $outputsEnabled = (array)($report['config']['outputs'] ?? []);
        $paths = (array)($runtimeConfig['output'] ?? []);
        $summaryMarkdown = null;

        if (!empty($outputsEnabled['junit'])) {
            MysqlQueryGateArtifactWriter::writeText((string)($paths['junit_path'] ?? ''), MysqlQueryGateJUnitWriter::render($report));
        }
        if (!empty($outputsEnabled['sarif'])) {
            MysqlQueryGateArtifactWriter::writeJson((string)($paths['sarif_path'] ?? ''), MysqlQueryGateSarifWriter::build($report));
        }
        if (!empty($outputsEnabled['summary'])) {
            $summaryMarkdown = MysqlQueryGateSummaryWriter::render($report, $top);
            MysqlQueryGateArtifactWriter::writeText((string)($paths['summary_path'] ?? ''), $summaryMarkdown);
            if (!empty($outputsEnabled['github_step_summary'])) {
                $report['outputs']['github_step_summary_appended'] = MysqlQueryGateSummaryWriter::appendGithubStepSummary($summaryMarkdown);
            }
        }
        if (!empty($report['_approval_report']) && is_array($report['_approval_report'])) {
            MysqlQueryGateArtifactWriter::writeJson((string)($paths['approval_path'] ?? ''), $report['_approval_report']);
        }
        if (!empty($outputsEnabled['github_annotations']) && !empty($runtimeConfig['github_annotations'])) {
            $report['outputs']['github_annotations_emitted'] = MysqlQueryGateSummaryWriter::emitGithubAnnotations(
                $report,
                (int)($runtimeConfig['max_annotations'] ?? 50)
            );
        } else {
            $report['outputs']['github_annotations_emitted'] = 0;
        }

        unset($report['_approval_report']);
        if (!empty($outputsEnabled['json'])) {
            MysqlQueryGateArtifactWriter::writeJson((string)($paths['report_path'] ?? ''), $report);
            $historyDir = (string)($paths['history_path'] ?? '');
            if ($historyDir !== '') {
                $name = sprintf(
                    'mysql_gate_%s_%s_%s.json',
                    gmdate('Ymd_His'),
                    self::slug((string)($report['gate_id'] ?? 'gate')),
                    self::token(8)
                );
                MysqlQueryGateArtifactWriter::writeJson(rtrim($historyDir, '/\\') . '/' . $name, $report);
            }
        }
    }

    public static function invalidReport(MysqlQueryGateException $e, string $gateId = '', string $mode = 'off'): array
    {
        $status = $e->exitCode() === MysqlQueryGateConfig::EXIT_OPERATIONAL ? 'operational_error' : 'invalid_configuration';
        return [
            'enabled' => true,
            'schema_version' => MysqlQueryGateConfig::REPORT_SCHEMA_VERSION,
            'gate_id' => $gateId,
            'mode' => in_array($mode, MysqlQueryGateConfig::modes(), true) ? $mode : MysqlQueryGateConfig::MODE_OFF,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'inputs' => [],
            'summary' => [
                'findings' => 0,
                'blocking' => 0,
                'warnings' => 0,
                'observed' => 0,
                'suppressed' => 0,
                'suppressed_blocking' => 0,
                'pending_stability' => 0,
                'insufficient_evidence' => 0,
            ],
            'decision' => [
                'status' => $status,
                'exit_code' => $e->exitCode(),
                'reason' => $e->errorCode(),
            ],
            'error' => [
                'code' => $e->errorCode(),
                'path' => $e->jsonPath(),
                'message' => MysqlQueryGateArtifactWriter::sanitizeText($e->getMessage(), 300),
            ],
            'findings' => [],
            'allowlist' => ['enabled' => false, 'entries' => 0, 'used' => [], 'unused' => [], 'expired' => []],
            'stability' => ['enabled' => false],
            'outputs' => [],
            'limitations' => ['Gate evaluation did not complete because its input contract was invalid or unavailable.'],
        ];
    }

    /** @param array<int,array<string,mixed>> $comparisons @return array<int,array<string,mixed>> */
    private static function uniqueComparisons(array $comparisons): array
    {
        $out = [];
        foreach ($comparisons as $comparison) {
            if (!is_array($comparison)) {
                continue;
            }
            $hash = (string)($comparison['_artifact_hash'] ?? MysqlQueryGateArtifactWriter::payloadHash($comparison));
            if ($hash === '') {
                continue;
            }
            $comparison['_artifact_hash'] = $hash;
            $out[$hash] = $comparison;
        }
        ksort($out, SORT_STRING);
        return array_values($out);
    }

    /** @param array<int,array<string,mixed>> $comparisons @param array<string,mixed> $profile */
    private static function comparisonSchema(array $comparisons, array $profile): string
    {
        if (is_array($comparisons[0] ?? null)) {
            return (string)($comparisons[0]['schema_version'] ?? $comparisons[0]['comparison']['schema_version'] ?? '');
        }
        return (string)($profile['baseline_comparison']['schema_version'] ?? '');
    }

    /** @param array<string,mixed> $runtimeConfig @param array<string,mixed> $enabled @return array<string,mixed> */
    private static function outputManifest(array $runtimeConfig, array $enabled): array
    {
        $paths = (array)($runtimeConfig['output'] ?? []);
        return [
            'json' => !empty($enabled['json']) ? MysqlQueryGateFinding::safePath((string)($paths['report_path'] ?? '')) : '',
            'history' => !empty($enabled['json']) ? MysqlQueryGateFinding::safePath((string)($paths['history_path'] ?? '')) : '',
            'junit' => !empty($enabled['junit']) ? MysqlQueryGateFinding::safePath((string)($paths['junit_path'] ?? '')) : '',
            'sarif' => !empty($enabled['sarif']) ? MysqlQueryGateFinding::safePath((string)($paths['sarif_path'] ?? '')) : '',
            'summary' => !empty($enabled['summary']) ? MysqlQueryGateFinding::safePath((string)($paths['summary_path'] ?? '')) : '',
            'approval' => MysqlQueryGateFinding::safePath((string)($paths['approval_path'] ?? '')),
            'github_annotations_enabled' => !empty($enabled['github_annotations']) && !empty($runtimeConfig['github_annotations']),
            'github_step_summary_enabled' => !empty($enabled['github_step_summary']),
        ];
    }

    /** @param array<string,mixed> $approval @return array<string,mixed> */
    private static function approvalCriteria(array $approval): array
    {
        return [
            'minimum_successful_runs' => (int)($approval['minimum_successful_runs'] ?? 1),
            'minimum_sample_count' => (int)($approval['minimum_sample_count'] ?? 20),
            'maximum_policy_severity' => self::severityBefore((string)($approval['minimum_policy_severity'] ?? 'error')),
            'require_full_comparison' => (bool)($approval['require_full_compatibility'] ?? true),
            'require_source_commit' => (bool)($approval['require_source_commit'] ?? true),
            'require_dataset_identity' => (bool)($approval['require_dataset_identity'] ?? true),
            'require_environment_identity' => (bool)($approval['require_environment_identity'] ?? true),
            'require_no_suppressions' => true,
            'require_stability' => true,
        ];
    }

    private static function severityBefore(string $minimumBlocked): string
    {
        return match ($minimumBlocked) {
            'info' => 'info',
            'warning' => 'info',
            default => 'warning',
        };
    }

    /** @param array<string,mixed> $query */
    private static function queryIdentity(array $query): string
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', (array)($query['query_ids'] ?? [])))));
        if (count($ids) === 1) {
            return 'query_id:' . strtolower($ids[0]);
        }
        $fingerprint = (string)($query['fingerprint'] ?? '');
        return $fingerprint === '' ? '' : 'fingerprint:' . hash('sha256', $fingerprint);
    }

    /** @param array<int,array<string,mixed>> $findings */
    private static function highestDecision(array $findings): string
    {
        $rank = ['observe' => 0, 'warn' => 1, 'block' => 2];
        $best = 'observe';
        foreach ($findings as $finding) {
            $decision = (string)($finding['decision_effective'] ?? 'observe');
            if (($rank[$decision] ?? 0) > ($rank[$best] ?? 0)) {
                $best = $decision;
            }
        }
        return $best;
    }

    private static function slug(string $value): string
    {
        return substr(preg_replace('/[^a-z0-9._-]+/', '_', strtolower($value)) ?: 'gate', 0, 80);
    }

    private static function token(int $length): string
    {
        try {
            return substr(bin2hex(random_bytes((int)ceil($length / 2))), 0, $length);
        } catch (\Throwable) {
            return substr(hash('sha256', uniqid('', true)), 0, $length);
        }
    }
}
