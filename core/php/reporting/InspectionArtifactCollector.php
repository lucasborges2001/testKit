<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Testkit\Core\Common\Paths;

final class InspectionArtifactCollector
{
    /** @return array<string,mixed>|null */
    public static function migrationContractReport(string $reportRoot): ?array
    {
        $candidate = rtrim($reportRoot, '/\\') . '/migration_contract_latest.json';
        $json = AgentDecisionBuilder::loadJsonFile($candidate);
        if (!is_array($json)) {
            return null;
        }
        $json['_source_file'] = $candidate;
        return $json;
    }

    /** @return array<int,array<string,mixed>> */
    public static function baselineManifests(): array
    {
        $rows = [];
        foreach (glob(Paths::artifactsRoot() . '/baselines/*/*.manifest.json') ?: [] as $file) {
            $json = AgentDecisionBuilder::loadJsonFile($file);
            if (!is_array($json)) {
                continue;
            }
            $rows[] = [
                'path' => Paths::relativeToRepo($file),
                'status' => (string)($json['status'] ?? ''),
                'driver' => (string)($json['driver'] ?? ''),
                'db_name' => (string)($json['db_name'] ?? ''),
                'baseline_mode' => (string)($json['baseline_mode'] ?? ''),
                'generated_at' => (string)($json['generated_at'] ?? ''),
                'baseline_fingerprint' => (string)($json['baseline_fingerprint'] ?? ''),
                'migration_state' => $json['migration_state'] ?? null,
            ];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string)($a['path'] ?? ''), (string)($b['path'] ?? '')));
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    public static function activeLocks(): array
    {
        $rows = [];
        $lockRoot = Paths::outRoot() . '/locks';
        if (!is_dir($lockRoot)) {
            return [];
        }

        foreach (glob($lockRoot . '/*/owner.json') ?: [] as $ownerFile) {
            $json = AgentDecisionBuilder::loadJsonFile($ownerFile);
            if (!is_array($json)) {
                continue;
            }
            $rows[] = [
                'name' => (string)($json['name'] ?? basename(dirname($ownerFile))),
                'path' => Paths::relativeToRepo(dirname($ownerFile)),
                'run_id' => (string)($json['run_id'] ?? ''),
                'meta_run_id' => (string)($json['meta_run_id'] ?? ''),
                'pid' => $json['pid'] ?? null,
                'hostname' => (string)($json['hostname'] ?? ''),
                'acquired_at' => (string)($json['acquired_at'] ?? ''),
            ];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public static function agentRunArtifact(string $reportRoot): ?array
    {
        $artifact = AgentRunArtifact::loadLatestForRunRoot($reportRoot);
        if (!is_array($artifact)) {
            return null;
        }

        $execution = is_array($artifact['execution'] ?? null) ? $artifact['execution'] : [];
        $result = is_array($execution['result'] ?? null) ? $execution['result'] : [];
        $decision = is_array($artifact['decision'] ?? null) ? $artifact['decision'] : [];

        return [
            'artifact_kind' => (string)($artifact['artifact_kind'] ?? ''),
            'generated_at' => (string)($artifact['generated_at'] ?? ''),
            'source_file' => Paths::relativeToRepo((string)($artifact['_source_file'] ?? '')),
            'latest_path' => (string)($artifact['artifact_paths']['latest'] ?? ''),
            'timestamped_path' => (string)($artifact['artifact_paths']['timestamped'] ?? ''),
            'executed' => (bool)($execution['executed'] ?? false),
            'kind' => (string)($execution['kind'] ?? ($decision['next_action']['kind'] ?? '')),
            'reason' => (string)($execution['reason'] ?? ($decision['next_action']['reason'] ?? '')),
            'exit_code' => (int)($result['exit_code'] ?? 0),
            'agent_decision' => $artifact['agent_decision'] ?? ($decision['agent_decision'] ?? null),
            'next_action' => $decision['next_action'] ?? null,
        ];
    }
}
