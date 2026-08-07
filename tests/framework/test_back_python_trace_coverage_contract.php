<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Suites\BackPythonSuite;

$errors = [];

function assert_true_pytrace(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

function assert_contains_pytrace(array $values, string $needle, string $message, array &$errors): void
{
    if (!in_array($needle, $values, true)) {
        $errors[] = $message . ': missing ' . $needle;
    }
}

function set_env_pytrace(string $key, ?string $value): void
{
    if ($value === null) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
        return;
    }

    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

function get_env_pytrace(string $key): ?string
{
    $value = getenv($key);
    return $value === false ? null : (string)$value;
}

function find_python_binary_pytrace(): ?string
{
    foreach (['python3', 'python'] as $binary) {
        $cmd = escapeshellcmd($binary) . ' --version 2>&1';
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        if ($exitCode === 0) {
            return $binary;
        }
    }

    return null;
}

/** @return array<int,string> */
function find_cover_files_outside_pytrace(string $root, string $allowedRoot): array
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $allowedRoot = rtrim(str_replace('\\', '/', $allowedRoot), '/') . '/';
    $found = [];

    if (!is_dir($root)) {
        return $found;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        if (!str_ends_with($path, '.cover')) {
            continue;
        }

        if (str_starts_with($path, $allowedRoot)) {
            continue;
        }

        $found[] = ltrim(substr($path, strlen($root)), '/');
    }

    sort($found);
    return $found;
}

function has_cover_file_pytrace(string $root): bool
{
    if (!is_dir($root)) {
        return false;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && str_ends_with(str_replace('\\', '/', $file->getPathname()), '.cover')) {
            return true;
        }
    }

    return false;
}

function remove_tree_pytrace(string $path): void
{
    if ($path === '' || !is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo) {
            continue;
        }

        if ($file->isDir() && !$file->isLink()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }

    @rmdir($path);
}

