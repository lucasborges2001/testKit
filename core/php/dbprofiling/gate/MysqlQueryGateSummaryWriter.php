<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Gate;

final class MysqlQueryGateSummaryWriter
{
    /** @param array<string,mixed> $report */
    public static function render(array $report, int $top = 20): string
    {
        $top = max(1, min(100, $top));
        $summary = (array)($report['summary'] ?? []);
        $decision = (array)($report['decision'] ?? []);
        $inputs = (array)($report['inputs'] ?? []);
        $allowlist = (array)($report['allowlist'] ?? []);
        $outputs = (array)($report['outputs'] ?? []);
        $lines = [
            '# SQL query gate',
            '',
            '| Field | Value |',
            '|---|---|',
            '| Mode | `' . self::md((string)($report['mode'] ?? 'off')) . '` |',
            '| Decision | **' . strtoupper(self::md((string)($decision['status'] ?? 'disabled'))) . '** |',
            '| Exit code | `' . (int)($decision['exit_code'] ?? 0) . '` |',
            '| Blocking | ' . (int)($summary['blocking'] ?? 0) . ' |',
            '| Warnings | ' . (int)($summary['warnings'] ?? 0) . ' |',
            '| Observed | ' . (int)($summary['observed'] ?? 0) . ' |',
            '| Pending stability | ' . (int)($summary['pending_stability'] ?? 0) . ' |',
            '| Suppressed | ' . (int)($summary['suppressed'] ?? 0) . ' |',
            '',
            '## Inputs',
            '',
            '- Profile: `' . self::shortHash((string)($inputs['profile_hash'] ?? '')) . '`',
            '- Policy: `' . self::shortHash((string)($inputs['policy_hash'] ?? '')) . '`',
            '- Baseline: `' . self::shortHash((string)($inputs['baseline_hash'] ?? '')) . '`',
            '- Comparisons: ' . count((array)($inputs['comparison_hashes'] ?? [])),
            '',
            '## Compatibility and stability',
            '',
            '- Evidence runs: ' . (int)($report['stability']['evidence_runs'] ?? 0),
            '- Confirmed: ' . (int)($report['stability']['confirmed'] ?? 0),
            '- Insufficient runs: ' . (int)($report['stability']['insufficient_runs'] ?? 0),
            '- Insufficient samples: ' . (int)($report['stability']['insufficient_samples'] ?? 0),
            '- Incompatible evidence: ' . (int)($report['stability']['incompatible_evidence'] ?? 0),
            '',
        ];

        self::appendFindings($lines, 'Blocking findings', (array)($report['findings'] ?? []), 'block', $top);
        self::appendFindings($lines, 'Warnings', (array)($report['findings'] ?? []), 'warn', $top);
        self::appendPending($lines, (array)($report['findings'] ?? []), $top);

        $lines[] = '## Suppressions';
        $lines[] = '';
        $lines[] = '- Used: ' . count((array)($allowlist['used'] ?? []));
        $lines[] = '- Unused: ' . count((array)($allowlist['unused'] ?? []));
        $lines[] = '- Expired: ' . count((array)($allowlist['expired'] ?? []));
        if ((array)($allowlist['expired'] ?? []) !== []) {
            $lines[] = '- Expired IDs: `' . self::md(implode('`, `', array_slice((array)$allowlist['expired'], 0, 20))) . '`';
        }
        $lines[] = '';
        $lines[] = '## Artifacts';
        $lines[] = '';
        foreach (['json', 'junit', 'sarif', 'summary', 'approval'] as $key) {
            $path = (string)($outputs[$key] ?? '');
            if ($path !== '') {
                $lines[] = '- ' . ucfirst($key) . ': `' . self::md($path) . '`';
            }
        }
        $lines[] = '';
        $lines[] = '## Limitations';
        $lines[] = '';
        $limitations = (array)($report['limitations'] ?? []);
        if ($limitations === []) {
            $lines[] = '- None reported.';
        } else {
            foreach (array_slice($limitations, 0, 20) as $limitation) {
                $lines[] = '- ' . self::md((string)$limitation);
            }
        }
        $lines[] = '';
        return implode("\n", $lines);
    }

