<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

use Testkit\Core\Common\Paths;

final class AgentRunArtifact
{
    /**
     * @param array<string,mixed> $decision
     * @param array<string,mixed> $execution
     * @return array<string,mixed>
     */
    public static function record(array $decision, array $execution): array
    {
        $reportRoot = trim((string)($decision['report_root'] ?? ''));
        if ($reportRoot === '') {
            $reportRoot = Paths::reportsRoot();
        }
        $reportRoot = Paths::normalize($reportRoot);
        $artifactRoot = $reportRoot . '/agent_runs';
        Paths::ensureDir($artifactRoot);

        $timestamp = gmdate('Ymd_His');
        $runId = trim((string)($decision['run_id'] ?? ''));

        $latestPath = $artifactRoot . '/agent_run_execute_latest.json';
        $timestampedPath = $artifactRoot . '/agent_run_execute_' . $timestamp . '.json';

        $payload = [
            'artifact_contract_version' => 2,
            'artifact_kind' => 'agent_run_execute',
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'run_id' => $runId,
            'report_root' => $reportRoot,
            'report_scope_rel' => Paths::relativeToRepo($reportRoot),
            'agent_decision' => $decision['agent_decision'] ?? null,
            'next_action' => $decision['next_action'] ?? null,
            'decision_basis' => $decision['decision_basis'] ?? null,
            'decision' => $decision,
            'execution' => $execution,
            'artifact_paths' => [
                'latest' => Paths::relativeToRepo($latestPath),
                'timestamped' => Paths::relativeToRepo($timestampedPath),
            ],
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException('no se pudo serializar agent-run artifact');
        }

        self::writeFileAtomic($latestPath, $json . PHP_EOL);
        self::writeFileAtomic($timestampedPath, $json . PHP_EOL);

        return [
            'recorded' => true,
            'artifact_kind' => 'agent_run_execute',
            'artifact_contract_version' => 2,
            'artifact_root' => Paths::relativeToRepo($artifactRoot),
            'latest_path' => Paths::relativeToRepo($latestPath),
            'timestamped_path' => Paths::relativeToRepo($timestampedPath),
            'generated_at' => $payload['generated_at'],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function loadLatestForRunRoot(string $reportRoot): ?array
    {
        $path = rtrim(Paths::normalize($reportRoot), '/\\') . '/agent_runs/agent_run_execute_latest.json';
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return null;
        }

        $json['_source_file'] = $path;
        return $json;
    }

    private static function writeFileAtomic(string $path, string $contents): void
    {
        $dir = dirname($path);
        Paths::ensureDir($dir);

        $tmp = tempnam($dir, basename($path) . '.tmp.');
        if (!is_string($tmp) || $tmp === '') {
            file_put_contents($path, $contents, LOCK_EX);
            return;
        }

        file_put_contents($tmp, $contents, LOCK_EX);
        if (!@rename($tmp, $path)) {
            @copy($tmp, $path);
            @unlink($tmp);
        }
    }
}
