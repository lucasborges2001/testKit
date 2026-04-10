<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Testkit\Core\Common\Paths;

final class AgentRun
{
    /**
     * @param array<int,string> $argv
     */
    public static function runCli(array $argv): int
    {
        [$options, $positionals] = self::parseArgs($argv);
        if ((bool)($options['help'] ?? false)) {
            self::printHelp();
            return 0;
        }

        if ($positionals !== []) {
            $first = strtolower(trim((string)$positionals[0]));
            if (in_array($first, ['help', '--help', '-h'], true)) {
                self::printHelp();
                return 0;
            }
            if ($first === 'execute') {
                $options['execute'] = true;
                $positionals = array_values(array_slice($positionals, 1));
            }
        }

        try {
            $decision = self::buildAgentDecision(
                trim((string)($options['run'] ?? '')),
                trim((string)($options['goal'] ?? ''))
            );
        } catch (\Throwable $e) {
            if ((bool)($options['json'] ?? false)) {
                self::printJson([
                    'ok' => false,
                    'error' => $e->getMessage(),
                ]);
            } else {
                fwrite(STDERR, 'agent-run error: ' . $e->getMessage() . PHP_EOL);
            }
            return 2;
        }

        if ((bool)($options['execute'] ?? false)) {
            $execution = AgentRunExecute::execute($decision);
            $artifact = [
                'recorded' => false,
                'error' => null,
            ];

            try {
                $artifact = AgentRunArtifact::record($decision, $execution);
            } catch (\Throwable $e) {
                $artifact = [
                    'recorded' => false,
                    'error' => $e->getMessage(),
                ];
            }

            $payload = [
                'ok' => true,
                'mode' => 'execute',
                'decision' => $decision,
                'execution' => $execution,
                'artifact' => $artifact,
            ];

            if ((bool)($options['json'] ?? false)) {
                self::printJson($payload);
            } else {
                self::printExecuteText($payload);
            }

            $exitCode = AgentRunExecute::exitCode($execution);
            if (!(bool)($artifact['recorded'] ?? false) && $exitCode === 0) {
                return 2;
            }

            return $exitCode;
        }

        if ((bool)($options['json'] ?? false)) {
            self::printJson($decision);
            return 0;
        }

        self::printText($decision);
        return 0;
    }

    /**
     * @param array<int,string> $argv
     * @return array{0:array<string,mixed>,1:array<int,string>}
     */
    private static function parseArgs(array $argv): array
    {
        $args = array_values(array_slice($argv, 1));
        $options = [
            'json' => false,
            'run' => '',
            'goal' => '',
            'help' => false,
            'execute' => false,
        ];
        $positionals = [];

        foreach ($args as $arg) {
            if ($arg === '--json') {
                $options['json'] = true;
                continue;
            }
            if ($arg === '--help' || $arg === '-h') {
                $options['help'] = true;
                continue;
            }
            if ($arg === '--execute') {
                $options['execute'] = true;
                continue;
            }
            if (str_starts_with($arg, '--run=')) {
                $options['run'] = substr($arg, strlen('--run='));
                continue;
            }
            if (str_starts_with($arg, '--goal=')) {
                $options['goal'] = substr($arg, strlen('--goal='));
                continue;
            }
            $positionals[] = $arg;
        }

        return [$options, $positionals];
    }

