<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Gate;

final class MysqlQueryGateStabilityEvaluator
{
    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<int,array<string,mixed>> $evidenceRuns
     * @param array<string,mixed> $gate
     * @param array<string,string> $typeOverrides
     * @return array{findings:array<int,array<string,mixed>>,summary:array<string,mixed>}
     */
    public static function evaluate(array $findings, array $evidenceRuns, array $gate, array $typeOverrides = []): array
    {
        $groups = [];
        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $groups[(string)($finding['finding_id'] ?? '')][] = $finding;
        }
        ksort($groups, SORT_STRING);
        $out = [];
        $counts = [
            'confirmed' => 0,
            'pending_stability' => 0,
            'unstable' => 0,
            'insufficient_runs' => 0,
            'insufficient_samples' => 0,
            'incompatible_evidence' => 0,
            'not_required' => 0,
        ];

        foreach ($groups as $findingId => $observations) {
            usort($observations, static fn(array $a, array $b): int => [
                (string)($a['evidence']['generated_at'] ?? ''),
                (string)($a['evidence']['artifact_hash'] ?? ''),
            ] <=> [
                (string)($b['evidence']['generated_at'] ?? ''),
                (string)($b['evidence']['artifact_hash'] ?? ''),
            ]);
            $canonical = $observations[count($observations) - 1];
            $type = (string)($typeOverrides[$findingId] ?? $canonical['stability_type'] ?? 'none');
            if ($type === 'auto') {
                $type = (string)($canonical['stability_type'] ?? 'none');
            }
            if (!in_array($type, ['temporal', 'structural', 'none'], true)) {
                $type = 'none';
            }
            $canonical['stability_type'] = $type;

            if ($type === 'none') {
                $canonical['stability_status'] = 'not_required';
                $canonical['stability'] = [
                    'type' => 'none',
                    'runs_observed' => count($evidenceRuns),
                    'confirmations' => count($observations),
                    'required_runs' => 0,
                    'required_confirmations' => 0,
                    'minimum_sample_count' => 0,
                    'maximum_age_hours' => 0,
                    'reason' => 'stability_not_required',
                ];
                $counts['not_required']++;
                $out[] = $canonical;
                continue;
            }

            $spec = is_array($gate['stability'][$type] ?? null) ? $gate['stability'][$type] : [];
            $requiredRuns = max(1, (int)($spec['required_runs'] ?? ($type === 'temporal' ? 3 : 1)));
            $requiredConfirmations = max(1, min($requiredRuns, (int)($spec['required_confirmations'] ?? ($type === 'temporal' ? 2 : 1))));
            $minimumSamples = max(0, (int)($spec['minimum_sample_count'] ?? ($type === 'temporal' ? 20 : 0)));
            $maximumAgeHours = max(1, min(720, (int)($spec['maximum_age_hours'] ?? 168)));

            $contextSignatures = [];
            foreach ($observations as $observation) {
                $contextSignatures[self::contextSignature((array)($observation['evidence'] ?? []), $type)] = true;
            }
            $canonicalEvidence = (array)($canonical['evidence'] ?? []);
            $canonicalSignature = self::contextSignature($canonicalEvidence, $type);
            $validRuns = self::matchingRuns($evidenceRuns, $canonicalEvidence, $type, $maximumAgeHours);
            $confirmationArtifacts = [];
            $samplesOk = true;
            $sampleCounts = [];
            foreach ($observations as $observation) {
                $evidence = (array)($observation['evidence'] ?? []);
                if (self::contextSignature($evidence, $type) !== $canonicalSignature) {
                    continue;
                }
                $hash = (string)($evidence['artifact_hash'] ?? '');
                if ($hash === '') {
                    $hash = 'observation:' . (string)($evidence['run_id'] ?? '') . ':' . count($confirmationArtifacts);
                }
                $confirmationArtifacts[$hash] = true;
                $sample = is_numeric($evidence['sample_count'] ?? null) ? max(0, (int)$evidence['sample_count']) : 0;
                $sampleCounts[$hash] = $sample;
                if ($minimumSamples > 0 && $sample < $minimumSamples) {
                    $samplesOk = false;
                }
            }
            $confirmations = count($confirmationArtifacts);
            $runsObserved = count($validRuns);

            $status = 'confirmed';
            $reason = 'stability_confirmed';
            if (count($contextSignatures) > 1 || ($type === 'temporal' && !self::timingCompatible($canonicalEvidence))) {
                $status = 'incompatible_evidence';
                $reason = count($contextSignatures) > 1 ? 'evidence_context_mismatch' : 'timing_context_not_full';
            } elseif (!$samplesOk) {
                $status = 'insufficient_samples';
                $reason = 'minimum_sample_count_not_met';
            } elseif ($runsObserved < $requiredRuns) {
                $status = 'insufficient_runs';
                $reason = 'required_runs_not_met';
            } elseif ($confirmations < $requiredConfirmations) {
                $status = 'unstable';
                $reason = 'required_confirmations_not_met';
            }

            $canonical['stability_status'] = $status;
            $canonical['stability'] = [
                'type' => $type,
                'runs_observed' => $runsObserved,
                'confirmations' => $confirmations,
                'required_runs' => $requiredRuns,
                'required_confirmations' => $requiredConfirmations,
                'minimum_sample_count' => $minimumSamples,
                'sample_counts' => array_values($sampleCounts),
                'maximum_age_hours' => $maximumAgeHours,
                'reason' => $reason,
                'context_signature' => substr($canonicalSignature, 0, 24),
                'artifact_hashes' => array_values(array_keys($confirmationArtifacts)),
            ];
            if ($status === 'confirmed') {
                $counts['confirmed']++;
            } elseif ($status === 'insufficient_runs') {
                $counts['insufficient_runs']++;
                $counts['pending_stability']++;
            } elseif ($status === 'unstable') {
                $counts['unstable']++;
                $counts['pending_stability']++;
            } elseif ($status === 'insufficient_samples') {
                $counts['insufficient_samples']++;
                $counts['pending_stability']++;
            } else {
                $counts['incompatible_evidence']++;
            }
            $out[] = $canonical;
        }

