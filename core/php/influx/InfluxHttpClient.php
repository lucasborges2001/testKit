<?php
declare(strict_types=1);

namespace Testkit\Core\Influx;

use RuntimeException;

final class InfluxHttpClient
{
    public function __construct(
        private readonly InfluxConfig $config
    ) {
    }

    /**
     * @param array<string,string|int|float> $query
     * @param array<string,string> $headers
     * @param array<int,int>|int $expectedStatus
     * @return array{status:int,body:string,headers:array<int,string>}
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        array $headers = [],
        ?string $body = null,
        array|int $expectedStatus = 200
    ): array {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('curl extension no disponible en PHP para Influx HTTP client.');
        }

        $url = $this->config->url() . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = $key . ': ' . $value;
        }

        $responseHeaders = [];
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('No se pudo iniciar cURL para Influx.');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->config->timeoutSeconds(),
            CURLOPT_TIMEOUT => $this->config->timeoutSeconds(),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $trimmed = trim($headerLine);
                if ($trimmed !== '') {
                    $responseHeaders[] = $trimmed;
                }
                return strlen($headerLine);
            },
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP Influx falló: ' . $error);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $expected = is_array($expectedStatus) ? $expectedStatus : [$expectedStatus];
        if (!in_array($status, $expected, true)) {
            throw new RuntimeException(sprintf(
                'Influx HTTP %s %s devolvió %d. Body: %s',
                strtoupper($method),
                $path,
                $status,
                trim((string) $raw)
            ));
        }

        return [
            'status' => $status,
            'body' => (string) $raw,
            'headers' => $responseHeaders,
        ];
    }

    /**
     * @param array<string,string|int|float> $query
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    public function json(
        string $method,
        string $path,
        array $query = [],
        array $headers = [],
        ?string $body = null,
        array|int $expectedStatus = 200
    ): array {
        $headers = array_merge(['Accept' => 'application/json'], $headers);
        $response = $this->request($method, $path, $query, $headers, $body, $expectedStatus);
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Respuesta JSON inválida de Influx para ' . $path);
        }

        return $decoded;
    }
}