try {
    $traceFile = '/tmp/testkit-pytrace/.testkit/coverage/back_python/trace_counts.dat';
    $testFile = '/tmp/testkit-pytrace/test/back/ocpp_server/integration/ocpp_trace_artifacts_unittest.py';

    $countMethod = new ReflectionMethod(BackPythonSuite::class, 'buildCoverageCountCommand');
    $countMethod->setAccessible(true);
    $countCmd = $countMethod->invoke(null, 'python3', $traceFile, '/tmp/testkit-pytrace/.testkit/coverage/back_python', $testFile);

    assert_true_pytrace(is_array($countCmd), 'coverage count command should be an array', $errors);
    assert_contains_pytrace($countCmd, '-m', 'coverage count command', $errors);
    assert_contains_pytrace($countCmd, 'trace', 'coverage count command', $errors);
    assert_contains_pytrace($countCmd, '--count', 'coverage count command', $errors);
    assert_contains_pytrace($countCmd, '--file', 'coverage count command', $errors);
    assert_contains_pytrace($countCmd, '--coverdir', 'coverage count command', $errors);
    assert_contains_pytrace($countCmd, '/tmp/testkit-pytrace/.testkit/coverage/back_python', 'coverage count command', $errors);
    assert_contains_pytrace($countCmd, $traceFile, 'coverage count command', $errors);

    $fileIndex = array_search('--file', $countCmd, true);
    assert_true_pytrace(is_int($fileIndex), 'coverage count command should include --file', $errors);
    assert_true_pytrace(($countCmd[$fileIndex + 1] ?? null) === $traceFile, 'coverage count command should preserve trace_counts.dat after --file', $errors);
    assert_true_pytrace(end($countCmd) === $testFile, 'coverage count command should keep test file as final argument', $errors);

    $reportMethod = new ReflectionMethod(BackPythonSuite::class, 'buildCoverageReportCommand');
    $reportMethod->setAccessible(true);
    $reportCmd = $reportMethod->invoke(null, 'python3', $traceFile, '/tmp/testkit-pytrace/.testkit/coverage/back_python');

    assert_true_pytrace(is_array($reportCmd), 'coverage report command should be an array', $errors);
    foreach (['-m', 'trace', '--report', '--missing', '--summary', '--file', '--coverdir'] as $token) {
        assert_contains_pytrace($reportCmd, $token, 'coverage report command', $errors);
    }
    assert_contains_pytrace($reportCmd, $traceFile, 'coverage report command', $errors);

    $python = find_python_binary_pytrace();
    assert_true_pytrace($python !== null, 'python3 or python must be available to run BackPythonSuite trace regression', $errors);

    if ($python !== null) {
        $tmpRoot = sys_get_temp_dir() . '/testkit_back_python_trace_' . uniqid('', true);
        $repoRoot = $tmpRoot . '/repo';
        $artifactRoot = $repoRoot . '/.testkit';
        $testDir = $repoRoot . '/test/back/ocpp_server/integration';
        $coverageRoot = $artifactRoot . '/coverage';
        $coverageDir = $coverageRoot . '/back_python';
        @mkdir($testDir, 0777, true);

        file_put_contents(
            $testDir . '/ocpp_trace_artifacts_unittest.py',
            implode("\n", [
                'import unittest',
                '',
                'class OcppTraceArtifactsTest(unittest.TestCase):',
                '    def test_trace_runs(self):',
                '        self.assertEqual(2 + 2, 4)',
                '',
                "if __name__ == '__main__':",
                '    unittest.main()',
                '',
            ])
        );

        $keys = [
            'TESTKIT_PROJECT_ROOT',
            'TK_REPO_ROOT',
            'TESTKIT_ARTIFACTS_ROOT',
            'TK_BACK_PYTHON_DIR',
            'TEST_COVERAGE',
            'TEST_COVERAGE_ROOT',
            'TEST_COVERAGE_DIR',
            'TEST_COVERAGE_FORMAT',
            'TEST_MATCH',
            'TEST_REQUIRE_TESTS',
            'TEST_LIST',
            'TEST_JOBS',
            'TEST_PYTHON_BINARY',
            'TESTKIT_SKIP_STORE_BOOTSTRAP',
            'TEST_STORE_DRIVER',
            'TEST_DB_STRATEGY',
            'TESTKIT_PROGRESS_MODE',
            'TEST_REPORT_ROOT',
            'TEST_REPORT_RUN_ROOT',
            'TEST_RUN_ID',
            'TEST_META_RUN_ID',
        ];
        $previousEnv = [];
        foreach ($keys as $key) {
            $previousEnv[$key] = get_env_pytrace($key);
        }

        try {
            set_env_pytrace('TESTKIT_PROJECT_ROOT', $repoRoot);
            set_env_pytrace('TK_REPO_ROOT', $repoRoot);
            set_env_pytrace('TESTKIT_ARTIFACTS_ROOT', $artifactRoot);
            set_env_pytrace('TK_BACK_PYTHON_DIR', 'test/back');
            set_env_pytrace('TEST_COVERAGE', '1');
            set_env_pytrace('TEST_COVERAGE_ROOT', $coverageRoot);
            set_env_pytrace('TEST_COVERAGE_DIR', null);
            set_env_pytrace('TEST_COVERAGE_FORMAT', 'both');
            set_env_pytrace('TEST_MATCH', 'ocpp_trace_artifacts');
            set_env_pytrace('TEST_REQUIRE_TESTS', '1');
            set_env_pytrace('TEST_LIST', '0');
            set_env_pytrace('TEST_JOBS', '3');
            set_env_pytrace('TEST_PYTHON_BINARY', $python);
            set_env_pytrace('TESTKIT_SKIP_STORE_BOOTSTRAP', '1');
            set_env_pytrace('TEST_STORE_DRIVER', 'none');
            set_env_pytrace('TEST_DB_STRATEGY', 'shared');
            set_env_pytrace('TESTKIT_PROGRESS_MODE', 'quiet');
            set_env_pytrace('TEST_REPORT_ROOT', $artifactRoot . '/reports');
            set_env_pytrace('TEST_REPORT_RUN_ROOT', null);
            set_env_pytrace('TEST_RUN_ID', 'pytrace_' . str_replace('.', '_', uniqid('', true)));
            set_env_pytrace('TEST_META_RUN_ID', 'pytrace_meta_' . str_replace('.', '_', uniqid('', true)));

            ob_start();
            $exitCode = BackPythonSuite::run();
            $suiteOutput = (string)ob_get_clean();

            assert_true_pytrace($exitCode === 0, 'BackPythonSuite coverage fixture should pass. Output: ' . $suiteOutput, $errors);
            assert_true_pytrace(is_file($coverageDir . '/trace_counts.dat'), 'BackPythonSuite coverage should create trace_counts.dat under .testkit coverage', $errors);
            assert_true_pytrace(has_cover_file_pytrace($coverageDir), 'BackPythonSuite coverage should write annotated *.cover artifacts under .testkit coverage', $errors);

            $dirtyCoverFiles = find_cover_files_outside_pytrace($repoRoot, $artifactRoot);
            assert_true_pytrace(
                $dirtyCoverFiles === [],
                'BackPythonSuite coverage should not create *.cover outside .testkit. Found: ' . implode(', ', $dirtyCoverFiles),
                $errors
            );
        } finally {
            foreach ($previousEnv as $key => $value) {
                set_env_pytrace($key, $value);
            }
            remove_tree_pytrace($tmpRoot);
        }
    }
} catch (Throwable $e) {
    $errors[] = $e::class . ': ' . $e->getMessage();
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "BackPythonSuite trace coverage contract PASS\n";
exit(0);
