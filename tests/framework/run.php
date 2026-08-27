#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/php/common/Env.php';
require_once $root . '/core/php/common/AgentMode.php';
require_once $root . '/core/php/reporting/UI.php';
require_once $root . '/core/php/reporting/CompactBatchReporter.php';

use Testkit\Core\Reporting\CompactBatchReporter;

$tests = [
    'ProcessRunner timeout'                 => __DIR__ . '/test_process_timeout.php',
    'ProcessRunner polling no deadlock'     => __DIR__ . '/test_polling_deadlock.php',
    'ProcessRunner finish no deadlock'      => __DIR__ . '/test_sequential_deadlock.php',
    'ProcessRunner interleaved output'      => __DIR__ . '/test_interleaved_output.php',
    'SuiteExecutor concurrent jobs'         => __DIR__ . '/test_concurrent_jobs.php',
    'Lock stale detection'                  => __DIR__ . '/test_lock_stale.php',
    'Lock valid not broken'                 => __DIR__ . '/test_lock_valid.php',
    'Store resource lock'                   => __DIR__ . '/test_store_resource_lock.php',
    'FrontJsSuite admission/lock order'     => __DIR__ . '/test_front_js_admission_lock_order.php',
    'Failure classification contracts'      => __DIR__ . '/test_failure_classification_contracts.php',
    'Seed state canonical contract'         => __DIR__ . '/test_seed_state_contract.php',
    'Engine support contract'               => __DIR__ . '/test_engine_support_contract.php',
    'Store driver explicit contract'        => __DIR__ . '/test_store_driver_contract.php',
    'No-store contract'                     => __DIR__ . '/test_no_store_contract.php',
    'Wrapper runtime contract'              => __DIR__ . '/test_wrapper_runtime_contract.sh',
    'Core domain boundary'                  => __DIR__ . '/test_core_domain_boundary.php',
    'PLC Modbus read-only profiles'         => __DIR__ . '/test_plc_modbus_readonly_profiles.php',
    'PLC task timing design audit'          => __DIR__ . '/test_plc_task_timing_design_audit.php',
    'Contract registry parity'              => __DIR__ . '/test_contract_registry.php',
    'Strict run request'                    => __DIR__ . '/test_strict_run_request.php',
    'CI typed selector contract'            => __DIR__ . '/test_ci_typed_selectors.php',
    'BackPythonSuite trace coverage'        => __DIR__ . '/test_back_python_trace_coverage_contract.php',
    'Manifest atomic write'                 => __DIR__ . '/test_manifest_write.php',
    'Reporting contract stable'             => __DIR__ . '/test_reporting_contract.php',
    'Exit code v2 contract'                 => __DIR__ . '/test_exit_code_v2_contract.php',
    'Operation result v2 contract'          => __DIR__ . '/test_operation_result_v2_contract.php',
    'Console reporter wrapper commands'     => __DIR__ . '/test_console_reporter_wrapper_commands.php',
    'Suggested command builder invokers'    => __DIR__ . '/test_suggested_command_builder_invokers.php',
    'ConsoleReporter compact pass'          => __DIR__ . '/test_console_reporter_compact_pass.php',
    'Console meta compact pass'             => __DIR__ . '/test_console_meta_compact_pass.php',
    'Console mode contract'                 => __DIR__ . '/test_console_mode_contract.php',
    'Compact batch reporter'                => __DIR__ . '/test_compact_batch_reporter.php',
    'Static checks contract'                => __DIR__ . '/test_static_checks_contract.php',
    'Wrapper compact output contract'       => __DIR__ . '/test_wrapper_compact_output_contract.php',
    'Observability progress policy'         => __DIR__ . '/test_progress_policy.php',
    'Observability console contract'        => __DIR__ . '/test_console_observability_contract.php',
    'Observability execution contract'      => __DIR__ . '/test_execution_observability_contract.php',
    'Bootstrap visual dedupe'               => __DIR__ . '/test_bootstrap_visual_dedupe.php',
    'Meta action required renderer'         => __DIR__ . '/test_meta_action_required_renderer.php',
    'Meta rerun plan fallback'              => __DIR__ . '/test_meta_rerun_plan_fallback.php',
    'Agent mode runtime contract'           => __DIR__ . '/test_agent_mode_contract.php',
    'Command spec contract'                 => __DIR__ . '/test_command_spec_contract.php',
    'Agent command spec admission'          => __DIR__ . '/test_agent_command_spec_admission.php',
    'Agent run continuation contract'       => __DIR__ . '/test_agent_run_contract.php',
    'Agent decision actionable contract'    => __DIR__ . '/test_agent_decision_contract.php',
    'Batch selection match file/list'       => __DIR__ . '/test_selection_match_file.php',
    'Batch isolated rerun contract'         => __DIR__ . '/test_rerun_failed_isolated.php',
    'Batch isolation tags contract'         => __DIR__ . '/test_isolation_tags.php',
    'MySQL query profiling contract'        => __DIR__ . '/test_mysql_query_profiling.php',
    'MySQL query instrumentation contract'  => __DIR__ . '/test_mysql_query_instrumentation.php',
    'MySQL query policy contract'           => __DIR__ . '/test_mysql_query_policy.php',
    'MySQL query baseline contract'         => __DIR__ . '/test_mysql_query_baseline.php',
    'MySQL query gate contract'             => __DIR__ . '/test_mysql_query_gate.php',
    'SQL observability native contract'     => __DIR__ . '/test_sql_observability_native.php',
    'SQL observability public exit code 5'  => __DIR__ . '/test_sql_observability_exit_code_5.php',
    'Declarative suite output contract'     => __DIR__ . '/test_run_suite_config_output_contract.php',
    'Declarative suite compact contract'    => __DIR__ . '/test_run_suite_config_compact_contract.php',
    'Reset CLI contract'                    => __DIR__ . '/test_reset_cli.sh',
];

$started = microtime(true);
$pass = 0;
$fail = 0;
$failures = [];
foreach ($tests as $name => $file) {
    $runner = str_ends_with($file, '.sh') ? 'bash ' : 'php ';
    $rerun = $runner . self_test_relative_path($root, $file);
    if (!is_file($file)) {
        $fail++;
        $failures[] = ['label' => $name, 'exit_code' => 127, 'reason' => 'registered file not found: ' . $file, 'rerun' => $rerun];
        continue;
    }
    $output = [];
    $exitCode = 0;
    exec($runner . escapeshellarg($file) . ' 2>&1', $output, $exitCode);
    if ($exitCode === 0) {
        $pass++;
        continue;
    }
    $fail++;
    $failures[] = ['label' => $name, 'exit_code' => $exitCode, 'output' => implode("\n", $output), 'rerun' => $rerun];
}

$check = [
    'label' => 'Framework tests', 'total' => count($tests), 'passed' => $pass, 'failed' => $fail, 'skipped' => 0,
    'duration_ms' => (int)round((microtime(true) - $started) * 1000), 'failures' => $failures,
];
CompactBatchReporter::printCheck($check);
CompactBatchReporter::printSummary([$check]);
exit($fail > 0 ? 1 : 0);

function self_test_relative_path(string $root, string $path): string
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $path = str_replace('\\', '/', $path);
    $prefix = $root . '/';
    return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
}