    /**
     * @return array<string,mixed>
     */
    private static function buildAgentDecision(string $requestedRunId = '', string $goal = ''): array
    {
        $context = self::resolveRunContext($requestedRunId);
        $meta = self::loadMetaReport($context['report_root']);
        $suiteReports = self::loadCanonicalSuiteReports($context['report_root']);
        self::assertCanonicalAgentContext($meta, $suiteReports, $context['report_root']);

        $selection = self::deriveSelection($meta, $suiteReports);
        $finalStatus = self::deriveFinalStatus($meta, $suiteReports);
        $evidenceValid = self::deriveEvidenceValidity($meta, $suiteReports);
        $evidenceInvalidReason = self::deriveEvidenceInvalidReason($meta, $suiteReports);
        $firstFailure = self::deriveFirstFailure($meta, $suiteReports);
        $nextAction = self::nextAction(
            $context['run_id'],
            $finalStatus,
            $evidenceValid,
            $evidenceInvalidReason,
            $firstFailure,
            $selection,
            $suiteReports
        );

        return [
            'ok' => true,
            'agent_contract' => 'deterministic_v1',
            'goal' => $goal,
            'run_id' => $context['run_id'],
            'report_root' => $context['report_root'],
            'report_scope_rel' => $context['report_scope_rel'],
            'final_status' => $finalStatus,
            'evidence_valid' => $evidenceValid,
            'evidence_invalid_reason' => $evidenceInvalidReason,
            'selection' => $selection,
            'first_failure' => $firstFailure,
            'next_action' => $nextAction,
            'decision_basis' => [
                'uses_canonical_report_only' => true,
                'rules' => [
                    'invalid_evidence_then_inspect_concurrency',
                    'first_failure_then_rerun_single_file',
                    'pass_then_stop',
                    'no_tests_then_refine_selection',
                    'otherwise_inspect_failure',
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function nextAction(
        string $runId,
        string $finalStatus,
        bool $evidenceValid,
        ?string $evidenceInvalidReason,
        ?array $firstFailure,
        array $selection,
        array $suiteReports
    ): array {
        if (!$evidenceValid) {
            return [
                'kind' => 'inspect_concurrency',
                'reason' => 'evidence_invalid' . ($evidenceInvalidReason !== null && $evidenceInvalidReason !== '' ? ':' . $evidenceInvalidReason : ''),
                'command' => 'php scripts/inspect.php concurrency --run=' . $runId . ' --json',
                'target' => $runId,
            ];
        }

        if (is_array($firstFailure) && trim((string)($firstFailure['file'] ?? '')) !== '') {
            $suiteId = self::selectionPrimarySuiteId($selection, $suiteReports);
            $suiteTarget = $suiteId !== '' ? str_replace('_', '-', $suiteId) : 'all';
            $file = trim((string)($firstFailure['file'] ?? ''));

            return [
                'kind' => 'rerun_single_file',
                'reason' => 'first_actionable_failure',
                'target' => $file,
                'suite_id' => $suiteId,
                'command' => 'TEST_MATCH=' . self::shellQuote($file) . ' php runTest.php ' . $suiteTarget,
            ];
        }

        if ($finalStatus === 'PASS') {
            return [
                'kind' => 'stop',
                'reason' => 'valid_evidence_no_failures',
                'target' => null,
                'command' => null,
            ];
        }

        if ($finalStatus === 'NO_TESTS') {
            return [
                'kind' => 'refine_selection',
                'reason' => 'selection_empty',
                'target' => $selection['match'] !== '' ? $selection['match'] : ($selection['selected_module_scope'] !== '' ? $selection['selected_module_scope'] : null),
                'command' => 'php scripts/inspect.php latest --run=' . $runId . ' --json',
            ];
        }

        if ($finalStatus === 'LISTED') {
            return [
                'kind' => 'run_selected_tests',
                'reason' => 'selection_only_listed',
                'target' => null,
                'command' => 'php runTest.php ' . self::selectionTargetHint($selection, $suiteReports),
            ];
        }

        return [
            'kind' => 'inspect_failure',
            'reason' => 'failure_without_actionable_first_failure',
            'target' => $runId,
            'command' => 'php scripts/inspect.php failure --run=' . $runId . ' --json',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function deriveSelection(?array $meta, array $suiteReports): array
    {
        $suiteIds = [];
        $selectedTestCount = 0;
        $match = '';
        $scope = '';
        $category = '';
        $moduleScope = '';
        $target = '';

        if (is_array($meta)) {
            $canonical = self::requireCanonicalReport($meta, 'meta report');
            $selection = is_array($canonical['selection'] ?? null) ? $canonical['selection'] : [];
            $target = (string)($selection['target'] ?? '');
            $match = (string)($selection['match'] ?? '');
            $scope = (string)($selection['scope'] ?? '');
            $category = (string)($selection['category'] ?? '');
            $moduleScope = (string)($selection['selected_module_scope'] ?? '');
            $selectedTestCount = (int)($selection['selected_test_count'] ?? 0);
        }

        foreach ($suiteReports as $report) {
            $canonical = self::requireCanonicalReport($report, 'suite report');
            $selection = is_array($canonical['selection'] ?? null) ? $canonical['selection'] : [];
            $suiteId = trim((string)($selection['suite_id'] ?? $report['suite_id'] ?? ''));
            if ($suiteId !== '') {
                $suiteIds[$suiteId] = true;
            }
            if ($selectedTestCount === 0) {
                $selectedTestCount += (int)($selection['selected_test_count'] ?? 0);
            }
            if ($match === '') {
                $match = (string)($selection['match'] ?? '');
            }
            if ($scope === '') {
                $scope = (string)($selection['scope'] ?? '');
            }
            if ($category === '') {
                $category = (string)($selection['category'] ?? '');
            }
            if ($moduleScope === '') {
                $moduleScope = (string)($selection['selected_module_scope'] ?? '');
            }
        }

        return [
            'target' => $target,
            'scope' => $scope,
            'category' => $category,
            'match' => $match,
            'selected_test_count' => $selectedTestCount,
            'selected_module_scope' => $moduleScope,
            'suite_ids' => array_values(array_keys($suiteIds)),
            'primary_suite_id' => count($suiteIds) === 1 ? (string)array_key_first($suiteIds) : '',
        ];
    }

    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     */
    private static function deriveFinalStatus(?array $meta, array $suiteReports): string
    {
        if (is_array($meta)) {
            $canonical = self::requireCanonicalReport($meta, 'meta report');
            return (string)($canonical['final_status'] ?? 'FAIL');
        }

        $hasPass = false;
        $hasFail = false;
        $hasSkip = false;
        $totalSelected = 0;
        foreach ($suiteReports as $report) {
            $canonical = self::requireCanonicalReport($report, 'suite report');
            $status = (string)($canonical['final_status'] ?? 'FAIL');
            $selection = is_array($canonical['selection'] ?? null) ? $canonical['selection'] : [];
            $totalSelected += (int)($selection['selected_test_count'] ?? 0);

            if ($status === 'FAIL') {
                $hasFail = true;
            } elseif ($status === 'PASS') {
                $hasPass = true;
            } elseif ($status === 'SKIP') {
                $hasSkip = true;
            }
        }

        if ($hasFail) {
            return 'FAIL';
        }
        if ($totalSelected === 0) {
            return 'NO_TESTS';
        }
        if ($hasPass && !$hasSkip) {
            return 'PASS';
        }
        if ($hasSkip && !$hasPass) {
            return 'SKIP';
        }
        if ($hasPass && $hasSkip) {
            return 'PARTIAL';
        }

        return 'FAIL';
    }

    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     */
    private static function deriveEvidenceValidity(?array $meta, array $suiteReports): bool
    {
        if (is_array($meta)) {
            $canonical = self::requireCanonicalReport($meta, 'meta report');
            $evidence = is_array($canonical['evidence'] ?? null) ? $canonical['evidence'] : [];
            return (bool)($evidence['valid'] ?? true);
        }

        foreach ($suiteReports as $report) {
            $canonical = self::requireCanonicalReport($report, 'suite report');
            $evidence = is_array($canonical['evidence'] ?? null) ? $canonical['evidence'] : [];
            if (!(bool)($evidence['valid'] ?? true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     */
    private static function deriveEvidenceInvalidReason(?array $meta, array $suiteReports): ?string
    {
        if (is_array($meta)) {
            $canonical = self::requireCanonicalReport($meta, 'meta report');
            $evidence = is_array($canonical['evidence'] ?? null) ? $canonical['evidence'] : [];
            $reason = $evidence['invalid_reason'] ?? null;
            return is_string($reason) && $reason !== '' ? $reason : null;
        }

        foreach ($suiteReports as $report) {
            $canonical = self::requireCanonicalReport($report, 'suite report');
            $evidence = is_array($canonical['evidence'] ?? null) ? $canonical['evidence'] : [];
            $valid = (bool)($evidence['valid'] ?? true);
            $reason = $evidence['invalid_reason'] ?? null;
            if (!$valid) {
                return is_string($reason) && $reason !== '' ? $reason : null;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     * @return array<string,mixed>|null
     */
    private static function deriveFirstFailure(?array $meta, array $suiteReports): ?array
    {
        if (is_array($meta)) {
            $first = self::firstFailureFromReport($meta);
            if (is_array($first)) {
                return $first;
            }
        }

        foreach ($suiteReports as $report) {
            $first = self::firstFailureFromReport($report);
            if (is_array($first)) {
                return $first;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>|null
     */
    private static function firstFailureFromReport(array $report): ?array
    {
        $canonical = self::requireCanonicalReport($report, 'report');
        $evidence = is_array($canonical['evidence'] ?? null) ? $canonical['evidence'] : [];
        $selection = is_array($canonical['selection'] ?? null) ? $canonical['selection'] : [];
        $artifacts = is_array($canonical['artifacts'] ?? null) ? $canonical['artifacts'] : [];
        $first = $evidence['first_failure'] ?? null;
        if (!is_array($first) || $first === []) {
            return null;
        }

        $first['suite_id'] = (string)($first['suite_id'] ?? $selection['suite_id'] ?? $report['suite_id'] ?? '');
        if (!isset($first['artifact_path']) || trim((string)$first['artifact_path']) === '') {
            $first['artifact_path'] = (string)($artifacts['report_scope_rel'] ?? self::reportArtifactPath($report));
        }
        $first['stack_excerpt'] = self::normalizeStackExcerpt($first['stack_excerpt'] ?? null);
        return $first;
    }

    /**
     * @return array<string,mixed>
     */
    private static function resolveRunContext(string $requestedRunId = ''): array
    {
        $requestedRunId = trim($requestedRunId);
        if ($requestedRunId !== '') {
            $root = Paths::reportRunRoot($requestedRunId);
            if (!is_dir($root)) {
                throw new \RuntimeException('run id no encontrado: ' . $requestedRunId);
            }

            return [
                'run_id' => $requestedRunId,
                'report_root' => Paths::normalize($root),
                'report_scope_rel' => Paths::relativeToRepo($root),
            ];
        }

        $latestManifest = self::loadLatestRunManifest();
        if (is_array($latestManifest)) {
            $root = trim((string)($latestManifest['report_root'] ?? ''));
            if ($root !== '' && is_dir($root)) {
                return [
                    'run_id' => (string)($latestManifest['run_id'] ?? ''),
                    'report_root' => Paths::normalize($root),
                    'report_scope_rel' => (string)($latestManifest['report_scope_rel'] ?? Paths::relativeToRepo($root)),
                ];
            }
        }

        return [
            'run_id' => '',
            'report_root' => Paths::reportsRoot(),
            'report_scope_rel' => Paths::relativeToRepo(Paths::reportsRoot()),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function loadLatestRunManifest(): ?array
    {
        return self::loadJsonFile(Paths::latestRunManifestPath());
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function loadMetaReport(string $reportRoot): ?array
    {
        $candidate = rtrim($reportRoot, '/\\') . '/meta_latest.json';
        $json = self::loadJsonFile($candidate);
        if (!is_array($json)) {
            return null;
        }

        $json['_source_file'] = $candidate;
        return self::assertCanonicalEnvelope($json, $candidate);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function loadCanonicalSuiteReports(string $reportRoot): array
    {
        if (!is_dir($reportRoot)) {
            return [];
        }

        $reports = [];
        foreach (glob(rtrim($reportRoot, '/\\') . '/*_latest.json') ?: [] as $file) {
            $name = basename($file);
            if ($name === 'meta_latest.json') {
                continue;
            }
            if (str_contains($name, '__')) {
                continue;
            }

            $json = self::loadJsonFile($file);
            if (!is_array($json) || !isset($json['suite_id'])) {
                continue;
            }

            $json['_source_file'] = $file;
            $reports[] = self::assertCanonicalEnvelope($json, $file);
        }

        usort($reports, static fn(array $a, array $b): int => strcmp((string)($a['suite_id'] ?? ''), (string)($b['suite_id'] ?? '')));
        return $reports;
    }

    /**
     * @param array<string,mixed>|null $meta
     * @param array<int,array<string,mixed>> $suiteReports
     */
    private static function assertCanonicalAgentContext(?array $meta, array $suiteReports, string $reportRoot): void
    {
        if (is_array($meta) || $suiteReports !== []) {
            return;
        }

        throw new \RuntimeException(
            'agent-run no encontró reportes canónicos en ' . Paths::relativeToRepo($reportRoot) .
            '. Este slice no soporta fallback legacy: corré un run generado con slices 01-04 aplicados.'
        );
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private static function requireCanonicalReport(array $report, string $context): array
    {
        $canonical = $report['canonical_report'] ?? null;
        if (!is_array($canonical)) {
            throw new \RuntimeException($context . ' no contiene canonical_report');
        }
        if (!array_key_exists('report_version', $canonical)) {
            throw new \RuntimeException($context . ' tiene canonical_report sin report_version');
        }
        return $canonical;
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private static function assertCanonicalEnvelope(array $report, string $path): array
    {
        $canonical = $report['canonical_report'] ?? null;
        if (!is_array($canonical)) {
            throw new \RuntimeException(
                'agent-run encontró un reporte sin canonical_report en ' . Paths::relativeToRepo($path) .
                '. Mezclar runs nuevos con JSON legacy deja de estar soportado en este slice.'
            );
        }
        if (!array_key_exists('report_version', $canonical)) {
            throw new \RuntimeException(
                'agent-run encontró canonical_report incompleto en ' . Paths::relativeToRepo($path) .
                ' (falta report_version).'
            );
        }
        return $report;
    }

    /**
     * @param array<string,mixed> $selection
     * @param array<int,array<string,mixed>> $suiteReports
     */
    private static function selectionPrimarySuiteId(array $selection, array $suiteReports): string
    {
        $primary = trim((string)($selection['primary_suite_id'] ?? ''));
        if ($primary !== '') {
            return $primary;
        }

        foreach ($suiteReports as $report) {
            $canonical = self::requireCanonicalReport($report, 'suite report');
            $suiteSelection = is_array($canonical['selection'] ?? null) ? $canonical['selection'] : [];
            $suiteId = trim((string)($suiteSelection['suite_id'] ?? $report['suite_id'] ?? ''));
            if ($suiteId !== '') {
                return $suiteId;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $selection
     * @param array<int,array<string,mixed>> $suiteReports
     */
    private static function selectionTargetHint(array $selection, array $suiteReports): string
    {
        $target = trim((string)($selection['target'] ?? ''));
        if ($target !== '') {
            return $target;
        }

        $suiteId = self::selectionPrimarySuiteId($selection, $suiteReports);
        if ($suiteId !== '') {
            return str_replace('_', '-', $suiteId);
        }

        return 'all';
    }

    /**
     * @param array<string,mixed> $report
     */
    private static function reportArtifactPath(array $report): string
    {
        $source = trim((string)($report['_source_file'] ?? ''));
        if ($source !== '') {
            return Paths::relativeToRepo($source);
        }

        $canonical = self::requireCanonicalReport($report, 'report');
        $artifacts = is_array($canonical['artifacts'] ?? null) ? $canonical['artifacts'] : [];
        $root = trim((string)($artifacts['report_scope_rel'] ?? $report['report_scope_rel'] ?? $report['report_root'] ?? ''));
        return $root;
    }

    /**
     * @return array<int,string>
     */
    private static function normalizeStackExcerpt(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(static fn(mixed $line): string => trim((string)$line), $value), static fn(string $line): bool => $line !== ''));
        }

        $text = trim((string)$value);
        if ($text === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $lines = array_values(array_filter(array_map(static fn(string $line): string => trim($line), $lines), static fn(string $line): bool => $line !== ''));
        return array_slice($lines, 0, 8);
    }

    private static function shellQuote(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function printJson(array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException('no se pudo serializar la salida JSON de agent-run');
        }

        echo $json . PHP_EOL;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function printText(array $payload): void
    {
        echo 'agent-run' . PHP_EOL;
        echo str_repeat('=', 72) . PHP_EOL;
        echo 'run_id: ' . (string)($payload['run_id'] ?? '') . PHP_EOL;
        echo 'goal: ' . (string)($payload['goal'] ?? '') . PHP_EOL;
        echo 'final_status: ' . (string)($payload['final_status'] ?? '') . PHP_EOL;
        echo 'evidence_valid: ' . ((bool)($payload['evidence_valid'] ?? true) ? 'true' : 'false') . PHP_EOL;
        if ((string)($payload['evidence_invalid_reason'] ?? '') !== '') {
            echo 'evidence_invalid_reason: ' . (string)$payload['evidence_invalid_reason'] . PHP_EOL;
        }

        $selection = is_array($payload['selection'] ?? null) ? $payload['selection'] : [];
        echo 'selection: tests=' . (int)($selection['selected_test_count'] ?? 0)
            . ' scope=' . (string)($selection['scope'] ?? '')
            . ' category=' . (string)($selection['category'] ?? '')
            . ' match=' . (string)($selection['match'] ?? '')
            . PHP_EOL;

        $firstFailure = $payload['first_failure'] ?? null;
        if (is_array($firstFailure)) {
            echo 'first_failure: ' . (string)($firstFailure['file'] ?? '') . ' -> ' . (string)($firstFailure['message'] ?? '') . PHP_EOL;
        } else {
            echo 'first_failure: none' . PHP_EOL;
        }

        $nextAction = is_array($payload['next_action'] ?? null) ? $payload['next_action'] : [];
        echo 'next_action: ' . (string)($nextAction['kind'] ?? '') . PHP_EOL;
        echo 'reason: ' . (string)($nextAction['reason'] ?? '') . PHP_EOL;
        if ((string)($nextAction['command'] ?? '') !== '') {
            echo 'command: ' . (string)$nextAction['command'] . PHP_EOL;
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function printExecuteText(array $payload): void
    {
        $decision = is_array($payload['decision'] ?? null) ? $payload['decision'] : [];
        $execution = is_array($payload['execution'] ?? null) ? $payload['execution'] : [];
        self::printText($decision);
        echo PHP_EOL;
        echo 'execution' . PHP_EOL;
        echo str_repeat('-', 72) . PHP_EOL;
        echo 'executed: ' . ((bool)($execution['executed'] ?? false) ? 'true' : 'false') . PHP_EOL;
        echo 'kind: ' . (string)($execution['kind'] ?? '') . PHP_EOL;
        echo 'reason: ' . (string)($execution['reason'] ?? '') . PHP_EOL;

        $command = is_array($execution['command'] ?? null) ? $execution['command'] : [];
        if ((string)($command['display'] ?? '') !== '') {
            echo 'command: ' . (string)$command['display'] . PHP_EOL;
        }
        $envOverrides = is_array($command['env_overrides'] ?? null) ? $command['env_overrides'] : [];
        if ($envOverrides !== []) {
            echo 'env_overrides: ' . json_encode($envOverrides, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }

        $result = is_array($execution['result'] ?? null) ? $execution['result'] : [];
        echo 'exit_code: ' . (int)($result['exit_code'] ?? 0) . PHP_EOL;
        echo 'duration_ms: ' . (int)($result['duration_ms'] ?? 0) . PHP_EOL;

        $artifact = is_array($payload['artifact'] ?? null) ? $payload['artifact'] : [];
        echo 'artifact_recorded: ' . ((bool)($artifact['recorded'] ?? false) ? 'true' : 'false') . PHP_EOL;
        if ((string)($artifact['latest_path'] ?? '') !== '') {
            echo 'artifact_latest: ' . (string)$artifact['latest_path'] . PHP_EOL;
        }
        if ((string)($artifact['timestamped_path'] ?? '') !== '') {
            echo 'artifact_timestamped: ' . (string)$artifact['timestamped_path'] . PHP_EOL;
        }
        if ((string)($artifact['error'] ?? '') !== '') {
            echo 'artifact_error: ' . (string)$artifact['error'] . PHP_EOL;
        }

        if ((string)($result['stderr_excerpt'] ?? '') !== '') {
            echo 'stderr_excerpt:' . PHP_EOL . (string)$result['stderr_excerpt'] . PHP_EOL;
        }
        if ((string)($result['stdout_excerpt'] ?? '') !== '') {
            echo 'stdout_excerpt:' . PHP_EOL . (string)$result['stdout_excerpt'] . PHP_EOL;
        }
    }

    private static function printHelp(): void
    {
        echo "Usage:\n";
        echo "  php scripts/agent-run.php [--run=<id>] [--goal=<text>] [--json]
  php scripts/agent-run.php execute [--run=<id>] [--goal=<text>] [--json]\n";
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function loadJsonFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $json = json_decode($raw, true);
        return is_array($json) ? $json : null;
    }
}