    /** @param array<int,string> &$lines @param array<int,array<string,mixed>> $findings */
    private static function appendFindings(array &$lines, string $title, array $findings, string $decision, int $top): void
    {
        $selected = array_values(array_filter($findings, static fn(mixed $finding): bool =>
            is_array($finding) && (string)($finding['decision_effective'] ?? '') === $decision
        ));
        $lines[] = '## ' . $title;
        $lines[] = '';
        if ($selected === []) {
            $lines[] = '- None.';
            $lines[] = '';
            return;
        }
        foreach (array_slice($selected, 0, $top) as $finding) {
            $identity = (string)($finding['query_identity'] ?? 'global');
            $lines[] = '- **' . self::md((string)($finding['category'] ?? 'finding')) . '** — `'
                . self::md($identity) . '` — ' . self::md((string)($finding['message'] ?? ''));
        }
        if (count($selected) > $top) {
            $lines[] = '- … ' . (count($selected) - $top) . ' more.';
        }
        $lines[] = '';
    }

    /** @param array<int,string> &$lines @param array<int,array<string,mixed>> $findings */
    private static function appendPending(array &$lines, array $findings, int $top): void
    {
        $selected = array_values(array_filter($findings, static fn(mixed $finding): bool =>
            is_array($finding) && in_array((string)($finding['stability_status'] ?? ''), ['insufficient_runs', 'insufficient_samples', 'unstable'], true)
        ));
        $lines[] = '## Pending stability';
        $lines[] = '';
        if ($selected === []) {
            $lines[] = '- None.';
            $lines[] = '';
            return;
        }
        foreach (array_slice($selected, 0, $top) as $finding) {
            $stability = (array)($finding['stability'] ?? []);
            $lines[] = '- **' . self::md((string)($finding['category'] ?? 'finding')) . '** — `'
                . self::md((string)($finding['query_identity'] ?? 'global')) . '` — '
                . (int)($stability['confirmations'] ?? 0) . '/' . (int)($stability['required_confirmations'] ?? 0)
                . ' confirmations, ' . (int)($stability['runs_observed'] ?? 0) . '/'
                . (int)($stability['required_runs'] ?? 0) . ' runs.';
        }
        $lines[] = '';
    }

    /** @param array<string,mixed> $report */
    public static function emitGithubAnnotations(array $report, int $max): int
    {
        $max = max(0, min(500, $max));
        if ($max === 0) {
            return 0;
        }
        $count = 0;
        foreach ((array)($report['findings'] ?? []) as $finding) {
            if (!is_array($finding) || $count >= $max) {
                break;
            }
            $decision = (string)($finding['decision_effective'] ?? 'observe');
            if ($decision === 'observe') {
                continue;
            }
            $command = $decision === 'block' ? 'error' : 'warning';
            $location = (array)($finding['location'] ?? []);
            $props = [];
            $path = MysqlQueryGateArtifactWriter::safeRelativePath((string)($location['path'] ?? ''));
            if ($path !== '') {
                $props[] = 'file=' . self::workflow($path);
                $line = $location['line'] ?? null;
                if (is_int($line) && $line > 0) {
                    $props[] = 'line=' . $line;
                }
            }
            $props[] = 'title=' . self::workflow((string)($finding['category'] ?? 'SQL query gate'));
            $message = self::workflow(MysqlQueryGateArtifactWriter::sanitizeText((string)($finding['message'] ?? 'SQL query gate finding.'), 500));
            fwrite(STDOUT, '::' . $command . ($props === [] ? '' : ' ' . implode(',', $props)) . '::' . $message . PHP_EOL);
            $count++;
        }
        return $count;
    }

    public static function appendGithubStepSummary(string $markdown): bool
    {
        if (strtolower((string)getenv('GITHUB_ACTIONS')) !== 'true') {
            return false;
        }
        $path = getenv('GITHUB_STEP_SUMMARY');
        if (!is_string($path) || trim($path) === '' || str_contains($path, "\0") || is_link($path)) {
            return false;
        }
        $dir = realpath(dirname($path));
        if ($dir === false || !is_dir($dir) || !is_writable($dir)) {
            return false;
        }
        return @file_put_contents($path, $markdown . "\n", FILE_APPEND | LOCK_EX) !== false;
    }

    private static function shortHash(string $hash): string
    {
        return preg_match('/^[a-f0-9]{64}$/', $hash) === 1 ? substr($hash, 0, 12) : 'not-provided';
    }

    private static function md(string $value): string
    {
        $value = MysqlQueryGateArtifactWriter::sanitizeText($value, 1000);
        return str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], $value);
    }

    private static function workflow(string $value): string
    {
        return str_replace(
            ['%', "\r", "\n", ':', ','],
            ['%25', '%0D', '%0A', '%3A', '%2C'],
            MysqlQueryGateArtifactWriter::sanitizeText($value, 1000)
        );
    }
}
