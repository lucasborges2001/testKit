<?php
declare(strict_types=1);

namespace Testkit\Core\Common;

final class AgentMode
{
    private const MODE_AGENT = 'agent';

    public static function isEnabled(): bool
    {
        return self::mode() === self::MODE_AGENT;
    }

    public static function mode(): string
    {
        $raw = strtolower(trim(Env::string('TESTKIT_MODE', '')));
        return $raw === self::MODE_AGENT ? self::MODE_AGENT : '';
    }

    public static function applyRuntimeEnv(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        self::forceEnv('TEST_META_FAIL_FAST', '0');
        self::forceEnv('TEST_CHILD_FAIL_FAST', '0');
        self::forceEnv('TEST_FAIL_FAST', '0');
        self::forceEnv('TEST_JOBS', '1');
        self::forceEnv('TEST_DB_STRATEGY', 'shared');
        self::forceEnv('TESTKIT_PROGRESS_MODE', 'quiet');
        self::forceEnv('NO_COLOR', '1');
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function suiteConfig(array $config): array
    {
        if (!self::isEnabled()) {
            $config['agent_mode'] = self::reportPayload();
            return $config;
        }

        $config['jobs'] = 1;
        $config['fail_fast'] = false;
        $config['agent_mode'] = self::reportPayload();

        return $config;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function metaConfig(array $config): array
    {
        if (!self::isEnabled()) {
            $config['agent_mode'] = self::reportPayload();
            return $config;
        }

        $config['meta_fail_fast'] = false;
        $config['child_fail_fast'] = false;
        $config['agent_mode'] = self::reportPayload();

        return $config;
    }

    /**
     * @return array<string,mixed>
     */
    public static function reportPayload(): array
    {
        if (!self::isEnabled()) {
            return [
                'enabled' => false,
                'mode' => 'standard',
                'enforced' => [],
            ];
        }

        return [
            'enabled' => true,
            'mode' => self::MODE_AGENT,
            'enforced' => [
                'TEST_META_FAIL_FAST' => '0',
                'TEST_CHILD_FAIL_FAST' => '0',
                'TEST_FAIL_FAST' => '0',
                'TEST_JOBS' => '1',
                'TEST_DB_STRATEGY' => 'shared',
                'TESTKIT_PROGRESS_MODE' => 'quiet',
                'NO_COLOR' => '1',
            ],
        ];
    }

    private static function forceEnv(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
