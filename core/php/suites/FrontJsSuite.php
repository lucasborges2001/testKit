<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;
use Testkit\Core\Execution\ProcessRunner;

final class FrontJsSuite
{
    public static function run(): int
    {
        $repoRoot = Paths::repoRoot();
        ContractWorldBootstrap::prepare('front_js', $repoRoot);

        $runner = Paths::testkitRoot() . '/runners/runFrontTest.mjs';
        if (!is_file($runner)) {
            fwrite(STDERR, 'Falta runner JS: ' . $runner . PHP_EOL);
            return 3;
        }

        $node = Env::string('NODE_BINARY', 'node');
        $nodePath = self::findBin($node);
        if ($nodePath === null) {
            $requireNode = Env::bool('TEST_JS_REQUIRE_NODE', false);
            fwrite(STDERR, ($requireNode ? 'FAIL' : 'SKIP') . ": no se encontro '{$node}' en PATH.\n");
            return $requireNode ? 1 : 2;
        }

        $env = self::baseEnv();
        $env['TESTKIT_ROOT'] = Paths::testkitRoot();
        $env['TK_REPO_ROOT'] = $repoRoot;
        $env['TESTKIT_REPORT_FILE'] = Paths::reportsRoot() . '/front_js_latest.json';

        $job = ProcessRunner::start([$nodePath, $runner], $repoRoot, $env);
        $done = ProcessRunner::finish($job);

        $stdout = (string)($done['stdout'] ?? '');
        $stderr = (string)($done['stderr'] ?? '');
        if ($stdout !== '') {
            fwrite(STDOUT, $stdout);
        }
        if ($stderr !== '') {
            fwrite(STDERR, $stderr);
        }

        return (int)($done['code'] ?? 1);
    }

    private static function findBin(string $bin): ?string
    {
        $bin = trim($bin);
        if ($bin === '') {
            return null;
        }

        if (strpbrk($bin, '/\\') !== false) {
            return (is_file($bin) && is_executable($bin)) ? $bin : null;
        }

        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return null;
        }

        $candidates = [$bin];
        if (PHP_OS_FAMILY === 'Windows' && pathinfo($bin, PATHINFO_EXTENSION) === '') {
            $candidates = [$bin . '.exe', $bin . '.cmd', $bin . '.bat', $bin];
        }

        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $dir = trim($dir);
            if ($dir === '') {
                continue;
            }

            foreach ($candidates as $candidate) {
                $file = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $candidate;
                if (is_file($file) && is_executable($file)) {
                    return $file;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string,string>
     */
    private static function baseEnv(): array
    {
        $env = [];
        $raw = getenv();
        if (is_array($raw)) {
            foreach ($raw as $k => $v) {
                if (!is_string($k) || $k === '' || !is_scalar($v)) {
                    continue;
                }
                $env[$k] = (string)$v;
            }
        }
        return $env;
    }
}
