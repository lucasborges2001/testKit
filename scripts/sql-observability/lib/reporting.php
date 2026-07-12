<?php
declare(strict_types=1);

final class SqlObsReportException extends RuntimeException
{
    public function __construct(string $message, private readonly int $processExitCode = 3)
    {
        parent::__construct($message);
    }

    public function processExitCode(): int
    {
        return $this->processExitCode;
    }
}

final class SqlObsReporting
{
    public const CONFIG_SCHEMA = 'testkit-sql-observability-report-config-v1';
    public const REPORT_SCHEMA = 'testkit-sql-observability-report-v1';
    public const TRENDS_SCHEMA = 'testkit-sql-observability-trends-v1';
    public const ARTIFACT_INDEX_SCHEMA = 'testkit-sql-observability-artifact-index-v1';
    public const FINAL_MANIFEST_SCHEMA = 'testkit-sql-observability-final-report-manifest-v1';
    public const BUNDLE_SCHEMA = 'testkit-sql-observability-report-bundle-v1';
    public const RUN_SCHEMA = 'testkit-sql-observability-run-manifest-v1';

    private const SENSITIVE_KEY = '/(?:password|passwd|secret|token|api[_-]?key|authorization|cookie|dsn|username|params?|parameters?|payload)/i';
    private const STATUS_PRIORITY = [
        'operational_error' => 90,
        'invalid_evidence' => 80,
        'blocked' => 70,
        'incompatible_evidence' => 60,
        'pending_baseline' => 50,
        'pending_stability' => 40,
        'insufficient_evidence' => 30,
        'healthy_with_warnings' => 20,
        'healthy' => 10,
    ];
    private const METRICS = ['calls', 'min_ms', 'avg_ms', 'max_ms', 'total_ms', 'p50_ms', 'p95_ms', 'p99_ms', 'standard_deviation_ms', 'sample_count'];

    /** @return array<string,mixed> */
    public static function loadConfig(string $path): array
    {
        $data = self::jsonFile($path, 1_048_576);
        self::assertObjectKeys($data, ['schema_version', 'reporting'], '$');
        if (($data['schema_version'] ?? '') !== self::CONFIG_SCHEMA) {
            throw new SqlObsReportException('Invalid reporting config schema.', 3);
        }
        $reporting = self::object($data['reporting'] ?? null, '$.reporting');
        self::assertObjectKeys($reporting, [
            'id', 'project_label', 'required_scenarios', 'required_repetitions', 'near_expiration_days',
            'baseline_maximum_age_days', 'top_limits', 'history', 'retention', 'outputs',
        ], '$.reporting');
        $id = self::safeId($reporting['id'] ?? null, '$.reporting.id', 160);
        $label = self::safeText($reporting['project_label'] ?? null, '$.reporting.project_label', 160);
        $requiredScenarios = self::stringList($reporting['required_scenarios'] ?? null, '$.reporting.required_scenarios', 20, 80);
        if ($requiredScenarios === []) {
            throw new SqlObsReportException('At least one required scenario is required.', 3);
        }
        $topLimits = self::object($reporting['top_limits'] ?? null, '$.reporting.top_limits');
        self::assertObjectKeys($topLimits, ['hotspots', 'regressions', 'new_queries', 'plan_changes', 'findings', 'recommendations'], '$.reporting.top_limits');
        foreach ($topLimits as $key => $value) {
            $topLimits[$key] = self::integer($value, '$.reporting.top_limits.' . $key, 1, 5000);
        }
        $history = self::object($reporting['history'] ?? null, '$.reporting.history');
        self::assertObjectKeys($history, ['maximum_bundles', 'maximum_age_days'], '$.reporting.history');
        $history['maximum_bundles'] = self::integer($history['maximum_bundles'] ?? null, '$.reporting.history.maximum_bundles', 1, 30);
        $history['maximum_age_days'] = self::integer($history['maximum_age_days'] ?? null, '$.reporting.history.maximum_age_days', 1, 365);
        $retention = self::object($reporting['retention'] ?? null, '$.reporting.retention');
        self::assertObjectKeys($retention, ['short_days', 'standard_days', 'baseline_evidence_days'], '$.reporting.retention');
        $retention['short_days'] = self::integer($retention['short_days'] ?? null, '$.reporting.retention.short_days', 1, 90);
        $retention['standard_days'] = self::integer($retention['standard_days'] ?? null, '$.reporting.retention.standard_days', 1, 90);
        $retention['baseline_evidence_days'] = self::integer($retention['baseline_evidence_days'] ?? null, '$.reporting.retention.baseline_evidence_days', 1, 90);
        if ($retention['short_days'] > $retention['standard_days'] || $retention['standard_days'] > $retention['baseline_evidence_days']) {
            throw new SqlObsReportException('Retention classes must be ordered short <= standard <= baseline_evidence.', 3);
        }
        $outputs = self::object($reporting['outputs'] ?? null, '$.reporting.outputs');
        self::assertObjectKeys($outputs, ['json', 'technical_markdown', 'executive_markdown', 'trends_json', 'trends_markdown', 'artifact_index'], '$.reporting.outputs');
        foreach ($outputs as $key => $value) {
            if (!is_bool($value)) {
                throw new SqlObsReportException('Output flag must be boolean: ' . $key, 3);
            }
        }
        return [
            'schema_version' => self::CONFIG_SCHEMA,
            'reporting' => [
                'id' => $id,
                'project_label' => $label,
                'required_scenarios' => $requiredScenarios,
                'required_repetitions' => self::integer($reporting['required_repetitions'] ?? null, '$.reporting.required_repetitions', 1, 5),
                'near_expiration_days' => self::integer($reporting['near_expiration_days'] ?? null, '$.reporting.near_expiration_days', 1, 30),
                'baseline_maximum_age_days' => self::integer($reporting['baseline_maximum_age_days'] ?? null, '$.reporting.baseline_maximum_age_days', 1, 730),
                'top_limits' => $topLimits,
                'history' => $history,
                'retention' => $retention,
                'outputs' => $outputs,
            ],
        ];
    }

