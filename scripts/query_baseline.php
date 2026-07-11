#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/dbprofiling/bootstrap.php';

use Testkit\Core\Common\Paths;
use Testkit\Core\DbProfiling\Baseline\MysqlQueryBaselineBuilder;
use Testkit\Core\DbProfiling\Baseline\MysqlQueryBaselineException;
use Testkit\Core\DbProfiling\Baseline\MysqlQueryBaselineLoader;
use Testkit\Core\DbProfiling\Baseline\MysqlQueryBaselineReporter;
use Testkit\Core\DbProfiling\InstrumentationContext;

try {
    $args = tk_baseline_args($argv);
    if ($args['help']) {
        tk_baseline_help();
        exit(0);
    }
    if ($args['command'] !== 'create') {
        throw new RuntimeException('Expected command: create', 2);
    }
    foreach (['profile', 'output', 'baseline-id', 'dataset-id', 'dataset-version', 'environment-id'] as $required) {
        if ((string)$args[$required] === '') {
            throw new MysqlQueryBaselineException(
                'Missing required option --' . $required,
                '$',
                'baseline_metadata_missing'
            );
        }
    }

    $profilePath = Paths::normalize((string)$args['profile']);
    $outputPath = Paths::normalize((string)$args['output']);
    if (!is_file($profilePath)) {
        throw new RuntimeException('Profile report not found: ' . Paths::relativeToRepo($profilePath), 2);
    }
    if (is_file($outputPath) && !$args['force']) {
        throw new RuntimeException('Output already exists; use --force to replace it.', 2);
    }

    $raw = file_get_contents($profilePath);
    if (!is_string($raw)) {
        throw new RuntimeException('Profile report cannot be read.', 2);
    }
    try {
        $profile = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new MysqlQueryBaselineException(
            'Invalid profile JSON: ' . $e->getMessage(),
            '$',
            'current_profile_incompatible'
        );
    }
    if (!is_array($profile)) {
        throw new MysqlQueryBaselineException(
            'Profile root must be an object.',
            '$',
            'current_profile_incompatible'
        );
    }

    $metadata = [
        'baseline_id' => $args['baseline-id'],
        'description' => $args['description'],
        'repository' => $args['repository'],
        'commit_sha' => $args['commit-sha'],
        'branch' => $args['branch'],
        'engine_version' => $args['engine-version'],
        'engine_version_mode' => $args['engine-version-mode'],
        'dataset_id' => $args['dataset-id'],
        'dataset_version' => $args['dataset-version'],
        'dataset_hash' => $args['dataset-hash'],
        'dataset_hash_mode' => $args['dataset-hash-mode'],
        'environment_id' => $args['environment-id'],
        'suite_id' => $args['suite-id'],
        'suite_scope' => $args['suite-scope'],
        'profile_hash' => hash('sha256', $raw),
    ];
    $baseline = MysqlQueryBaselineBuilder::build($profile, $metadata);
    $baseline = MysqlQueryBaselineLoader::validate($baseline);
    MysqlQueryBaselineReporter::writeJsonAtomic($outputPath, $baseline);
    $finalRaw = (string)file_get_contents($outputPath);
    $hash = hash('sha256', $finalRaw);

    if ($args['format'] === 'json') {
        echo json_encode([
            'status' => 'created',
            'output' => InstrumentationContext::normalizePath($outputPath),
            'sha256' => $hash,
            'baseline' => $baseline,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    } else {
        echo "SQL query baseline created\n";
        echo 'Baseline: ' . (string)$baseline['baseline']['id'] . PHP_EOL;
        echo 'Output: ' . Paths::relativeToRepo($outputPath) . PHP_EOL;
        echo 'Queries: ' . count((array)$baseline['baseline']['queries']) . PHP_EOL;
        echo 'SHA-256: ' . $hash . PHP_EOL;
    }
    exit(0);
} catch (MysqlQueryBaselineException $e) {
    fwrite(
        STDERR,
        'Invalid baseline/profile: '
        . $e->getMessage()
        . ' at '
        . $e->jsonPath()
        . ' ['
        . $e->errorCode()
        . ']'
        . PHP_EOL
    );
    exit($e->errorCode() === 'current_profile_incompatible' ? 4 : 3);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, 'Baseline creation failed: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

/** @param array<int,string> $argv @return array<string,mixed> */
function tk_baseline_args(array $argv): array
{
    $out = [
        'command' => '', 'profile' => '', 'output' => '', 'baseline-id' => '',
        'description' => '', 'repository' => '', 'commit-sha' => '', 'branch' => '',
        'engine-version' => '', 'engine-version-mode' => 'major_minor',
        'dataset-id' => '', 'dataset-version' => '', 'dataset-hash' => '',
        'dataset-hash-mode' => 'exact', 'environment-id' => '', 'suite-id' => '',
        'suite-scope' => 'exact', 'force' => false, 'format' => 'human', 'help' => false,
    ];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = (string)$argv[$i];
        if ($arg === '--help' || $arg === '-h') {
            $out['help'] = true;
            continue;
        }
        if ($arg === '--force') {
            $out['force'] = true;
            continue;
        }
        if ($out['command'] === '' && !str_starts_with($arg, '--')) {
            $out['command'] = $arg;
            continue;
        }
        foreach ([
            'profile', 'output', 'baseline-id', 'description', 'repository',
            'commit-sha', 'branch', 'engine-version', 'engine-version-mode',
            'dataset-id', 'dataset-version', 'dataset-hash', 'dataset-hash-mode',
            'environment-id', 'suite-id', 'suite-scope', 'format',
        ] as $key) {
            $flag = '--' . $key;
            if ($arg === $flag) {
                if (!isset($argv[$i + 1])) {
                    throw new RuntimeException('Missing value for ' . $flag, 2);
                }
                $out[$key] = (string)$argv[++$i];
                continue 2;
            }
            if (str_starts_with($arg, $flag . '=')) {
                $out[$key] = substr($arg, strlen($flag) + 1);
                continue 2;
            }
        }
        throw new RuntimeException('Unknown option: ' . $arg, 2);
    }
    if (!in_array($out['format'], ['human', 'json'], true)) {
        throw new RuntimeException('--format must be human or json.', 2);
    }
    return $out;
}

function tk_baseline_help(): void
{
    echo "MySQL Query Baseline\n\n";
    echo "Usage:\n";
    echo "  php scripts/query_baseline.php create --profile <file> --output <file> [options]\n\n";
    echo "Required:\n";
    echo "  --profile <path>\n";
    echo "  --output <path>\n";
    echo "  --baseline-id <id>\n";
    echo "  --dataset-id <id>\n";
    echo "  --dataset-version <version>\n";
    echo "  --environment-id <id>\n\n";
    echo "Optional:\n";
    echo "  --description <text>\n";
    echo "  --repository <owner/repo>\n";
    echo "  --commit-sha <sha>\n";
    echo "  --branch <branch>\n";
    echo "  --engine-version <version>\n";
    echo "  --engine-version-mode exact|major_minor|major|ignore\n";
    echo "  --dataset-hash <sha256>\n";
    echo "  --dataset-hash-mode exact|warn|ignore\n";
    echo "  --suite-id <id>          Defaults to profile comparison_context/suite_id\n";
    echo "  --suite-scope exact|global\n";
    echo "  --force                  Explicitly replace an existing baseline\n";
    echo "  --format human|json\n";
    echo "  --help\n";
}
