<?php
declare(strict_types=1);

namespace Testkit\Core\Influx;

use RuntimeException;

final class InfluxClient
{
    private readonly InfluxHttpClient $http;

    public function __construct(
        private readonly InfluxConfig $config
    ) {
        $this->http = new InfluxHttpClient($config);
    }

    /**
     * @return array<string,mixed>
     */
    public function health(): array
    {
        return $this->http->json('GET', '/health', [], [], null, [200, 503]);
    }

    /**
     * @return array<string,mixed>
     */
    public function ready(): array
    {
        return $this->http->json('GET', '/ready', [], [], null, [200, 503]);
    }

    public function write(string $lineProtocol, ?string $bucket = null, ?string $org = null, ?string $precision = null): void
    {
        $bucket ??= $this->config->bucket();
        $org ??= $this->config->org();
        $precision ??= $this->config->precision();

        $this->http->request(
            'POST',
            '/api/v2/write',
            [
                'bucket' => $bucket,
                'org' => $org,
                'precision' => $precision,
            ],
            [
                'Authorization' => 'Token ' . $this->config->token(),
                'Content-Type' => 'text/plain; charset=utf-8',
                'Accept' => 'application/json',
            ],
            $lineProtocol,
            [204]
        );
    }

    public function queryCsv(string $flux, ?string $org = null): string
    {
        $org ??= $this->config->org();

        $response = $this->http->request(
            'POST',
            '/api/v2/query',
            [],
            [
                'Authorization' => 'Token ' . $this->config->token(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/csv',
            ],
            json_encode([
                'query' => $flux,
                'type' => 'flux',
                'dialect' => [
                    'annotations' => [],
                    'header' => true,
                    'delimiter' => ',',
                ],
            ], JSON_THROW_ON_ERROR),
            [200]
        );

        return $response['body'];
    }

    public function ensureBucketExists(?string $bucket = null, ?string $org = null): void
    {
        $bucket ??= $this->config->bucket();
        $org ??= $this->config->org();

        $existing = $this->http->json(
            'GET',
            '/api/v2/buckets',
            ['name' => $bucket],
            [
                'Authorization' => 'Token ' . $this->config->adminToken(),
            ],
            null,
            [200]
        );

        foreach (($existing['buckets'] ?? []) as $item) {
            if (($item['name'] ?? null) === $bucket) {
                return;
            }
        }

        $orgs = $this->http->json(
            'GET',
            '/api/v2/orgs',
            ['org' => $org],
            [
                'Authorization' => 'Token ' . $this->config->adminToken(),
            ],
            null,
            [200]
        );

        $orgId = null;
        foreach (($orgs['orgs'] ?? []) as $item) {
            if (($item['name'] ?? null) === $org && is_string($item['id'] ?? null)) {
                $orgId = $item['id'];
                break;
            }
        }

        if (!is_string($orgId) || $orgId === '') {
            throw new RuntimeException('No se pudo resolver orgId para org=' . $org);
        }

        $this->http->json(
            'POST',
            '/api/v2/buckets',
            [],
            [
                'Authorization' => 'Token ' . $this->config->adminToken(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            json_encode([
                'orgID' => $orgId,
                'name' => $bucket,
            ], JSON_THROW_ON_ERROR),
            [201]
        );
    }

    public function purgeRun(string $runId, ?string $bucket = null, ?string $org = null): void
    {
        $bucket ??= $this->config->bucket();
        $org ??= $this->config->org();
        $predicate = sprintf('%s="%s"', $this->config->runTagKey(), addcslashes($runId, '"\\'));

        $this->http->request(
            'POST',
            '/api/v2/delete',
            [
                'org' => $org,
                'bucket' => $bucket,
            ],
            [
                'Authorization' => 'Token ' . $this->config->adminToken(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            json_encode([
                'start' => '1970-01-01T00:00:00Z',
                'stop' => '2100-01-01T00:00:00Z',
                'predicate' => $predicate,
            ], JSON_THROW_ON_ERROR),
            [204]
        );
    }
}
