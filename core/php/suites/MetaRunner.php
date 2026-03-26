<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;
use Testkit\Core\Config\RunnerConfig;
use Testkit\Core\Execution\ProcessRunner;
use Testkit\Core\Reporting\ConsoleReporter;
use Testkit\Core\Reporting\ResultWriter;

final class MetaRunner
{
    public static function run(string $targetArg): int
    {
        $config = RunnerConfig::meta();
        $target = strtolower(trim($targetArg !== '' ? $targetArg : (string)$config['target']));
        if ($target === '') {
            $target = 'all';
        }

        $categoryTargets = ['smoke', 'perf', 'stress', 'contract', 'critical', 'slow'];
        if (in_array($target, $categoryTargets, true) && Env::string('TEST_CATEGORY', '') === '') {
            putenv('TEST_CATEGORY=' . $target);
        }

        $selected = self::resolveTarget($target);
        if (!$selected) {
            fwrite(STDERR, 'TEST_TARGET invalido: ' . $target . ". Valores: all|back|front|back-php|back-py|front-php|front-js|php|js|smoke|perf|stress|contract|critical|slow\n");
            return 3;
        }

        putenv('TEST_FAIL_FAST=' . ((bool)$config['child_fail_fast'] ? '1' : '0'));

        $metaStart = self::nowMs();
        $suiteRows = [];
        $overallFail = false;

        foreach ($selected as $suiteId) {
            $start = self::nowMs();
            $code = self::runSuite($suiteId);
            $duration = max(0, self::nowMs() - $start);

            $suiteRows[] = [
                'suite_id' => $suiteId,
                'exit_code' => $code,
                'duration_ms' => $duration,
            ];

            if ($code !== 0 && $code !== 2) {
                $overallFail = true;
                if ((bool)$config['meta_fail_fast']) {
                    break;
                }
            }
        }

        $meta = [
            'target' => $target,
            'category' => Env::string('TEST_CATEGORY', 'all'),
            'suites' => $suiteRows,
            'duration_ms' => max(0, self::nowMs() - $metaStart),
            'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        ConsoleReporter::printMeta($meta);
        ResultWriter::writeMeta($meta);

        return $overallFail ? 1 : 0;
    }

    /**
     * @return array<int,string>
     */
    private static function resolveTarget(string $target): array
    {
        $map = [
            'all' => ['back_php', 'back_python', 'front_php', 'front_js'],
            'back' => ['back_php', 'back_python'],
            'front' => ['front_php', 'front_js'],
            'public_html' => ['front_php', 'front_js'],
            'back-php' => ['back_php'],
            'back-py' => ['back_python'],
            'back-python' => ['back_python'],
            'python' => ['back_python'],
            'py' => ['back_python'],
            'front-php' => ['front_php'],
            'front-js' => ['front_js'],
            'php' => ['back_php', 'front_php'],
            'js' => ['front_js'],
            'smoke' => ['back_php', 'back_python', 'front_php', 'front_js'],
            'perf' => ['back_python', 'front_js'],
            'stress' => ['back_python', 'front_js'],
            'contract' => ['back_php', 'back_python', 'front_php', 'front_js'],
            'critical' => ['back_php', 'back_python', 'front_php', 'front_js'],
            'slow' => ['back_php', 'back_python', 'front_php', 'front_js'],
        ];

        return $map[$target] ?? [];
    }

    private static function runSuite(string $suiteId): int
    {
        return match ($suiteId) {
            'back_php' => BackPhpSuite::run(),
            'back_python' => BackPythonSuite::run(),
            'front_php' => FrontPhpSuite::run(),
            'front_js' => self::runFrontJsSuite(),
            default => 3,
        };
    }

    private static function runFrontJsSuite(): int
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

    private static function nowMs(): int
    {
        return (int)round(microtime(true) * 1000);
    }
}
