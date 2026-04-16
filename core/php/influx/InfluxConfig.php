<?php
declare(strict_types=1);

namespace Testkit\Core\Influx;

use RuntimeException;
use Testkit\Core\Common\Env;

final class InfluxConfig
{
    public function url(): string
    {
        $explicit = trim((string) getenv('TEST_INFLUX_URL'));
        if ($explicit !== '') {
            return rtrim($explicit, '/');
        }

        $host = trim((string) (getenv('TEST_INFLUX_HOST') ?: 'influx_test'));
        $port = trim((string) (getenv('TEST_INFLUX_INTERNAL_PORT') ?: '8086'));
        $scheme = trim((string) (getenv('TEST_INFLUX_SCHEME') ?: 'http'));

        return rtrim(sprintf('%s://%s:%s', $scheme, $host, $port), '/');
    }

    public function org(): string
    {
        return $this->requireEnv(['TEST_INFLUX_ORG'], 'Influx org');
    }

    public function bucket(): string
    {
        return $this->requireEnv(['TEST_INFLUX_BUCKET'], 'Influx bucket');
    }

    public function token(): string
    {
        foreach (['TEST_INFLUX_TOKEN', 'TEST_INFLUX_ADMIN_TOKEN'] as $key) {
            $value = trim((string) getenv($key));
            if ($value !== '') {
                return $value;
            }
        }

        throw new RuntimeException('Falta token de Influx (TEST_INFLUX_TOKEN o TEST_INFLUX_ADMIN_TOKEN).');
    }

    public function adminToken(): string
    {
        foreach (['TEST_INFLUX_ADMIN_TOKEN', 'TEST_INFLUX_TOKEN'] as $key) {
            $value = trim((string) getenv($key));
            if ($value !== '') {
                return $value;
            }
        }

        throw new RuntimeException('Falta token admin de Influx (TEST_INFLUX_ADMIN_TOKEN o TEST_INFLUX_TOKEN).');
    }

    public function timeoutSeconds(): int
    {
        return max(1, Env::int('TEST_INFLUX_TIMEOUT_SEC', 15));
    }

    public function precision(): string
    {
        $precision = strtolower(trim((string) (getenv('TEST_INFLUX_PRECISION') ?: 'ns')));
        return in_array($precision, ['ns', 'us', 'ms', 's'], true) ? $precision : 'ns';
    }

    public function runTagKey(): string
    {
        $value = trim((string) (getenv('TEST_INFLUX_RUN_TAG_KEY') ?: 'testkit_run_id'));
        return $value !== '' ? $value : 'testkit_run_id';
    }

    /**
     * @param array<int,string> $keys
     */
    private function requireEnv(array $keys, string $label): string
    {
        foreach ($keys as $key) {
            $value = trim((string) getenv($key));
            if ($value !== '') {
                return $value;
            }
        }

        throw new RuntimeException('Falta ' . $label . ' (' . implode('/', $keys) . ').');
    }
}