    /** @return list<string> */
    public static function discoverManifests(string $root): array
    {
        $realRoot = realpath($root);
        if ($realRoot === false || !is_dir($realRoot)) {
            throw new SqlObsReportException('Report root is unavailable.', 2);
        }
        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($realRoot, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }
            if ($file->getFilename() === 'run-manifest.json') {
                $paths[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        sort($paths, SORT_STRING);
        return $paths;
    }

    /**
     * @param list<string> $manifestPaths
     * @param list<string> $historyManifestPaths
     * @return array{report:array<string,mixed>,trends:array<string,mixed>,input_artifacts:list<array<string,mixed>>,validation_status:string}
     */
    public static function build(array $manifestPaths, array $historyManifestPaths, array $config, ?string $root, string $generatedAt): array
    {
        self::assertUtcTime($generatedAt, 'generated_at');
        $rootReal = $root !== null ? realpath($root) : null;
        if ($root !== null && ($rootReal === false || !is_dir($rootReal))) {
            throw new SqlObsReportException('Report root is unavailable.', 2);
        }
        $manifestPaths = array_values(array_unique(array_map(static fn(string $p): string => str_replace('\\', '/', $p), $manifestPaths)));
        sort($manifestPaths, SORT_STRING);
        if ($manifestPaths === []) {
            $reporting = $config['reporting'];
            $empty = self::emptyReport($reporting, $generatedAt, 'insufficient_evidence', ['no_run_manifests']);
            return [
                'report' => $empty,
                'trends' => self::buildTrends($empty, [], $config, $generatedAt),
                'input_artifacts' => [],
                'validation_status' => 'insufficient_evidence',
            ];
        }

        $runs = [];
        $invalid = [];
        $artifactIndex = [];
        $seen = [];
        foreach ($manifestPaths as $manifestPath) {
            try {
                $run = self::loadRun($manifestPath, $rootReal !== false ? $rootReal : null);
                $key = $run['manifest']['scenario_id'] . '#' . $run['manifest']['repetition'];
                if (isset($seen[$key])) {
                    $invalid[] = [
                        'path' => self::displayPath($manifestPath, $rootReal !== false ? $rootReal : dirname($manifestPath)),
                        'reason' => 'duplicate_scenario_repetition',
                        'duplicate_of' => $seen[$key],
                    ];
                    continue;
                }
                $seen[$key] = self::displayPath($manifestPath, $rootReal !== false ? $rootReal : dirname($manifestPath));
                $runs[] = $run;
                array_push($artifactIndex, ...$run['artifact_index']);
            } catch (Throwable $e) {
                $invalid[] = [
                    'path' => self::displayPath($manifestPath, $rootReal !== false ? $rootReal : dirname($manifestPath)),
                    'reason' => self::sanitizeText($e->getMessage(), 240),
                ];
            }
        }
        usort($runs, static function (array $a, array $b): int {
            return [$a['manifest']['scenario_id'], $a['manifest']['repetition'], $a['manifest']['run_id']]
                <=> [$b['manifest']['scenario_id'], $b['manifest']['repetition'], $b['manifest']['run_id']];
        });

        $reporting = $config['reporting'];
        $requiredScenarios = $reporting['required_scenarios'];
        $requiredRepetitions = $reporting['required_repetitions'];
        $byScenario = [];
        foreach ($runs as $run) {
            $byScenario[$run['manifest']['scenario_id']][] = $run;
        }
        ksort($byScenario);

        $scenarioRows = [];
        $runRows = [];
        $allQueries = [];
        $instrumentation = ['findings' => [], 'capture_methods' => [], 'connections_observed' => 0, 'observable_coverage' => [], 'global_coverage' => 'unknown'];
        $policies = ['sets' => [], 'pass' => 0, 'violations' => 0, 'insufficient_data' => 0, 'not_evaluated' => 0, 'unused' => []];
        $baselines = [];
        $comparisons = ['regressions' => [], 'temporal_regressions' => [], 'structural_regressions' => [], 'plan_regressions' => [], 'improvements' => [], 'new_queries' => [], 'removed_queries' => [], 'plan_changes' => [], 'insufficient_data' => []];
        $gates = ['runs' => [], 'blocking' => 0, 'warnings' => 0, 'observed' => 0, 'suppressed' => 0, 'pending_stability' => 0, 'expired_allowlist' => 0];
        $allowlist = ['entries' => 0, 'used' => [], 'unused' => [], 'expired' => [], 'near_expiration' => []];
        $plans = ['signals' => [], 'full_table_scan' => 0, 'filesort' => 0, 'temporary_table' => 0, 'no_key_used' => 0, 'index_lost' => 0, 'index_added' => 0, 'access_improved' => 0, 'access_degraded' => 0];
        $warnings = [];
        $approvalByScenario = [];
        $compatibilityFailures = 0;
        $failedRepetitions = 0;
        $pendingBaseline = false;
        $pendingStability = false;
        $blocked = false;

        foreach ($byScenario as $scenarioId => $scenarioRuns) {
            $scenarioQueryMap = [];
            $scenarioRunMetrics = [];
            $scenarioFailed = 0;
            $scenarioBaselineReady = true;
            $scenarioCompatible = true;
            $referenceContext = null;
            foreach ($scenarioRuns as $run) {
                $manifest = $run['manifest'];
                $profile = $run['artifacts']['mysql_profile'] ?? [];
                $policyArtifact = $run['artifacts']['mysql_policy'] ?? [];
                $comparison = $run['artifacts']['mysql_comparison'] ?? [];
                $gate = $run['artifacts']['mysql_gate'] ?? [];
                $approval = $run['artifacts']['mysql_baseline_approval'] ?? [];
                $suiteExit = (int)($manifest['exit_codes']['suite'] ?? 2);
                $runStatus = $suiteExit === 0 ? 'passed' : 'failed';
                if ($suiteExit !== 0) {
                    $failedRepetitions++;
                    $scenarioFailed++;
                }
                if (($manifest['baseline_status'] ?? '') !== 'ready') {
                    $scenarioBaselineReady = false;
                    $pendingBaseline = true;
                }
                $context = [
                    'repository' => (string)($manifest['repository'] ?? ''),
                    'commit_sha' => (string)($manifest['commit_sha'] ?? ''),
                    'base_commit' => (string)($manifest['base_commit'] ?? ''),
                    'testkit_commit' => (string)($manifest['testkit_commit'] ?? ''),
                    'dataset_id' => (string)($manifest['dataset_id'] ?? ''),
                    'dataset_version' => (string)($manifest['dataset_version'] ?? ''),
                    'dataset_hash' => (string)($manifest['dataset_hash'] ?? ''),
                    'environment_id' => (string)($manifest['environment_id'] ?? ''),
                    'engine_version' => (string)($manifest['engine_version'] ?? ''),
                    'target' => (string)($manifest['target'] ?? ''),
                    'module_id' => (string)($manifest['module_id'] ?? ''),
                ];
                if ($referenceContext === null) {
                    $referenceContext = $context;
                } elseif ($referenceContext !== $context) {
                    $scenarioCompatible = false;
                    $compatibilityFailures++;
                }
                $profileSummary = is_array($profile['summary'] ?? null) ? $profile['summary'] : [];
                $scenarioRunMetrics[] = [
                    'repetition' => (int)$manifest['repetition'],
                    'total_queries' => (int)($profileSummary['total_queries'] ?? 0),
                    'unique_fingerprints' => (int)($profileSummary['unique_fingerprints'] ?? 0),
                    'total_sql_time_ms' => (float)($profileSummary['total_db_time_ms'] ?? $profileSummary['total_sql_time_ms'] ?? 0.0),
                ];
                $runRows[] = self::runSummary($run, $runStatus);
                self::collectProfile($profile, $manifest, $scenarioQueryMap, $instrumentation, $plans);
                self::collectPolicy($policyArtifact, $profile, $policies);
                self::collectComparison($comparison, $scenarioId, $manifest, $comparisons, $baselines, $plans);
                self::collectGate($gate, $scenarioId, $manifest, $gates, $allowlist, $generatedAt);
                if (($approval['status'] ?? '') !== '') {
                    $approvalByScenario[$scenarioId] = (string)$approval['status'];
                    if (($approval['status'] ?? '') !== 'eligible') {
                        $warnings[] = 'baseline_approval_' . self::safeIdentifierValue((string)$approval['status']);
                    }
                }
            }
            foreach ($scenarioQueryMap as $identity => $query) {
                $allQueries[] = self::finalizeQuery($query, $scenarioId);
            }
            usort($scenarioRunMetrics, static fn(array $a, array $b): int => $a['repetition'] <=> $b['repetition']);
            $scenarioRows[] = [
                'scenario_id' => $scenarioId,
                'module_id' => (string)($scenarioRuns[0]['manifest']['module_id'] ?? ''),
                'observed_repetitions' => count($scenarioRuns),
                'required_repetitions' => $requiredRepetitions,
                'failed_repetitions' => $scenarioFailed,
                'baseline_status' => $scenarioBaselineReady ? 'ready' : 'pending',
                'compatibility_status' => $scenarioCompatible ? 'compatible' : 'incompatible',
                'run_metrics' => $scenarioRunMetrics,
                'representative_metrics' => self::representativeScenarioMetrics($scenarioRunMetrics),
                'query_count' => count($scenarioQueryMap),
            ];
        }
        usort($scenarioRows, static fn(array $a, array $b): int => strcmp($a['scenario_id'], $b['scenario_id']));
        usort($runRows, static fn(array $a, array $b): int => [$a['scenario_id'], $a['repetition']] <=> [$b['scenario_id'], $b['repetition']]);
        usort($allQueries, static fn(array $a, array $b): int => [$a['scenario_id'], $a['identity']] <=> [$b['scenario_id'], $b['identity']]);

        $observedScenarios = array_keys($byScenario);
        sort($observedScenarios, SORT_STRING);
        $missingScenarios = array_values(array_diff($requiredScenarios, $observedScenarios));
        $validRepetitions = count($runs) - $failedRepetitions;
        $expectedRuns = count($requiredScenarios) * $requiredRepetitions;
        $insufficient = $missingScenarios !== [] || count($runs) < $expectedRuns || $failedRepetitions > 0;
        foreach ($gates['runs'] as $gateRow) {
            if (($gateRow['decision'] ?? '') === 'blocked' || (int)($gateRow['exit_code'] ?? 0) === 5) {
                $blocked = true;
            }
            if ((int)($gateRow['pending_stability'] ?? 0) > 0) {
                $pendingStability = true;
            }
        }
        $overall = 'healthy';
        if ($invalid !== []) {
            $overall = 'invalid_evidence';
        } elseif ($blocked) {
            $overall = 'blocked';
        } elseif ($compatibilityFailures > 0) {
            $overall = 'incompatible_evidence';
        } elseif ($pendingBaseline) {
            $overall = 'pending_baseline';
        } elseif ($pendingStability) {
            $overall = 'pending_stability';
        } elseif ($insufficient) {
            $overall = 'insufficient_evidence';
        } elseif ($gates['warnings'] > 0 || $policies['violations'] > 0 || $instrumentation['findings'] !== [] || $allowlist['expired'] !== []) {
            $overall = 'healthy_with_warnings';
        }

        $dataQuality = [
            'required_scenarios' => $requiredScenarios,
            'observed_scenarios' => $observedScenarios,
            'missing_scenarios' => $missingScenarios,
            'required_repetitions' => $requiredRepetitions,
            'expected_run_count' => $expectedRuns,
            'valid_repetitions' => $validRepetitions,
            'failed_repetitions' => $failedRepetitions,
            'excluded_artifacts' => $invalid,
            'hash_failures' => count(array_filter($invalid, static fn(array $v): bool => str_contains((string)($v['reason'] ?? ''), 'hash'))),
            'schema_failures' => count(array_filter($invalid, static fn(array $v): bool => str_contains((string)($v['reason'] ?? ''), 'schema'))),
            'compatibility_failures' => $compatibilityFailures,
            'coverage_status' => 'observable_only',
            'global_coverage' => 'unknown',
            'baseline_readiness' => $pendingBaseline ? 'pending' : 'ready',
            'stability_status' => $pendingStability ? 'pending' : ($compatibilityFailures > 0 ? 'incompatible' : 'observed'),
        ];

        $allowlist = self::markNearExpiration($allowlist, $generatedAt, (int)$reporting['near_expiration_days']);
        foreach ($baselines as &$baselineRow) {
            $baselineRow['approval_status'] = $approvalByScenario[(string)($baselineRow['scenario_id'] ?? '')] ?? 'not_observed';
            $baselineRow['age_days'] = self::ageDays((string)($baselineRow['created_at'] ?? ''), $generatedAt);
            $baselineRow['age_status'] = $baselineRow['age_days'] !== null && $baselineRow['age_days'] > (int)$reporting['baseline_maximum_age_days'] ? 'old_warning' : 'current_or_unknown';
            if ($baselineRow['age_status'] === 'old_warning') { $warnings[] = 'baseline_age_warning'; }
        }
        unset($baselineRow);
        $performance = self::performanceSummary($scenarioRows, $allQueries, $plans, $reporting['top_limits']);
        $recommendations = self::recommendations($overall, $dataQuality, $performance, $instrumentation, $policies, $baselines, $gates, $allowlist, $reporting['top_limits']['recommendations']);
        $provenance = self::provenance($runs);
        $reportId = $reporting['id'] . '.' . gmdate('YmdHis', strtotime($generatedAt)) . '.' . substr(hash('sha256', self::canonicalJson([$provenance, $manifestPaths, $generatedAt])), 0, 12);
        $report = [
            'schema_version' => self::REPORT_SCHEMA,
            'report_id' => $reportId,
            'generated_at' => $generatedAt,
            'project' => [
                'id' => $reporting['id'],
                'label' => $reporting['project_label'],
                'repository' => $provenance['repositories'][0] ?? '',
            ],
            'provenance' => $provenance,
            'input_inventory' => [
                'selected_run_manifests' => array_map(static fn(array $r): string => $r['manifest_display_path'], $runs),
                'selected_run_count' => count($runs),
                'invalid_or_excluded' => $invalid,
                'selection_mode' => $root !== null ? 'contractual_root_discovery_or_explicit' : 'explicit_manifests',
            ],
            'data_quality' => $dataQuality,
            'overall_status' => $overall,
            'summary' => [
                'scenarios' => count($scenarioRows),
                'runs' => count($runs),
                'queries_by_context' => count($allQueries),
                'blocking_findings' => $gates['blocking'],
                'warnings' => $gates['warnings'] + $policies['violations'],
                'pending' => ($pendingBaseline ? 1 : 0) + ($pendingStability ? 1 : 0),
                'representative_project_sql_time_ms' => self::projectRepresentativeTime($scenarioRows),
                'representative_time_semantics' => 'sum_of_independent_scenario_medians_not_request_latency',
            ],
            'scenarios' => $scenarioRows,
            'modules' => self::moduleSummary($scenarioRows, $allQueries),
            'runs' => $runRows,
            'queries' => $allQueries,
            'instrumentation' => self::normalizeInstrumentation($instrumentation),
            'coverage' => [
                'observable' => self::uniqueSorted($instrumentation['observable_coverage']),
                'global' => 'unknown',
                'reason' => 'No independent denominator exists for all application queries.',
            ],
            'policies' => self::normalizePolicy($policies),
            'baselines' => self::normalizeRows($baselines, ['scenario_id', 'baseline_id']),
            'comparisons' => self::normalizeComparisons($comparisons, $reporting['top_limits']),
            'gates' => self::normalizeGate($gates),
            'allowlist' => self::normalizeAllowlist($allowlist),
            'performance' => $performance,
            'plans' => self::normalizePlans($plans, $reporting['top_limits']['plan_changes']),
            'trends' => ['status' => 'pending_history_build', 'report_path' => 'sql-observability-trends.json'],
            'recommendations' => $recommendations,
            'artifacts' => ['index_path' => 'artifact-index.json', 'manifest_path' => 'report-manifest.json'],
            'limitations' => self::uniqueSorted(array_merge($warnings, [
                'Global query coverage is unknown because no independent denominator exists.',
                'Scenario repetitions are statistical samples and are not summed as separate functional loads.',
                'The report consumes upstream artifacts and does not rerun queries or recalculate policies, comparisons, or gates.',
            ])),
        ];
        $report = self::sanitizeRecursive($report);
        $history = self::loadHistoryReports($historyManifestPaths, $config, $generatedAt);
        $trends = self::buildTrends($report, $history, $config, $generatedAt);
        $report['trends'] = [
            'status' => (string)$trends['overall_status'],
            'report_path' => 'sql-observability-trends.json',
            'compatible_bundles' => (int)$trends['summary']['compatible_bundles'],
            'incompatible_bundles' => (int)$trends['summary']['incompatible_bundles'],
        ];
        return [
            'report' => $report,
            'trends' => $trends,
            'input_artifacts' => self::normalizeRows($artifactIndex, ['scenario', 'run', 'type', 'path']),
            'validation_status' => $overall,
        ];
    }

    /** @param array<string,mixed> $built @return array<string,string> */
    public static function writeOutputs(array $built, array $config, string $outputDir): array
    {
        if (is_link($outputDir)) {
            throw new SqlObsReportException('Output directory cannot be a symlink.', 3);
        }
        if (!is_dir($outputDir) && !mkdir($outputDir, 0770, true) && !is_dir($outputDir)) {
            throw new SqlObsReportException('Unable to create report output directory.', 2);
        }
        $outputDirReal = realpath($outputDir);
        if ($outputDirReal === false) {
            throw new SqlObsReportException('Unable to resolve output directory.', 2);
        }
        $report = $built['report'];
        $trends = $built['trends'];
        $files = [];
        $files['technical_json'] = self::writeJson($outputDirReal . '/sql-observability-report.json', $report);
        $files['technical_markdown'] = self::writeText($outputDirReal . '/sql-observability-technical.md', self::technicalMarkdown($report));
        $files['executive_markdown'] = self::writeText($outputDirReal . '/sql-observability-executive.md', self::executiveMarkdown($report));
        $files['trends_json'] = self::writeJson($outputDirReal . '/sql-observability-trends.json', $trends);
        $files['trends_markdown'] = self::writeText($outputDirReal . '/sql-observability-trends.md', self::trendsMarkdown($trends));

        $artifactRows = $built['input_artifacts'];
        foreach ($files as $type => $path) {
            $artifactRows[] = self::artifactRow($path, $outputDirReal, $type, '', 0, self::schemaForOutput($type), 'standard', 'public_internal');
        }
        $artifactRows = self::normalizeRows($artifactRows, ['type', 'scenario', 'run', 'path']);
        $artifactIndex = [
            'schema_version' => self::ARTIFACT_INDEX_SCHEMA,
            'generated_at' => $report['generated_at'],
            'report_id' => $report['report_id'],
            'artifacts' => $artifactRows,
            'retention_classes' => [
                'short' => (int)$config['reporting']['retention']['short_days'],
                'standard' => (int)$config['reporting']['retention']['standard_days'],
                'baseline_evidence' => (int)$config['reporting']['retention']['baseline_evidence_days'],
            ],
        ];
        $files['artifact_index_json'] = self::writeJson($outputDirReal . '/artifact-index.json', $artifactIndex);
        $files['artifact_index_markdown'] = self::writeText($outputDirReal . '/artifact-index.md', self::artifactIndexMarkdown($artifactIndex));

        $manifestFiles = [];
        foreach ($files as $type => $path) {
            $manifestFiles[$type] = [
                'path' => basename($path),
                'sha256' => hash_file('sha256', $path),
                'size_bytes' => filesize($path),
            ];
        }
        ksort($manifestFiles);
        $manifest = [
            'schema_version' => self::FINAL_MANIFEST_SCHEMA,
            'report_id' => $report['report_id'],
            'generated_at' => $report['generated_at'],
            'overall_status' => $report['overall_status'],
            'files' => $manifestFiles,
            'complete' => true,
        ];
        $files['report_manifest'] = self::writeJson($outputDirReal . '/report-manifest.json', $manifest);
        return $files;
    }

    /** @return array<string,mixed> */
    public static function validateFinalOutput(string $outputDir): array
    {
        $root = realpath($outputDir);
        if ($root === false || !is_dir($root)) {
            throw new SqlObsReportException('Final report directory is unavailable.', 2);
        }
        $manifestPath = $root . '/report-manifest.json';
        $manifest = self::jsonFile($manifestPath, 2_097_152);
        if (($manifest['schema_version'] ?? '') !== self::FINAL_MANIFEST_SCHEMA || empty($manifest['complete'])) {
            throw new SqlObsReportException('Final report manifest is invalid or incomplete.', 3);
        }
        $checked = [];
        foreach ((array)($manifest['files'] ?? []) as $name => $row) {
            if (!is_array($row)) {
                throw new SqlObsReportException('Invalid final report file entry.', 3);
            }
            $relative = (string)($row['path'] ?? '');
            if (!self::safeRelativePath($relative)) {
                throw new SqlObsReportException('Unsafe final report path.', 3);
            }
            $path = realpath($root . '/' . $relative);
            if ($path === false || !is_file($path) || is_link($root . '/' . $relative) || !self::underRoot($path, $root)) {
                throw new SqlObsReportException('Final report file is missing or unsafe: ' . $relative, 4);
            }
            $hash = hash_file('sha256', $path);
            if (!is_string($hash) || !hash_equals((string)($row['sha256'] ?? ''), $hash)) {
                throw new SqlObsReportException('Final report hash mismatch: ' . $relative, 4);
            }
            $checked[] = $relative;
        }
        sort($checked, SORT_STRING);
        return [
            'schema_version' => self::FINAL_MANIFEST_SCHEMA,
            'status' => 'valid',
            'overall_status' => (string)($manifest['overall_status'] ?? ''),
            'files_checked' => $checked,
        ];
    }

    /** @return array<string,mixed> */
    public static function inspectReport(string $path): array
    {
        $report = self::jsonFile($path, 20_971_520);
        if (($report['schema_version'] ?? '') !== self::REPORT_SCHEMA) {
            throw new SqlObsReportException('Unsupported report schema.', 3);
        }
        return [
            'schema_version' => self::REPORT_SCHEMA,
            'report_id' => (string)($report['report_id'] ?? ''),
            'generated_at' => (string)($report['generated_at'] ?? ''),
            'overall_status' => (string)($report['overall_status'] ?? ''),
            'data_quality' => (array)($report['data_quality'] ?? []),
            'summary' => (array)($report['summary'] ?? []),
            'top_risks' => array_slice((array)($report['recommendations'] ?? []), 0, 5),
        ];
    }

    /** @return array<string,mixed> */
    private static function loadRun(string $manifestPath, ?string $root): array
    {
        $manifestReal = realpath($manifestPath);
        if ($manifestReal === false || !is_file($manifestReal) || is_link($manifestPath)) {
            throw new SqlObsReportException('Run manifest is missing or unsafe.', 4);
        }
        if ($root !== null && !self::underRoot($manifestReal, $root)) {
            throw new SqlObsReportException('Run manifest is outside the selected root.', 4);
        }
        $manifest = self::jsonFile($manifestReal, 2_097_152);
        if (($manifest['schema_version'] ?? '') !== self::RUN_SCHEMA) {
            throw new SqlObsReportException('Run manifest schema mismatch.', 4);
        }
        foreach (['run_id','repository','commit_sha','scenario_id','repetition','target','module_id','dataset_id','dataset_version','dataset_hash','environment_id','engine_version','baseline_status','artifacts','hashes','exit_codes'] as $required) {
            if (!array_key_exists($required, $manifest)) {
                throw new SqlObsReportException('Run manifest missing field: ' . $required, 4);
            }
        }
        self::safeId($manifest['run_id'], '$.run_id', 160);
        self::safeId($manifest['scenario_id'], '$.scenario_id', 80);
        self::safeId($manifest['module_id'], '$.module_id', 160);
        $repetition = self::integer($manifest['repetition'], '$.repetition', 1, 5);
        $manifest['repetition'] = $repetition;
        if (preg_match('/^[a-f0-9]{64}$/', (string)$manifest['dataset_hash']) !== 1) {
            throw new SqlObsReportException('Run manifest dataset hash is invalid.', 4);
        }
        $artifacts = self::object($manifest['artifacts'], '$.artifacts');
        $hashes = self::object($manifest['hashes'], '$.hashes');
        $loaded = [];
        $artifactIndex = [];
        $manifestDir = dirname($manifestReal);
        foreach ($artifacts as $name => $relative) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) !== 1 || !is_string($relative) || !self::safeRelativePath($relative)) {
                throw new SqlObsReportException('Run manifest contains an unsafe artifact entry.', 4);
            }
            if (!isset($hashes[$name]) || preg_match('/^[a-f0-9]{64}$/', (string)$hashes[$name]) !== 1) {
                throw new SqlObsReportException('Artifact hash metadata is missing: ' . $name, 4);
            }
            $candidate = $manifestDir . '/' . $relative;
            if (is_link($candidate)) {
                throw new SqlObsReportException('Artifact symlink is not allowed: ' . $name, 4);
            }
            $real = realpath($candidate);
            if ($real === false || !is_file($real) || !self::underRoot($real, $manifestDir)) {
                throw new SqlObsReportException('Artifact is missing or outside the run: ' . $name, 4);
            }
            $actual = hash_file('sha256', $real);
            if (!is_string($actual) || !hash_equals((string)$hashes[$name], $actual)) {
                throw new SqlObsReportException('Artifact hash mismatch: ' . $name, 4);
            }
            if (str_ends_with(strtolower($real), '.json') || str_ends_with(strtolower($real), '.sarif')) {
                $loaded[$name] = self::jsonFile($real, 20_971_520);
                self::validateArtifactSchema($name, $loaded[$name]);
            }
            $artifactIndex[] = self::artifactRow(
                $real,
                $root ?? $manifestDir,
                $name,
                (string)$manifest['scenario_id'],
                $repetition,
                self::artifactSchema($name, $loaded[$name] ?? null),
                str_contains($name, 'baseline') ? 'baseline_evidence' : 'standard',
                in_array($name, ['mysql_profile','mysql_comparison','mysql_gate','mysql_policy'], true) ? 'restricted_internal' : 'public_internal'
            );
        }
        return [
            'manifest' => self::sanitizeRecursive($manifest),
            'manifest_path' => $manifestReal,
            'manifest_display_path' => self::displayPath($manifestReal, $root ?? $manifestDir),
            'artifacts' => $loaded,
            'artifact_index' => $artifactIndex,
        ];
    }

    private static function validateArtifactSchema(string $name, array $payload): void
    {
        $expected = match ($name) {
            'mysql_profile' => 'mysql-query-profile-report-v2',
            'mysql_policy' => 'mysql-query-policy-v1',
            'mysql_comparison' => 'mysql-query-comparison-report-v1',
            'mysql_gate' => 'mysql-query-gate-report-v1',
            'mysql_baseline_approval' => 'mysql-query-baseline-approval-report-v1',
            default => '',
        };
        if ($expected !== '' && ($payload['schema_version'] ?? '') !== $expected) {
            throw new SqlObsReportException('Artifact schema mismatch: ' . $name, 4);
        }
        if ($name === 'mysql_policy' && !is_array($payload['policy_evaluation'] ?? null)) {
            throw new SqlObsReportException('Policy artifact does not contain policy_evaluation.', 4);
        }
    }

    /** @param array<string,mixed> $run */
    private static function runSummary(array $run, string $status): array
    {
        $m = $run['manifest'];
        $profile = $run['artifacts']['mysql_profile'] ?? [];
        $policy = $run['artifacts']['mysql_policy']['policy_evaluation'] ?? ($profile['policy_evaluation'] ?? []);
        $comparison = $run['artifacts']['mysql_comparison'] ?? [];
        $gate = $run['artifacts']['mysql_gate'] ?? [];
        return [
            'run_id' => (string)$m['run_id'],
            'scenario_id' => (string)$m['scenario_id'],
            'module_id' => (string)$m['module_id'],
            'repetition' => (int)$m['repetition'],
            'status' => $status,
            'suite_exit_code' => (int)($m['exit_codes']['suite'] ?? 2),
            'dataset_id' => (string)$m['dataset_id'],
            'dataset_version' => (string)$m['dataset_version'],
            'dataset_hash' => (string)$m['dataset_hash'],
            'environment_id' => (string)$m['environment_id'],
            'engine_version' => (string)$m['engine_version'],
            'baseline_status' => (string)$m['baseline_status'],
            'profile_status' => ($profile['schema_version'] ?? '') === 'mysql-query-profile-report-v2' ? 'valid' : 'missing',
            'policy_status' => empty($policy['enabled']) ? 'not_evaluated' : ((int)($policy['violated_budgets'] ?? 0) > 0 ? 'violation' : 'pass'),
            'comparison_status' => (string)($comparison['compatibility']['status'] ?? ($m['baseline_status'] === 'ready' ? 'missing' : 'pending_baseline')),
            'gate_status' => (string)($gate['decision']['status'] ?? $gate['decision'] ?? 'not_evaluated'),
            'gate_exit_code' => (int)($gate['decision']['exit_code'] ?? $gate['exit_code'] ?? 0),
            'manifest_path' => $run['manifest_display_path'],
        ];
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $manifest @param array<string,array<string,mixed>> $queryMap */
    private static function collectProfile(array $profile, array $manifest, array &$queryMap, array &$instrumentation, array &$plans): void
    {
        if (($profile['schema_version'] ?? '') !== 'mysql-query-profile-report-v2') {
            return;
        }
        $instrumentation['connections_observed'] += count(array_filter((array)($profile['connections'] ?? []), 'is_array'));
        foreach ((array)($profile['instrumentation']['findings'] ?? []) as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $finding['scenario_id'] = $manifest['scenario_id'];
            $finding['run_id'] = $manifest['run_id'];
            $instrumentation['findings'][] = self::sanitizeRecursive($finding);
        }
        foreach ((array)($profile['coverage'] ?? []) as $key => $value) {
            if (is_scalar($value)) {
                $instrumentation['observable_coverage'][] = $key . ':' . (string)$value;
            }
        }
        $planMap = self::planMap($profile);
        foreach ((array)($profile['queries'] ?? []) as $query) {
            if (!is_array($query)) {
                continue;
            }
            $identity = self::queryIdentity($query);
            if ($identity === '') {
                continue;
            }
            $key = (string)$manifest['scenario_id'] . '|' . $identity;
            if (!isset($queryMap[$identity])) {
                $queryMap[$identity] = [
                    'identity' => $identity,
                    'fingerprint' => self::sanitizeSql((string)($query['fingerprint'] ?? '')),
                    'sample_sql' => self::sanitizeSql((string)($query['sample_sql'] ?? $query['fingerprint'] ?? '')),
                    'modules' => [], 'scenarios' => [], 'runs' => [], 'metric_samples' => [],
                    'capture_methods' => [], 'classification' => [], 'policy_status' => [], 'baseline_status' => [],
                    'comparison_status' => [], 'gate_status' => [], 'plan_status' => [], 'connection_counts' => [],
                ];
            }
            $row = &$queryMap[$identity];
            $row['modules'][] = (string)$manifest['module_id'];
            $row['scenarios'][] = (string)$manifest['scenario_id'];
            $row['runs'][] = (string)$manifest['run_id'];
            foreach (self::METRICS as $metric) {
                if (isset($query[$metric]) && is_numeric($query[$metric])) {
                    $row['metric_samples'][$metric][] = (float)$query[$metric];
                }
            }
            foreach ((array)($query['capture_methods'] ?? []) as $method => $count) {
                if (is_int($method)) {
                    $method = (string)$count;
                    $count = 1;
                }
                if ($method !== '') {
                    $row['capture_methods'][(string)$method] = (int)($row['capture_methods'][(string)$method] ?? 0) + (int)$count;
                    $instrumentation['capture_methods'][(string)$method] = (int)($instrumentation['capture_methods'][(string)$method] ?? 0) + (int)$count;
                }
            }
            $row['classification'][] = (string)($query['classification'] ?? 'unclassified');
            $row['policy_status'][] = (string)($query['policy_status'] ?? 'not_evaluated');
            $row['baseline_status'][] = (string)($manifest['baseline_status'] ?? 'unknown');
            $row['comparison_status'][] = (string)($query['baseline_status'] ?? 'not_evaluated');
            $row['gate_status'][] = (string)($query['gate_status'] ?? 'not_evaluated');
            $plan = $planMap[(string)($query['fingerprint'] ?? '')] ?? [];
            $row['plan_status'][] = $plan === [] ? 'not_available' : 'observed';
            $row['connection_counts'][] = count(array_filter((array)($profile['connections'] ?? []), 'is_array'));
            unset($row);
        }
        foreach ((array)($profile['explain']['findings'] ?? []) as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $flags = self::stringListLoose($finding['flags'] ?? []);
            foreach ($flags as $flag) {
                if (isset($plans[$flag])) {
                    $plans[$flag]++;
                }
            }
            $plans['signals'][] = [
                'scenario_id' => (string)$manifest['scenario_id'],
                'run_id' => (string)$manifest['run_id'],
                'query_identity' => self::planIdentity($finding),
                'flags' => $flags,
                'estimated_rows' => (int)($finding['plan_summary']['estimated_rows'] ?? 0),
                'access_types' => self::stringListLoose($finding['plan_summary']['access_types'] ?? []),
                'keys_used' => self::stringListLoose($finding['plan_summary']['keys_used'] ?? []),
                'status' => (string)($finding['explain_status'] ?? 'unknown'),
            ];
        }
    }

    private static function collectPolicy(array $artifact, array $profile, array &$policies): void
    {
        $evaluation = is_array($artifact['policy_evaluation'] ?? null)
            ? $artifact['policy_evaluation']
            : (is_array($profile['policy_evaluation'] ?? null) ? $profile['policy_evaluation'] : []);
        if ($evaluation === [] || empty($evaluation['enabled'])) {
            $policies['not_evaluated']++;
            return;
        }
        $setId = (string)($evaluation['policy_set_id'] ?? 'unknown');
        $policies['sets'][$setId] = [
            'policy_set_id' => $setId,
            'hash' => (string)($evaluation['policy_file_hash'] ?? ''),
            'loaded' => (int)($evaluation['loaded_policies'] ?? 0),
            'applicable' => (int)($evaluation['applicable_policies'] ?? 0),
        ];
        $policies['pass'] += (int)($evaluation['passed_budgets'] ?? 0);
        $policies['violations'] += (int)($evaluation['violated_budgets'] ?? 0);
        $policies['insufficient_data'] += (int)($evaluation['insufficient_data_budgets'] ?? 0);
        foreach ((array)($evaluation['unused_policies'] ?? []) as $unused) {
            if (is_array($unused)) {
                $policies['unused'][] = self::sanitizeRecursive($unused);
            }
        }
    }

    private static function collectComparison(array $comparison, string $scenarioId, array $manifest, array &$comparisons, array &$baselines, array &$plans): void
    {
        if (($comparison['schema_version'] ?? '') !== 'mysql-query-comparison-report-v1') {
            return;
        }
        $baseline = (array)($comparison['baseline'] ?? []);
        $baselines[] = [
            'scenario_id' => $scenarioId,
            'baseline_id' => (string)($baseline['id'] ?? ''),
            'hash' => (string)($baseline['hash'] ?? ''),
            'source_commit' => (string)($baseline['source_commit_sha'] ?? ''),
            'dataset_id' => (string)($comparison['compatibility']['checks']['dataset_id']['baseline'] ?? $manifest['dataset_id']),
            'dataset_version' => (string)($comparison['compatibility']['checks']['dataset_version']['baseline'] ?? $manifest['dataset_version']),
            'dataset_hash' => (string)($comparison['compatibility']['checks']['dataset_hash']['baseline'] ?? $manifest['dataset_hash']),
            'environment_id' => (string)($comparison['compatibility']['checks']['environment_id']['baseline'] ?? $manifest['environment_id']),
            'suite_id' => (string)($comparison['compatibility']['checks']['suite_id']['baseline'] ?? $manifest['target']),
            'created_at' => (string)($baseline['created_at'] ?? ''),
            'compatibility' => (string)($comparison['compatibility']['status'] ?? 'unknown'),
            'policy_hash' => (string)($baseline['policy_hash'] ?? ''),
        ];
        foreach ((array)($comparison['queries'] ?? []) as $query) {
            if (!is_array($query)) {
                continue;
            }
            $entry = [
                'scenario_id' => $scenarioId,
                'identity' => (string)($query['identity'] ?? ''),
                'status' => (string)($query['overall_status'] ?? 'insufficient_data'),
                'compatibility' => (string)($comparison['compatibility']['status'] ?? 'unknown'),
                'comparison_scope' => (string)($comparison['compatibility']['comparison_scope'] ?? 'none'),
                'timing_comparable' => (bool)($comparison['compatibility']['timing_comparable'] ?? false),
                'metric_results' => self::sanitizeRecursive((array)($query['metric_results'] ?? [])),
                'plan' => self::sanitizeRecursive((array)($query['plan_comparison'] ?? $query['plan'] ?? [])),
                'gate_decision' => (string)($query['gate_status'] ?? 'not_evaluated'),
                'suppression' => self::sanitizeRecursive((array)($query['suppression'] ?? [])),
            ];
            $status = $entry['status'];
            if ($status === 'regressed') {
                $comparisons['regressions'][] = $entry;
                $temporal = false;
                $structural = false;
                foreach ((array)$entry['metric_results'] as $metricResult) {
                    if (!is_array($metricResult) || ($metricResult['status'] ?? '') !== 'regressed') { continue; }
                    $metricName = (string)($metricResult['metric'] ?? '');
                    if (str_ends_with($metricName, '_ms')) { $temporal = true; }
                    else { $structural = true; }
                }
                if ($temporal) { $comparisons['temporal_regressions'][] = $entry; }
                if ($structural) { $comparisons['structural_regressions'][] = $entry; }
            } elseif ($status === 'improved') {
                $comparisons['improvements'][] = $entry;
            } elseif ($status === 'new') {
                $comparisons['new_queries'][] = $entry;
            } elseif ($status === 'removed') {
                $comparisons['removed_queries'][] = $entry;
            } elseif (in_array($status, ['insufficient_data','not_comparable','incompatible_context','structural_only'], true)) {
                $comparisons['insufficient_data'][] = $entry;
            }
            $planStatus = (string)($entry['plan']['status'] ?? $entry['plan']['overall_status'] ?? '');
            if ($planStatus !== '' && $planStatus !== 'unchanged' && $planStatus !== 'insufficient_data') {
                $entry['plan_status'] = $planStatus;
                $comparisons['plan_changes'][] = $entry;
                if ($planStatus === 'regressed') {
                    $plans['access_degraded']++;
                    $comparisons['plan_regressions'][] = $entry;
                } elseif ($planStatus === 'improved') {
                    $plans['access_improved']++;
                }
            }
        }
    }

    private static function collectGate(array $gate, string $scenarioId, array $manifest, array &$gates, array &$allowlist, string $generatedAt): void
    {
        if (($gate['schema_version'] ?? '') !== 'mysql-query-gate-report-v1') {
            return;
        }
        $decision = (array)($gate['decision'] ?? []);
        $summary = (array)($gate['summary'] ?? []);
        $row = [
            'scenario_id' => $scenarioId,
            'run_id' => (string)$manifest['run_id'],
            'mode' => (string)($gate['mode'] ?? $manifest['gate_mode']['effective'] ?? 'off'),
            'decision' => (string)($decision['status'] ?? $gate['status'] ?? 'unknown'),
            'exit_code' => (int)($decision['exit_code'] ?? $gate['exit_code'] ?? 0),
            'blocking' => (int)($summary['blocking'] ?? $summary['blocked'] ?? 0),
            'warnings' => (int)($summary['warnings'] ?? 0),
            'observed' => (int)($summary['observed'] ?? 0),
            'suppressed' => (int)($summary['suppressed'] ?? 0),
            'pending_stability' => (int)($summary['pending_stability'] ?? $gate['stability']['pending_stability'] ?? 0),
        ];
        $gates['runs'][] = $row;
        foreach (['blocking','warnings','observed','suppressed','pending_stability'] as $field) {
            $gates[$field] += (int)$row[$field];
        }
        $allow = (array)($gate['allowlist'] ?? []);
        foreach ((array)($allow['used'] ?? []) as $entry) {
            if (is_array($entry)) {
                $allowlist['used'][] = self::sanitizeRecursive($entry);
            }
        }
        foreach ((array)($allow['unused'] ?? []) as $entry) {
            if (is_array($entry)) {
                $allowlist['unused'][] = self::sanitizeRecursive($entry);
            }
        }
        foreach ((array)($allow['expired'] ?? []) as $entry) {
            if (is_array($entry)) {
                $allowlist['expired'][] = self::sanitizeRecursive($entry);
            }
        }
        $allowlist['entries'] += (int)($allow['entries'] ?? count((array)($allow['used'] ?? [])) + count((array)($allow['unused'] ?? [])) + count((array)($allow['expired'] ?? [])));
        $gates['expired_allowlist'] += count((array)($allow['expired'] ?? []));
    }

    /** @param array<string,mixed> $query */
    private static function finalizeQuery(array $query, string $scenarioId): array
    {
        $metrics = [];
        foreach (self::METRICS as $metric) {
            $values = array_values(array_map('floatval', (array)($query['metric_samples'][$metric] ?? [])));
            if ($values === []) {
                $metrics[$metric] = ['representative' => null, 'min' => null, 'max' => null, 'variation' => null, 'observations' => []];
                continue;
            }
            sort($values, SORT_NUMERIC);
            $min = $values[0];
            $max = $values[count($values) - 1];
            $median = self::median($values);
            $metrics[$metric] = [
                'representative' => self::number($median),
                'min' => self::number($min),
                'max' => self::number($max),
                'variation' => self::number($max - $min),
                'observations' => array_map([self::class, 'number'], $values),
            ];
        }
        ksort($query['capture_methods']);
        return [
            'identity' => (string)$query['identity'],
            'fingerprint' => (string)$query['fingerprint'],
            'sample_sql' => (string)$query['sample_sql'],
            'module_ids' => self::uniqueSorted($query['modules']),
            'scenario_id' => $scenarioId,
            'runs' => self::uniqueSorted($query['runs']),
            'metrics' => $metrics,
            'capture_methods' => $query['capture_methods'],
            'classification' => self::dominantStatus($query['classification'], ['hotspot','slow','ok','unclassified']),
            'policy_status' => self::dominantStatus($query['policy_status'], ['violation','insufficient_data','pass','not_applicable','not_evaluated']),
            'baseline_status' => self::dominantStatus($query['baseline_status'], ['pending_mysql_bootstrap','pending','candidate_validation','ready','unknown']),
            'comparison_status' => self::dominantStatus($query['comparison_status'], ['regressed','plan_changed','new','removed','improved','unchanged','insufficient_data','not_evaluated']),
            'gate_status' => self::dominantStatus($query['gate_status'], ['blocked','warning','observed','pass','not_evaluated']),
            'plan_status' => self::dominantStatus($query['plan_status'], ['regressed','improved','changed','observed','not_available']),
            'connection_count' => (int)max(array_map('intval', $query['connection_counts'] ?: [0])),
            'stability' => self::queryStability($metrics),
        ];
    }

    /** @param list<array<string,mixed>> $runMetrics */
    private static function representativeScenarioMetrics(array $runMetrics): array
    {
        $out = [];
        foreach (['total_queries','unique_fingerprints','total_sql_time_ms'] as $metric) {
            $values = [];
            foreach ($runMetrics as $row) {
                if (isset($row[$metric]) && is_numeric($row[$metric])) {
                    $values[] = (float)$row[$metric];
                }
            }
            sort($values, SORT_NUMERIC);
            $out[$metric] = $values === [] ? null : [
                'median' => self::number(self::median($values)),
                'min' => self::number($values[0]),
                'max' => self::number($values[count($values) - 1]),
                'variation' => self::number($values[count($values) - 1] - $values[0]),
            ];
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $scenarioRows @param list<array<string,mixed>> $queries */
    private static function performanceSummary(array $scenarioRows, array $queries, array $plans, array $limits): array
    {
        $hotspots = [];
        $metricMap = [
            'by_total_ms' => 'total_ms', 'by_p95_ms' => 'p95_ms', 'by_p99_ms' => 'p99_ms', 'by_calls' => 'calls',
        ];
        foreach ($metricMap as $ranking => $metric) {
            $rows = [];
            foreach ($queries as $query) {
                $value = $query['metrics'][$metric]['representative'] ?? null;
                if ($value === null) {
                    continue;
                }
                $rows[] = [
                    'scenario' => $query['scenario_id'],
                    'module' => $query['module_ids'][0] ?? '',
                    'query_identity' => $query['identity'],
                    'metric' => $metric,
                    'value' => $value,
                    'baseline' => $query['baseline_status'],
                    'status' => $query['comparison_status'],
                    'evidence_path' => 'queries/' . rawurlencode($query['identity']),
                ];
            }
            usort($rows, static fn(array $a, array $b): int => [$b['value'], $a['scenario'], $a['query_identity']] <=> [$a['value'], $b['scenario'], $b['query_identity']]);
            $hotspots[$ranking] = array_slice($rows, 0, (int)$limits['hotspots']);
        }
        $policyRows = [];
        foreach ($queries as $query) {
            if ($query['policy_status'] === 'violation') {
                $policyRows[] = [
                    'scenario' => $query['scenario_id'], 'module' => $query['module_ids'][0] ?? '',
                    'query_identity' => $query['identity'], 'metric' => 'policy_violations', 'value' => 1,
                    'baseline' => $query['baseline_status'], 'status' => 'violation',
                    'evidence_path' => 'queries/' . rawurlencode($query['identity']),
                ];
            }
        }
        $hotspots['by_policy_violations'] = array_slice($policyRows, 0, (int)$limits['hotspots']);
        $rowsExamined = [];
        $planRisk = [];
        $planSamples = [];
        foreach ((array)($plans['signals'] ?? []) as $signal) {
            $scenario = (string)($signal['scenario_id'] ?? '');
            $identity = (string)($signal['query_identity'] ?? '');
            if ($scenario === '' || $identity === '') {
                continue;
            }
            $key = $scenario . '|' . $identity;
            $planSamples[$key]['scenario'] = $scenario;
            $planSamples[$key]['query_identity'] = $identity;
            $planSamples[$key]['estimated_rows'][] = (float)($signal['estimated_rows'] ?? 0);
            $planSamples[$key]['risk_flags'][] = (float)count((array)($signal['flags'] ?? []));
            $planSamples[$key]['statuses'][] = (string)($signal['status'] ?? 'observed');
        }
        ksort($planSamples, SORT_STRING);
        foreach ($planSamples as $sample) {
            $base = [
                'scenario' => (string)$sample['scenario'],
                'module' => '',
                'query_identity' => (string)$sample['query_identity'],
                'baseline' => '',
                'status' => self::dominantStatus((array)$sample['statuses'], ['regressed','changed','improved','analyzed','observed','unknown']),
                'evidence_path' => 'plans/' . rawurlencode((string)$sample['query_identity']),
                'aggregation' => 'median_across_compatible_runs',
                'observations' => count((array)$sample['estimated_rows']),
            ];
            $rowsExamined[] = $base + [
                'metric' => 'rows_examined',
                'value' => self::number(self::median((array)$sample['estimated_rows'])),
            ];
            $planRisk[] = $base + [
                'metric' => 'plan_risk_flags',
                'value' => self::number(self::median((array)$sample['risk_flags'])),
            ];
        }
        usort($rowsExamined, static fn(array $a, array $b): int => [$b['value'],$a['scenario'],$a['query_identity']] <=> [$a['value'],$b['scenario'],$b['query_identity']]);
        usort($planRisk, static fn(array $a, array $b): int => [$b['value'],$a['scenario'],$a['query_identity']] <=> [$a['value'],$b['scenario'],$b['query_identity']]);
        $hotspots['by_rows_examined'] = array_slice($rowsExamined, 0, (int)$limits['hotspots']);
        $hotspots['by_plan_risk'] = array_slice($planRisk, 0, (int)$limits['hotspots']);
        return [
            'scenario_metrics' => array_map(static fn(array $s): array => [
                'scenario_id' => $s['scenario_id'],
                'representative_metrics' => $s['representative_metrics'],
                'semantics' => 'median_across_compatible_runs',
            ], $scenarioRows),
            'hotspots' => $hotspots,
            'no_double_counting' => true,
            'project_time_semantics' => 'Independent scenario medians are summed only as test-load indicators.',
        ];
    }

    private static function recommendations(string $overall, array $quality, array $performance, array $instrumentation, array $policies, array $baselines, array $gates, array $allowlist, int $limit): array
    {
        $rows = [];
        $add = static function (string $id, string $category, string $priority, string $scope, array $evidence, string $reason, string $action, string $confidence, array $limitations = []) use (&$rows): void {
            $rows[] = [
                'id' => $id,
                'category' => $category,
                'priority' => $priority,
                'scope' => $scope,
                'evidence' => $evidence,
                'reason' => $reason,
                'suggested_action' => $action,
                'confidence' => $confidence,
                'limitations' => $limitations,
            ];
        };
        if ($quality['missing_scenarios'] !== []) {
            $add('SQLOBS-REPORT-DQ-001', 'data_quality', 'critical', 'project', ['missing_scenarios' => $quality['missing_scenarios']], 'Required scenarios are absent.', 'Restore the missing scenario runs and regenerate the report.', 'high');
        }
        if ($quality['baseline_readiness'] !== 'ready') {
            $add('SQLOBS-REPORT-BASELINE-001', 'baseline', 'high', 'project', ['baseline_readiness' => $quality['baseline_readiness']], 'Real reviewed baselines are not available for all required scenarios.', 'Run the disposable-MySQL candidate flow, review approval, and promote candidates explicitly.', 'high', ['Do not fabricate or manually edit baseline metrics.']);
        }
        if ($quality['hash_failures'] > 0 || $quality['schema_failures'] > 0) {
            $add('SQLOBS-REPORT-INTEGRITY-001', 'data_quality', 'critical', 'project', ['hash_failures' => $quality['hash_failures'], 'schema_failures' => $quality['schema_failures']], 'One or more artifacts failed integrity validation.', 'Regenerate the affected run artifacts; do not reuse the invalid bundle.', 'high');
        }
        if ($gates['blocking'] > 0) {
            $add('SQLOBS-REPORT-GATE-001', 'gate', 'critical', 'project', ['blocking_findings' => $gates['blocking']], 'The SQL quality gate has blocking findings.', 'Review each blocking finding and its upstream comparison or policy evidence.', 'high');
        }
        if ($policies['violations'] > 0) {
            $add('SQLOBS-REPORT-POLICY-001', 'policy', 'high', 'project', ['violations' => $policies['violations']], 'Absolute SQL policy budgets were violated.', 'Review the violated policy rows and confirm whether code, policy calibration, or dataset drift is responsible.', 'high');
        }
        if ($instrumentation['findings'] !== []) {
            $add('SQLOBS-REPORT-INSTRUMENTATION-001', 'instrumentation', 'high', 'project', ['findings' => count($instrumentation['findings'])], 'Instrumentation findings reduce confidence in query coverage.', 'Resolve bypass or context findings before relying on performance conclusions.', 'high');
        }
        if ($allowlist['expired'] !== []) {
            $add('SQLOBS-REPORT-ALLOWLIST-001', 'allowlist', 'medium', 'project', ['expired' => count($allowlist['expired'])], 'Expired allowlist entries remain present.', 'Remove or explicitly renew each entry after technical review.', 'high', ['A suppression is not a technical resolution.']);
        }
        if ($overall === 'healthy') {
            $add('SQLOBS-REPORT-HEALTH-001', 'data_quality', 'low', 'project', ['status' => 'healthy'], 'Required evidence passed without blocking conditions.', 'Retain the report bundle and compare it with the next compatible run.', 'high');
        }
        $priorityRank = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
        usort($rows, static fn(array $a, array $b): int => [$priorityRank[$b['priority']] ?? 0, $a['id']] <=> [$priorityRank[$a['priority']] ?? 0, $b['id']]);
        $rows = array_slice($rows, 0, $limit);
        return array_map(static function (array $row): array {
            $row['recommendation_id'] = $row['id'];
            unset($row['id']);
            return $row;
        }, $rows);
    }

    /** @param list<array<string,mixed>> $runs */
    private static function provenance(array $runs): array
    {
        $fields = ['repository','commit_sha','base_commit','testkit_commit','dataset_hash','environment_id','engine_version'];
        $out = [];
        foreach ($fields as $field) {
            $values = [];
            foreach ($runs as $run) {
                $value = (string)($run['manifest'][$field] ?? '');
                if ($value !== '') {
                    $values[] = $value;
                }
            }
            $key = match ($field) {
                'repository' => 'repositories',
                'commit_sha' => 'commits',
                'base_commit' => 'base_commits',
                'testkit_commit' => 'testkit_commits',
                'dataset_hash' => 'dataset_hashes',
                'environment_id' => 'environment_ids',
                'engine_version' => 'engine_versions',
                default => $field,
            };
            $out[$key] = self::uniqueSorted($values);
        }
        $out['run_manifest_schema'] = self::RUN_SCHEMA;
        return $out;
    }

    /** @param list<string> $historyManifestPaths @return list<array<string,mixed>> */
    private static function loadHistoryReports(array $historyManifestPaths, array $config, string $generatedAt): array
    {
        $max = (int)$config['reporting']['history']['maximum_bundles'];
        $historyManifestPaths = array_slice(array_values(array_unique($historyManifestPaths)), 0, $max);
        sort($historyManifestPaths, SORT_STRING);
        $reports = [];
        foreach ($historyManifestPaths as $manifestPath) {
            try {
                $manifestReal = realpath($manifestPath);
                if ($manifestReal === false || !is_file($manifestReal) || is_link($manifestPath)) {
                    continue;
                }
                $manifest = self::jsonFile($manifestReal, 2_097_152);
                if (($manifest['schema_version'] ?? '') !== self::FINAL_MANIFEST_SCHEMA) {
                    continue;
                }
                $row = $manifest['files']['technical_json'] ?? null;
                if (!is_array($row) || !self::safeRelativePath((string)($row['path'] ?? ''))) {
                    continue;
                }
                $reportPath = realpath(dirname($manifestReal) . '/' . $row['path']);
                if ($reportPath === false || !is_file($reportPath) || !self::underRoot($reportPath, dirname($manifestReal))) {
                    continue;
                }
                $hash = hash_file('sha256', $reportPath);
                if (!is_string($hash) || !hash_equals((string)($row['sha256'] ?? ''), $hash)) {
                    continue;
                }
                $report = self::jsonFile($reportPath, 20_971_520);
                if (($report['schema_version'] ?? '') === self::REPORT_SCHEMA) {
                    $reports[] = $report;
                }
            } catch (Throwable) {
                continue;
            }
        }
        usort($reports, static fn(array $a, array $b): int => strcmp((string)$a['generated_at'], (string)$b['generated_at']));
        return $reports;
    }

    /** @return array<string,mixed> */
    private static function buildTrends(array $current, array $history, array $config, string $generatedAt): array
    {
        $bundles = array_merge($history, [$current]);
        $compatible = [];
        $incompatible = [];
        $currentProv = $current['provenance'] ?? [];
        foreach ($bundles as $bundle) {
            $sameDataset = ($bundle['provenance']['dataset_hashes'] ?? []) === ($currentProv['dataset_hashes'] ?? []);
            $sameEnvironment = ($bundle['provenance']['environment_ids'] ?? []) === ($currentProv['environment_ids'] ?? []);
            $sameBaseline = self::baselineHashes($bundle) === self::baselineHashes($current);
            if ($sameDataset && $sameEnvironment && $sameBaseline) {
                $compatible[] = $bundle;
            } else {
                $incompatible[] = [
                    'report_id' => (string)($bundle['report_id'] ?? ''),
                    'generated_at' => (string)($bundle['generated_at'] ?? ''),
                    'reasons' => array_values(array_filter([
                        $sameDataset ? null : 'dataset_hash_changed',
                        $sameEnvironment ? null : 'environment_changed',
                        $sameBaseline ? null : 'baseline_hash_changed',
                    ])),
                ];
            }
        }
        $series = [];
        $scenarioIds = [];
        foreach ($compatible as $bundle) {
            foreach ((array)($bundle['scenarios'] ?? []) as $scenario) {
                if (is_array($scenario)) {
                    $scenarioIds[(string)($scenario['scenario_id'] ?? '')] = true;
                }
            }
        }
        foreach (array_keys($scenarioIds) as $scenarioId) {
            foreach (['total_queries','unique_fingerprints','total_sql_time_ms'] as $metric) {
                $points = [];
                foreach ($compatible as $bundle) {
                    foreach ((array)($bundle['scenarios'] ?? []) as $scenario) {
                        if (!is_array($scenario) || ($scenario['scenario_id'] ?? '') !== $scenarioId) {
                            continue;
                        }
                        $value = $scenario['representative_metrics'][$metric]['median'] ?? null;
                        if (is_numeric($value)) {
                            $points[] = [
                                'report_id' => (string)$bundle['report_id'],
                                'generated_at' => (string)$bundle['generated_at'],
                                'value' => (float)$value,
                            ];
                        }
                    }
                }
                $status = 'insufficient_history';
                if (count($points) >= 2) {
                    $first = (float)$points[0]['value'];
                    $last = (float)$points[count($points) - 1]['value'];
                    $delta = $last - $first;
                    $threshold = max(abs($first) * 0.05, $metric === 'total_sql_time_ms' ? 1.0 : 1.0);
                    if (abs($delta) <= $threshold) {
                        $status = 'stable';
                    } elseif ($delta < 0) {
                        $status = 'improving';
                    } else {
                        $status = 'degrading';
                    }
                    if (count($points) >= 3) {
                        $directions = [];
                        for ($i = 1; $i < count($points); $i++) {
                            $d = (float)$points[$i]['value'] - (float)$points[$i - 1]['value'];
                            if (abs($d) > $threshold) {
                                $directions[] = $d > 0 ? 'up' : 'down';
                            }
                        }
                        if (count(array_unique($directions)) > 1) {
                            $status = 'mixed';
                        }
                    }
                }
                $series[] = [
                    'scenario_id' => $scenarioId,
                    'metric' => $metric,
                    'status' => $status,
                    'points' => $points,
                    'method' => 'first_last_with_5_percent_or_absolute_floor_no_prediction',
                ];
            }
        }
        usort($series, static fn(array $a, array $b): int => [$a['scenario_id'], $a['metric']] <=> [$b['scenario_id'], $b['metric']]);
        $statuses = array_column($series, 'status');
        $overall = count($compatible) < 2 ? 'insufficient_history'
            : (in_array('degrading', $statuses, true) ? 'degrading'
                : (in_array('mixed', $statuses, true) ? 'mixed'
                    : (in_array('improving', $statuses, true) ? 'improving' : 'stable')));
        return self::sanitizeRecursive([
            'schema_version' => self::TRENDS_SCHEMA,
            'generated_at' => $generatedAt,
            'report_id' => (string)$current['report_id'],
            'overall_status' => $overall,
            'summary' => [
                'input_bundles' => count($bundles),
                'compatible_bundles' => count($compatible),
                'incompatible_bundles' => count($incompatible),
                'minimum_bundles_for_trend' => 2,
            ],
            'series' => $series,
            'incompatible_history' => $incompatible,
            'limitations' => [
                'No prediction or regression line is produced.',
                'A single compatible bundle is reported as insufficient_history.',
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private static function emptyReport(array $reporting, string $generatedAt, string $status, array $limitations): array
    {
        return [
            'schema_version' => self::REPORT_SCHEMA,
            'report_id' => $reporting['id'] . '.' . gmdate('YmdHis', strtotime($generatedAt)) . '.empty',
            'generated_at' => $generatedAt,
            'project' => ['id' => $reporting['id'], 'label' => $reporting['project_label'], 'repository' => ''],
            'provenance' => ['repositories' => [], 'commits' => [], 'base_commits' => [], 'testkit_commits' => [], 'dataset_hashes' => [], 'environment_ids' => [], 'engine_versions' => [], 'run_manifest_schema' => self::RUN_SCHEMA],
            'input_inventory' => ['selected_run_manifests' => [], 'selected_run_count' => 0, 'invalid_or_excluded' => [], 'selection_mode' => 'none'],
            'data_quality' => [
                'required_scenarios' => $reporting['required_scenarios'], 'observed_scenarios' => [], 'missing_scenarios' => $reporting['required_scenarios'],
                'required_repetitions' => $reporting['required_repetitions'], 'expected_run_count' => count($reporting['required_scenarios']) * $reporting['required_repetitions'],
                'valid_repetitions' => 0, 'failed_repetitions' => 0, 'excluded_artifacts' => [], 'hash_failures' => 0, 'schema_failures' => 0,
                'compatibility_failures' => 0, 'coverage_status' => 'unknown', 'global_coverage' => 'unknown', 'baseline_readiness' => 'pending', 'stability_status' => 'insufficient',
            ],
            'overall_status' => $status,
            'summary' => ['scenarios' => 0, 'runs' => 0, 'queries_by_context' => 0, 'blocking_findings' => 0, 'warnings' => 0, 'pending' => 1, 'representative_project_sql_time_ms' => 0, 'representative_time_semantics' => 'no_evidence'],
            'scenarios' => [], 'modules' => [], 'runs' => [], 'queries' => [],
            'instrumentation' => ['findings' => [], 'capture_methods' => [], 'connections_observed' => 0, 'global_coverage' => 'unknown'],
            'coverage' => ['observable' => [], 'global' => 'unknown', 'reason' => 'No run evidence.'],
            'policies' => ['sets' => [], 'pass' => 0, 'violations' => 0, 'insufficient_data' => 0, 'not_evaluated' => 0, 'unused' => []],
            'baselines' => [], 'comparisons' => [], 'gates' => [], 'allowlist' => ['entries' => 0, 'used' => [], 'unused' => [], 'expired' => [], 'near_expiration' => []],
            'performance' => ['scenario_metrics' => [], 'hotspots' => [], 'no_double_counting' => true],
            'plans' => ['signals' => []], 'trends' => ['status' => 'insufficient_history'],
            'recommendations' => [[
                'recommendation_id' => 'sqlobs.collect-compatible-evidence',
                'category' => 'evidence',
                'priority' => 'high',
                'scope' => 'project',
                'evidence' => ['missing_scenarios' => $reporting['required_scenarios']],
                'reason' => 'No compatible run manifests were supplied; performance, policy, baseline and gate conclusions are unavailable.',
                'suggested_action' => 'Run each required scenario three times against the disposable MySQL environment, preserve the manifests, and rebuild the report.',
                'confidence' => 'high',
                'limitations' => ['No MySQL execution evidence was available to this report.'],
            ]],
            'artifacts' => ['index_path' => 'artifact-index.json', 'manifest_path' => 'report-manifest.json'],
            'limitations' => $limitations,
        ];
    }

    private static function technicalMarkdown(array $r): string
    {
        $lines = ['# SQL Observability — Technical Report', '', '## Estado general', '', '- Status: **' . self::md($r['overall_status']) . '**', '- Report ID: `' . self::md($r['report_id']) . '`', '- Generated: `' . self::md($r['generated_at']) . '`', ''];
        $lines[] = '## Provenance'; $lines[] = '';
        foreach (['commits','base_commits','testkit_commits','dataset_hashes','environment_ids','engine_versions'] as $key) {
            $lines[] = '- ' . $key . ': ' . self::md(implode(', ', (array)($r['provenance'][$key] ?? [])) ?: 'none');
        }
        $lines[] = ''; $lines[] = '## Calidad de evidencia'; $lines[] = '';
        $dq = $r['data_quality'];
        $lines[] = '| Campo | Valor |'; $lines[] = '|---|---|';
        foreach (['required_scenarios','observed_scenarios','missing_scenarios','required_repetitions','valid_repetitions','failed_repetitions','hash_failures','schema_failures','compatibility_failures','baseline_readiness','stability_status','global_coverage'] as $key) {
            $value = $dq[$key] ?? '';
            $lines[] = '| ' . self::md($key) . ' | ' . self::md(is_array($value) ? implode(', ', $value) : (string)$value) . ' |';
        }
        $lines[] = ''; $lines[] = '## Escenarios'; $lines[] = '';
        $lines[] = '| Scenario | Runs | Failed | Baseline | Compatibility | SQL time median ms |'; $lines[] = '|---|---:|---:|---|---|---:|';
        foreach ((array)$r['scenarios'] as $s) {
            $lines[] = '| ' . self::md($s['scenario_id']) . ' | ' . (int)$s['observed_repetitions'] . ' | ' . (int)$s['failed_repetitions'] . ' | ' . self::md($s['baseline_status']) . ' | ' . self::md($s['compatibility_status']) . ' | ' . self::md((string)($s['representative_metrics']['total_sql_time_ms']['median'] ?? 'n/a')) . ' |';
        }
        $lines[] = ''; $lines[] = '## Runs'; $lines[] = '';
        $lines[] = '| Scenario | Run | Status | Suite exit | Policy | Comparison | Gate |'; $lines[] = '|---|---:|---|---:|---|---|---|';
        foreach ((array)$r['runs'] as $run) {
            $lines[] = '| ' . self::md($run['scenario_id']) . ' | ' . (int)$run['repetition'] . ' | ' . self::md($run['status']) . ' | ' . (int)$run['suite_exit_code'] . ' | ' . self::md($run['policy_status']) . ' | ' . self::md($run['comparison_status']) . ' | ' . self::md($run['gate_status']) . ' |';
        }
        $lines[] = ''; $lines[] = '## Resumen de performance'; $lines[] = '';
        $lines[] = 'Representative project SQL time: **' . self::md((string)$r['summary']['representative_project_sql_time_ms']) . ' ms**. This is a sum of independent scenario medians, not request latency.';
        $lines[] = ''; $lines[] = '## Hotspots'; $lines[] = '';
        foreach ((array)($r['performance']['hotspots'] ?? []) as $name => $items) {
            $lines[] = '### ' . self::md($name); $lines[] = '';
            $lines[] = '| Scenario | Query identity | Metric | Value | Status |'; $lines[] = '|---|---|---|---:|---|';
            foreach (array_slice((array)$items, 0, 20) as $item) {
                $lines[] = '| ' . self::md($item['scenario']) . ' | `' . self::md($item['query_identity']) . '` | ' . self::md($item['metric']) . ' | ' . self::md((string)$item['value']) . ' | ' . self::md($item['status']) . ' |';
            }
            if ($items === []) { $lines[] = '| — | — | — | — | none |'; }
            $lines[] = '';
        }
        foreach ([
            'Regresiones' => 'regressions', 'Mejoras' => 'improvements', 'Queries nuevas' => 'new_queries', 'Queries removidas' => 'removed_queries', 'Cambios de plan' => 'plan_changes',
        ] as $title => $key) {
            $lines[] = '## ' . $title; $lines[] = '';
            $items = (array)($r['comparisons'][$key] ?? []);
            if ($items === []) { $lines[] = 'No evidence.'; $lines[] = ''; continue; }
            $lines[] = '| Scenario | Identity | Status | Compatibility |'; $lines[] = '|---|---|---|---|';
            foreach ($items as $item) {
                $lines[] = '| ' . self::md($item['scenario_id']) . ' | `' . self::md($item['identity']) . '` | ' . self::md($item['status']) . ' | ' . self::md($item['compatibility']) . ' |';
            }
            $lines[] = '';
        }
        $lines[] = '## Instrumentation health'; $lines[] = '';
        $lines[] = '- Findings: ' . count((array)($r['instrumentation']['findings'] ?? []));
        $lines[] = '- Connections observed: ' . (int)($r['instrumentation']['connections_observed'] ?? 0);
        $lines[] = '- Global coverage: **unknown**';
        $lines[] = '- Capture methods: ' . self::md(implode(', ', array_keys((array)($r['instrumentation']['capture_methods'] ?? []))) ?: 'none');
        $lines[] = '';
        $lines[] = '## Policies'; $lines[] = '';
        $lines[] = '- Passed budgets: ' . (int)($r['policies']['pass'] ?? 0);
        $lines[] = '- Violations: ' . (int)($r['policies']['violations'] ?? 0);
        $lines[] = '- Insufficient data: ' . (int)($r['policies']['insufficient_data'] ?? 0);
        $lines[] = '- Not evaluated: ' . (int)($r['policies']['not_evaluated'] ?? 0);
        $lines[] = '';
        $lines[] = '## Baselines'; $lines[] = '';
        $lines[] = '| Scenario | Baseline | Compatibility | Source commit |'; $lines[] = '|---|---|---|---|';
        foreach ((array)($r['baselines'] ?? []) as $baseline) {
            $lines[] = '| ' . self::md($baseline['scenario_id'] ?? '') . ' | ' . self::md($baseline['baseline_id'] ?? '') . ' | ' . self::md($baseline['compatibility'] ?? '') . ' | `' . self::md($baseline['source_commit'] ?? '') . '` |';
        }
        if (($r['baselines'] ?? []) === []) { $lines[] = '| — | pending | — | — |'; }
        $lines[] = '';
        $lines[] = '## Gates'; $lines[] = '';
        $lines[] = '- Blocking: ' . (int)($r['gates']['blocking'] ?? 0);
        $lines[] = '- Warnings: ' . (int)($r['gates']['warnings'] ?? 0);
        $lines[] = '- Observed: ' . (int)($r['gates']['observed'] ?? 0);
        $lines[] = '- Suppressed: ' . (int)($r['gates']['suppressed'] ?? 0);
        $lines[] = '- Pending stability: ' . (int)($r['gates']['pending_stability'] ?? 0);
        $lines[] = '';
        $lines[] = '## Allowlist'; $lines[] = '';
        $lines[] = '- Entries: ' . (int)($r['allowlist']['entries'] ?? 0);
        $lines[] = '- Used: ' . count((array)($r['allowlist']['used'] ?? []));
        $lines[] = '- Unused: ' . count((array)($r['allowlist']['unused'] ?? []));
        $lines[] = '- Expired: ' . count((array)($r['allowlist']['expired'] ?? []));
        $lines[] = '- Near expiration: ' . count((array)($r['allowlist']['near_expiration'] ?? []));
        $lines[] = '';
        $lines[] = '## Tendencias'; $lines[] = '';
        $lines[] = '- Status: ' . self::md($r['trends']['status'] ?? 'insufficient_history');
        $lines[] = '- Compatible bundles: ' . (int)($r['trends']['compatible_bundles'] ?? 0);
        $lines[] = '- Incompatible bundles: ' . (int)($r['trends']['incompatible_bundles'] ?? 0);
        $lines[] = '';
        $lines[] = '## Recomendaciones'; $lines[] = '';
        foreach ((array)$r['recommendations'] as $rec) {
            $lines[] = '- **' . self::md($rec['priority']) . ' / ' . self::md($rec['category']) . '** — ' . self::md($rec['reason']) . ' Action: ' . self::md($rec['suggested_action']) . ' (`' . self::md($rec['recommendation_id']) . '`)';
        }
        if ($r['recommendations'] === []) { $lines[] = '- None.'; }
        $lines[] = ''; $lines[] = '## Artifacts'; $lines[] = ''; $lines[] = '- [Artifact index](artifact-index.md)'; $lines[] = '- [Trends](sql-observability-trends.md)'; $lines[] = '- [Executive report](sql-observability-executive.md)';
        $lines[] = ''; $lines[] = '## Limitaciones'; $lines[] = '';
        foreach ((array)$r['limitations'] as $limitation) { $lines[] = '- ' . self::md($limitation); }
        return implode("\n", $lines) . "\n";
    }

    private static function executiveMarkdown(array $r): string
    {
        $status = (string)$r['overall_status'];
        $light = match ($status) {
            'healthy' => 'GREEN',
            'healthy_with_warnings' => 'AMBER',
            'blocked','invalid_evidence','operational_error' => 'RED',
            default => 'AMBER',
        };
        $risks = array_values(array_filter((array)$r['recommendations'], static fn(array $x): bool => in_array($x['priority'] ?? '', ['critical','high','medium'], true)));
        $improvements = array_slice((array)($r['comparisons']['improvements'] ?? []), 0, 5);
        $lines = [
            '# SQL Observability — Executive Report', '',
            '## Decision', '',
            '- Traffic light: **' . $light . '**',
            '- Consolidated status: **' . self::md($status) . '**',
            '- Blocking findings: **' . (int)$r['summary']['blocking_findings'] . '**', '',
            '## Evidence quality', '',
            '- Required scenarios: ' . self::md(implode(', ', (array)$r['data_quality']['required_scenarios'])),
            '- Observed scenarios: ' . self::md(implode(', ', (array)$r['data_quality']['observed_scenarios']) ?: 'none'),
            '- Valid repetitions: ' . (int)$r['data_quality']['valid_repetitions'],
            '- Failed repetitions: ' . (int)$r['data_quality']['failed_repetitions'],
            '- Global query coverage: **unknown**', '',
            '## Scenarios', '',
        ];
        foreach ((array)$r['scenarios'] as $scenario) {
            $lines[] = '- **' . self::md($scenario['scenario_id']) . '**: ' . (int)$scenario['observed_repetitions'] . ' runs; baseline ' . self::md($scenario['baseline_status']) . '; compatibility ' . self::md($scenario['compatibility_status']) . '.';
        }
        if ($r['scenarios'] === []) { $lines[] = '- No scenario evidence.'; }
        $lines[] = ''; $lines[] = '## Top risks'; $lines[] = '';
        foreach (array_slice($risks, 0, 5) as $risk) {
            $lines[] = '- **' . self::md($risk['priority']) . '** — ' . self::md($risk['reason']) . ' Next: ' . self::md($risk['suggested_action']);
        }
        if ($risks === []) { $lines[] = '- No material risks detected in the supplied evidence.'; }
        $lines[] = ''; $lines[] = '## Improvements'; $lines[] = '';
        foreach ($improvements as $item) {
            $lines[] = '- ' . self::md($item['scenario_id']) . ': ' . self::md($item['identity']) . ' improved under compatible comparison evidence.';
        }
        if ($improvements === []) { $lines[] = '- No compatible improvement evidence.'; }
        $lines[] = ''; $lines[] = '## Baselines'; $lines[] = '';
        $lines[] = '- Readiness: **' . self::md($r['data_quality']['baseline_readiness']) . '**';
        $lines[] = '- Baseline records observed: ' . count((array)$r['baselines']);
        $lines[] = ''; $lines[] = '## Gates'; $lines[] = '';
        $lines[] = '- Blocking: ' . (int)($r['gates']['blocking'] ?? 0);
        $lines[] = '- Warnings: ' . (int)($r['gates']['warnings'] ?? 0);
        $lines[] = '- Suppressed findings retained: ' . (int)($r['gates']['suppressed'] ?? 0);
        $lines[] = ''; $lines[] = '## Artifacts'; $lines[] = '';
        $lines[] = '- [Technical report](sql-observability-technical.md)';
        $lines[] = '- [Trends](sql-observability-trends.md)';
        $lines[] = '- [Artifact index](artifact-index.md)';
        $lines[] = ''; $lines[] = '## Próximas acciones'; $lines[] = '';
        foreach (array_slice((array)$r['recommendations'], 0, 5) as $rec) {
            $lines[] = '- ' . self::md($rec['suggested_action']);
        }
        if ($r['recommendations'] === []) { $lines[] = '- Preserve this bundle and compare it with the next compatible run.'; }
        return implode("\n", $lines) . "\n";
    }

    private static function trendsMarkdown(array $t): string
    {
        $lines = ['# SQL Observability — Trends', '', '- Status: **' . self::md($t['overall_status']) . '**', '- Compatible bundles: ' . (int)$t['summary']['compatible_bundles'], '- Incompatible bundles: ' . (int)$t['summary']['incompatible_bundles'], '', '| Scenario | Metric | Status | Points |', '|---|---|---|---:|'];
        foreach ((array)$t['series'] as $series) {
            $lines[] = '| ' . self::md($series['scenario_id']) . ' | ' . self::md($series['metric']) . ' | ' . self::md($series['status']) . ' | ' . count((array)$series['points']) . ' |';
        }
        if ($t['series'] === []) { $lines[] = '| — | — | insufficient_history | 0 |'; }
        $lines[] = ''; $lines[] = '## Limitations'; $lines[] = '';
        foreach ((array)$t['limitations'] as $limitation) { $lines[] = '- ' . self::md($limitation); }
        return implode("\n", $lines) . "\n";
    }

    private static function artifactIndexMarkdown(array $index): string
    {
        $lines = ['# SQL Observability — Artifact Index', '', '| Type | Scenario | Run | Path | SHA-256 | Bytes | Retention | Sensitivity |', '|---|---|---:|---|---|---:|---|---|'];
        foreach ((array)$index['artifacts'] as $row) {
            $lines[] = '| ' . self::md($row['type']) . ' | ' . self::md($row['scenario']) . ' | ' . (int)$row['run'] . ' | `' . self::md($row['path']) . '` | `' . self::md($row['sha256']) . '` | ' . (int)$row['size_bytes'] . ' | ' . self::md($row['retention_class']) . ' | ' . self::md($row['sensitivity']) . ' |';
        }
        return implode("\n", $lines) . "\n";
    }

    private static function markNearExpiration(array $allowlist, string $generatedAt, int $days): array
    {
        $now = strtotime($generatedAt) ?: 0;
        $limit = $now + ($days * 86400);
        foreach (array_merge((array)$allowlist['used'], (array)$allowlist['unused']) as $entry) {
            if (!is_array($entry)) { continue; }
            $expires = strtotime((string)($entry['expires_at'] ?? ''));
            if ($expires !== false && $expires >= $now && $expires <= $limit) {
                $allowlist['near_expiration'][] = $entry;
            }
        }
        return $allowlist;
    }

    private static function ageDays(string $createdAt, string $generatedAt): ?int
    {
        $created = strtotime($createdAt);
        $generated = strtotime($generatedAt);
        if ($created === false || $generated === false || $generated < $created) { return null; }
        return (int)floor(($generated - $created) / 86400);
    }

    private static function normalizeInstrumentation(array $i): array
    {
        ksort($i['capture_methods']);
        $i['findings'] = self::normalizeRows($i['findings'], ['scenario_id','run_id','code']);
        $i['observable_coverage'] = self::uniqueSorted($i['observable_coverage']);
        return $i;
    }

    private static function normalizePolicy(array $p): array
    {
        $p['sets'] = array_values($p['sets']);
        $p['sets'] = self::normalizeRows($p['sets'], ['policy_set_id']);
        $p['unused'] = self::normalizeRows($p['unused'], ['policy_id','status']);
        return $p;
    }

    private static function normalizeComparisons(array $c, array $limits): array
    {
        foreach ($c as $key => $rows) {
            $limitKey = match ($key) {
                'new_queries' => 'new_queries', 'plan_changes' => 'plan_changes', default => 'regressions',
            };
            $c[$key] = array_slice(self::normalizeRows($rows, ['scenario_id','identity','status']), 0, (int)$limits[$limitKey]);
        }
        return $c;
    }

    private static function normalizeGate(array $g): array
    {
        $g['runs'] = self::normalizeRows($g['runs'], ['scenario_id','run_id']);
        return $g;
    }

    private static function normalizeAllowlist(array $a): array
    {
        foreach (['used','unused','expired','near_expiration'] as $key) {
            $a[$key] = self::normalizeRows($a[$key], ['id','expires_at']);
        }
        return $a;
    }

    private static function normalizePlans(array $p, int $limit): array
    {
        $p['signals'] = array_slice(self::normalizeRows($p['signals'], ['scenario_id','run_id','query_identity']), 0, $limit);
        return $p;
    }

    /** @param list<array<string,mixed>> $scenarioRows @param list<array<string,mixed>> $queries */
    private static function moduleSummary(array $scenarioRows, array $queries): array
    {
        $modules = [];
        foreach ($scenarioRows as $scenario) {
            $id = (string)$scenario['module_id'];
            $modules[$id] ??= ['module_id' => $id, 'scenarios' => [], 'queries_by_context' => 0];
            $modules[$id]['scenarios'][] = (string)$scenario['scenario_id'];
        }
        foreach ($queries as $query) {
            foreach ((array)$query['module_ids'] as $id) {
                $modules[$id] ??= ['module_id' => $id, 'scenarios' => [], 'queries_by_context' => 0];
                $modules[$id]['queries_by_context']++;
            }
        }
        foreach ($modules as &$module) {
            $module['scenarios'] = self::uniqueSorted($module['scenarios']);
        }
        unset($module);
        return self::normalizeRows(array_values($modules), ['module_id']);
    }

    private static function projectRepresentativeTime(array $scenarioRows): float|int
    {
        $sum = 0.0;
        foreach ($scenarioRows as $row) {
            $value = $row['representative_metrics']['total_sql_time_ms']['median'] ?? null;
            if (is_numeric($value)) {
                $sum += (float)$value;
            }
        }
        return self::number($sum);
    }

    private static function queryStability(array $metrics): array
    {
        $calls = $metrics['calls']['observations'] ?? [];
        $stableCalls = count(array_unique(array_map('strval', $calls))) <= 1;
        return [
            'structural_calls' => $stableCalls ? 'stable' : 'drift',
            'run_count' => count($calls),
            'latency_variation_observed' => true,
        ];
    }

    private static function baselineHashes(array $report): array
    {
        $hashes = [];
        foreach ((array)($report['baselines'] ?? []) as $row) {
            if (is_array($row) && preg_match('/^[a-f0-9]{64}$/', (string)($row['hash'] ?? '')) === 1) {
                $hashes[] = (string)$row['hash'];
            }
        }
        return self::uniqueSorted($hashes);
    }

    /** @return array<string,array<string,mixed>> */
    private static function planMap(array $profile): array
    {
        $map = [];
        foreach ((array)($profile['explain']['findings'] ?? []) as $finding) {
            if (is_array($finding)) {
                $map[(string)($finding['fingerprint'] ?? '')] = $finding;
            }
        }
        return $map;
    }

    private static function planIdentity(array $finding): string
    {
        $queryId = trim((string)($finding['query_id'] ?? ''));
        if ($queryId !== '') {
            return 'query_id:' . self::safeIdentifierValue($queryId);
        }
        $fingerprint = self::sanitizeSql((string)($finding['fingerprint'] ?? ''));
        return $fingerprint === '' ? '' : 'fingerprint:' . hash('sha256', $fingerprint);
    }

    private static function queryIdentity(array $query): string
    {
        $ids = array_values(array_filter((array)($query['query_ids'] ?? []), static fn(mixed $v): bool => is_string($v) && trim($v) !== ''));
        if (count($ids) === 1) {
            return 'query_id:' . self::safeIdentifierValue($ids[0]);
        }
        $fingerprint = self::sanitizeSql((string)($query['fingerprint'] ?? $query['sample_sql'] ?? ''));
        return $fingerprint === '' ? '' : 'fingerprint:' . hash('sha256', $fingerprint);
    }

    private static function artifactRow(string $path, string $root, string $type, string $scenario, int $run, string $schema, string $retention, string $sensitivity): array
    {
        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            throw new SqlObsReportException('Unable to index artifact: ' . basename($path), 2);
        }
        return [
            'artifact_id' => substr(hash('sha256', $type . '|' . $scenario . '|' . $run . '|' . $real), 0, 24),
            'type' => $type,
            'scenario' => $scenario,
            'run' => $run,
            'path' => self::displayPath($real, $root),
            'sha256' => hash_file('sha256', $real),
            'size_bytes' => filesize($real),
            'schema_version' => $schema,
            'created_at' => (string)(getenv('SQLOBS_REPORT_NOW') ?: gmdate('Y-m-d\TH:i:s\Z', filemtime($real) ?: time())),
            'retention_class' => $retention,
            'sensitivity' => $sensitivity,
        ];
    }

    private static function artifactSchema(string $name, mixed $payload): string
    {
        return is_array($payload) ? (string)($payload['schema_version'] ?? '') : match (true) {
            str_contains($name, 'junit') => 'junit-xml',
            str_contains($name, 'summary') => 'markdown',
            default => '',
        };
    }

    private static function schemaForOutput(string $type): string
    {
        return match ($type) {
            'technical_json' => self::REPORT_SCHEMA,
            'trends_json' => self::TRENDS_SCHEMA,
            'technical_markdown','executive_markdown','trends_markdown' => 'markdown',
            default => '',
        };
    }

    private static function writeJson(string $path, array $payload): string
    {
        return self::writeText($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    }

    private static function writeText(string $path, string $content): string
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new SqlObsReportException('Unable to create output directory.', 2);
        }
        if (is_link($path)) {
            throw new SqlObsReportException('Refusing to replace a symlink.', 3);
        }
        $tmp = tempnam($dir, '.sqlobs-report-');
        if ($tmp === false) {
            throw new SqlObsReportException('Unable to allocate report temporary file.', 2);
        }
        try {
            $handle = fopen($tmp, 'wb');
            if ($handle === false) {
                throw new SqlObsReportException('Unable to open report temporary file.', 2);
            }
            $written = fwrite($handle, $content);
            if ($written === false || $written !== strlen($content)) {
                fclose($handle);
                throw new SqlObsReportException('Incomplete report write.', 2);
            }
            fflush($handle);
            if (function_exists('fsync')) {
                fsync($handle);
            }
            fclose($handle);
            chmod($tmp, 0640);
            if (!rename($tmp, $path)) {
                throw new SqlObsReportException('Unable to publish report atomically.', 2);
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
        return $path;
    }

    private static function jsonFile(string $path, int $maxBytes): array
    {
        if (!is_file($path) || is_link($path)) {
            throw new SqlObsReportException('JSON file is missing or unsafe: ' . basename($path), 2);
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 0 || $size > $maxBytes) {
            throw new SqlObsReportException('JSON file exceeds the allowed size: ' . basename($path), 3);
        }
        try {
            $data = json_decode((string)file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SqlObsReportException('Invalid JSON: ' . basename($path), 3);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new SqlObsReportException('Expected JSON object: ' . basename($path), 3);
        }
        return $data;
    }

    private static function object(mixed $value, string $path): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new SqlObsReportException('Expected object at ' . $path, 3);
        }
        return $value;
    }

    private static function assertObjectKeys(array $object, array $allowed, string $path): void
    {
        foreach (array_keys($object) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new SqlObsReportException('Unknown key at ' . $path . ': ' . (string)$key, 3);
            }
        }
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $object)) {
                throw new SqlObsReportException('Missing key at ' . $path . ': ' . $key, 3);
            }
        }
    }

    private static function integer(mixed $value, string $path, int $min, int $max): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            throw new SqlObsReportException('Expected integer at ' . $path, 3);
        }
        $int = (int)$value;
        if ($int < $min || $int > $max) {
            throw new SqlObsReportException('Integer out of range at ' . $path, 3);
        }
        return $int;
    }

    private static function safeId(mixed $value, string $path, int $max): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > $max || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/', $value) !== 1) {
            throw new SqlObsReportException('Invalid identifier at ' . $path, 3);
        }
        return $value;
    }

    private static function safeText(mixed $value, string $path, int $max): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1) {
            throw new SqlObsReportException('Invalid text at ' . $path, 3);
        }
        return trim($value);
    }

    private static function stringList(mixed $value, string $path, int $maxItems, int $maxLength): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maxItems) {
            throw new SqlObsReportException('Expected bounded list at ' . $path, 3);
        }
        $out = [];
        foreach ($value as $index => $item) {
            $out[] = self::safeId($item, $path . '[' . $index . ']', $maxLength);
        }
        return self::uniqueSorted($out);
    }

    private static function stringListLoose(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item) && trim((string)$item) !== '') {
                $out[] = self::safeIdentifierValue((string)$item);
            }
        }
        return self::uniqueSorted($out);
    }

    private static function safeRelativePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || preg_match('/(^|\/)\.\.(\/|$)/', $path) === 1 || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return false;
        }
        return preg_match('/^[A-Za-z0-9._\/-]+$/', $path) === 1;
    }

    private static function underRoot(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        return $path === $root || str_starts_with($path, $root . '/');
    }

    private static function displayPath(string $path, string $root): string
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (self::underRoot($path, $root)) {
            $relative = ltrim(substr($path, strlen($root)), '/');
            return $relative === '' ? basename($path) : $relative;
        }
        return basename($path);
    }

    private static function sanitizeRecursive(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match(self::SENSITIVE_KEY, $key) === 1) {
            return '[REDACTED]';
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $name = is_string($k) ? $k : null;
                if ($name !== null && preg_match(self::SENSITIVE_KEY, $name) === 1) {
                    continue;
                }
                $out[$k] = self::sanitizeRecursive($v, $name);
            }
            return $out;
        }
        if (is_string($value)) {
            return self::sanitizeText($value, 2000);
        }
        if (is_float($value) && (!is_finite($value))) {
            return null;
        }
        return $value;
    }

    private static function sanitizeText(string $value, int $max): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $value) ?? '';
        $value = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[REDACTED_EMAIL]', $value) ?? $value;
        $value = preg_replace('#\b(?:mysql|pgsql|postgres|sqlsrv):[^\s]+#i', '[REDACTED_DSN]', $value) ?? $value;
        $value = preg_replace('/\b(?:password|passwd|secret|token|api[_-]?key|authorization)\s*[:=]\s*[^\s,;]+/i', '$1=[REDACTED]', $value) ?? $value;
        $value = preg_replace('#(?<![A-Za-z0-9_.-])/(?:home|Users|workspace|mnt|tmp|var)/[^\s`"\']+#', '[REDACTED_PATH]', $value) ?? $value;
        return substr(trim($value), 0, $max);
    }

    private static function sanitizeSql(string $sql): string
    {
        $sql = self::sanitizeText($sql, 1200);
        $sql = preg_replace("/'(?:''|[^'])*'/", '?', $sql) ?? $sql;
        $sql = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;
        return substr(trim($sql), 0, 500);
    }

    private static function safeIdentifierValue(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._:-]+/', '-', $value) ?? '';
        return substr(trim($value, '-'), 0, 160);
    }

    private static function dominantStatus(array $values, array $priority): string
    {
        $values = self::uniqueSorted(array_filter(array_map('strval', $values), static fn(string $v): bool => $v !== ''));
        foreach ($priority as $status) {
            if (in_array($status, $values, true)) {
                return $status;
            }
        }
        return $values[0] ?? 'unknown';
    }

    private static function median(array $values): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }
        $middle = intdiv($count, 2);
        return $count % 2 === 1 ? (float)$values[$middle] : ((float)$values[$middle - 1] + (float)$values[$middle]) / 2;
    }

    private static function number(float|int $value): float|int
    {
        $rounded = round((float)$value, 3);
        return abs($rounded - round($rounded)) < 0.0005 ? (int)round($rounded) : $rounded;
    }

    private static function uniqueSorted(array $values): array
    {
        $values = array_values(array_unique(array_map('strval', $values)));
        sort($values, SORT_STRING);
        return $values;
    }

    private static function normalizeRows(array $rows, array $keys): array
    {
        $rows = array_values(array_filter($rows, 'is_array'));
        usort($rows, static function (array $a, array $b) use ($keys): int {
            foreach ($keys as $key) {
                $cmp = strcmp((string)($a[$key] ?? ''), (string)($b[$key] ?? ''));
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            return strcmp(self::canonicalJson($a), self::canonicalJson($b));
        });
        return $rows;
    }

    private static function canonicalJson(mixed $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (array_is_list($item)) {
                return array_map($normalize, $item);
            }
            ksort($item, SORT_STRING);
            foreach ($item as $key => $value) {
                $item[$key] = $normalize($value);
            }
            return $item;
        };
        return json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function assertUtcTime(string $value, string $field): void
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if (!$dt || $dt->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new SqlObsReportException('Invalid UTC timestamp: ' . $field, 3);
        }
    }

    private static function md(mixed $value): string
    {
        return str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], self::sanitizeText((string)$value, 1000));
    }

    private static function mdCode(string $value): string
    {
        return str_replace('```', '``\\`', self::sanitizeText($value, 6000));
    }
}
