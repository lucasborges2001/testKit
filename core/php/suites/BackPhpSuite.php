<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;
use Testkit\Core\Common\ProjectEnv;
use Testkit\Core\Config\RunnerConfig;

final class BackPhpSuite
{
    public static function run(): int
    {
        $repoRoot = Paths::repoRoot();
        $testkitRoot = Paths::testkitRoot();

        $testsRoot = $repoRoot . '/test/back';
        $testsDir = is_dir($testsRoot . '/tests') ? ($testsRoot . '/tests') : $testsRoot;

        $config = RunnerConfig::forSuite(
            'back_php',
            $testsDir,
            $repoRoot . '/test/coverage/php_back',
            'php'
        );

        $resolved = ProjectEnv::resolveDbEnv($repoRoot);
        foreach ($resolved['warnings'] as $warning) {
            fwrite(STDERR, $warning . PHP_EOL);
        }

        if ($resolved['db_env_path'] !== '') {
            putenv('DB_ENV_PATH=' . $resolved['db_env_path']);
        }

        putenv('APP_ENV=test');
        putenv('APP_DEBUG=1');
        ContractWorldBootstrap::prepare('back_php', $repoRoot);

        $prepend = $testkitRoot . '/utils/php/auto_prepend.php';
        $phpBinary = self::phpBinary();

        if ((bool)$config['coverage']) {
            @mkdir((string)$config['coverage_dir'], 0777, true);
            foreach (glob((string)$config['coverage_dir'] . '/*.json') ?: [] as $file) {
                @unlink($file);
            }
        }

        return SuiteOrchestrator::run(
            $config,
            ['.test.php'],
            static function (array $test, int $workerId) use ($phpBinary, $prepend, $config, $repoRoot, $testkitRoot, $resolved): array {
                $cmd = [$phpBinary];
                $env = [
                    'TEST_SUITE' => 'back',
                    'APP_ENV' => 'test',
                    'APP_DEBUG' => '1',
                    'TESTKIT_ROOT' => $testkitRoot,
                    'TK_REPO_ROOT' => $repoRoot,
                    'TEST_WORKER_ID' => (string)$workerId,
                ];

                if ($resolved['db_env_path'] !== '') {
                    $env['DB_ENV_PATH'] = $resolved['db_env_path'];
                }

                if ((bool)$config['coverage']) {
                    $cmd[] = '-d';
                    $cmd[] = 'xdebug.mode=coverage';
                    $cmd[] = '-d';
                    $cmd[] = 'xdebug.start_with_request=no';

                    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string)$test['rel']) ?: 'test';
                    $env['TEST_COVERAGE'] = '1';
                    $env['TEST_COVERAGE_FILE'] = (string)$config['coverage_dir'] . '/' . $safe . '.json';
                    $env['TEST_COVERAGE_FORMAT'] = (string)$config['coverage_format'];
                    $env['TEST_COVERAGE_DIR'] = (string)$config['coverage_dir'];
                }

                $cmd[] = '-d';
                $cmd[] = 'auto_prepend_file=' . $prepend;
                $cmd[] = (string)$test['file'];

                return ['cmd' => $cmd, 'env' => $env];
            }
        );
    }

    private static function phpBinary(): string
    {
        $explicit = Env::string('TEST_PHP_BINARY', '');
        if ($explicit !== '' && is_file($explicit)) {
            return $explicit;
        }
        return PHP_BINARY;
    }
}