        usort($out, static fn(array $a, array $b): int => strcmp((string)$a['finding_id'], (string)$b['finding_id']));
        return [
            'findings' => $out,
            'summary' => $counts + [
                'evidence_runs' => count($evidenceRuns),
            ],
        ];
    }

    /** @param array<string,mixed> $evidence */
    private static function contextSignature(array $evidence, string $type): string
    {
        $parts = [
            'baseline=' . (string)($evidence['baseline_hash'] ?? ''),
            'policy=' . (string)($evidence['policy_hash'] ?? ''),
            'dataset=' . (string)($evidence['dataset_id'] ?? ''),
            'dataset_version=' . (string)($evidence['dataset_version'] ?? ''),
            'dataset_hash=' . (string)($evidence['dataset_hash'] ?? ''),
            'environment=' . (string)($evidence['environment_id'] ?? ''),
            'suite=' . (string)($evidence['suite_id'] ?? ''),
        ];
        if ($type === 'structural') {
            // Environment may differ for structural evidence, but dataset and baseline must not.
            $parts[5] = 'environment=*';
        }
        return hash('sha256', implode('|', $parts));
    }

    /** @param array<int,array<string,mixed>> $runs @param array<string,mixed> $canonical @return array<int,array<string,mixed>> */
    private static function matchingRuns(array $runs, array $canonical, string $type, int $maximumAgeHours): array
    {
        $signature = self::contextSignature($canonical, $type);
        $cutoff = time() - ($maximumAgeHours * 3600);
        $out = [];
        foreach ($runs as $run) {
            if (!is_array($run)) {
                continue;
            }
            if (self::contextSignature($run, $type) !== $signature) {
                continue;
            }
            $time = strtotime((string)($run['generated_at'] ?? ''));
            if ($time !== false && $time < $cutoff) {
                continue;
            }
            if ($type === 'temporal' && !self::timingCompatible($run)) {
                continue;
            }
            $key = (string)($run['artifact_hash'] ?? '') . '|' . (string)($run['run_id'] ?? '');
            $out[$key] = $run;
        }
        return array_values($out);
    }

    /** @param array<string,mixed> $evidence */
    private static function timingCompatible(array $evidence): bool
    {
        $source = (string)($evidence['source'] ?? '');
        if ($source === 'profile' || ($evidence['compatibility_status'] ?? '') === 'profile_only') {
            return true;
        }
        return (bool)($evidence['timing_comparable'] ?? false)
            && (string)($evidence['comparison_scope'] ?? '') === 'full'
            && in_array((string)($evidence['compatibility_status'] ?? ''), ['compatible', 'compatible_with_warnings'], true);
    }
}
