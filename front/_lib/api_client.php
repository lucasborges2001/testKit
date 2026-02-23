<?php
declare(strict_types=1);

/**
 * Mini HTTP client para tests de public_html (sin ext-curl).
 * - Soporta cookies (Set-Cookie -> Cookie).
 * - Devuelve status + headers + body + json.
 */

/** @return array{status:int,headers:array<int,string>,body:string} */
function pvt_http_raw(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 10): array
{
  $hdrLines = [];
  foreach ($headers as $h) {
    if (!is_string($h) || trim($h) === '') continue;
    $hdrLines[] = trim($h);
  }

  $opts = [
    'http' => [
      'method' => strtoupper($method),
      'header' => implode("\r\n", $hdrLines),
      'ignore_errors' => true, // leer body aunque status != 200
      'timeout' => $timeout,
    ],
  ];

  if ($body !== null) {
    $opts['http']['content'] = $body;
  }

  $ctx = stream_context_create($opts);
  $out = @file_get_contents($url, false, $ctx);
  $out = is_string($out) ? $out : '';

  $respHeaders = $http_response_header ?? [];
  $status = 0;
  if (isset($respHeaders[0]) && is_string($respHeaders[0])) {
    // HTTP/1.1 200 OK
    if (preg_match('/\s(\d{3})\s/', $respHeaders[0], $m)) {
      $status = (int)$m[1];
    }
  }

  return ['status' => $status, 'headers' => $respHeaders, 'body' => $out];
}

/** @return array{status:int,headers:array<int,string>,body:string,json:mixed|null} */
function pvt_http_json(string $method, string $url, array $jsonBody = [], array $headers = [], ?string $cookie = null): array
{
  $hdrs = $headers;
  $hdrs[] = 'Content-Type: application/json';
  $hdrs[] = 'Accept: application/json';
  if ($cookie !== null && trim($cookie) !== '') {
    $hdrs[] = 'Cookie: ' . $cookie;
  }

  $raw = pvt_http_raw($method, $url, $hdrs, json_encode($jsonBody));
  $j = null;
  $b = trim($raw['body']);
  if ($b !== '' && ($b[0] === '{' || $b[0] === '[')) {
    $j = json_decode($b, true);
  }

  return ['status' => $raw['status'], 'headers' => $raw['headers'], 'body' => $raw['body'], 'json' => $j];
}

/** @return string cookie header "a=b; c=d" */
function pvt_cookie_from_set_cookie(array $headers): string
{
  $pairs = [];
  foreach ($headers as $h) {
    if (!is_string($h)) continue;
    if (stripos($h, 'Set-Cookie:') !== 0) continue;
    $v = trim(substr($h, strlen('Set-Cookie:')));
    if ($v === '') continue;
    // name=value; Path=/; HttpOnly...
    $parts = explode(';', $v);
    $nv = trim($parts[0] ?? '');
    if ($nv === '' || strpos($nv, '=') === false) continue;
    $pairs[] = $nv;
  }
  // dedupe por nombre, último gana
  $byName = [];
  foreach ($pairs as $nv) {
    [$n, $v] = explode('=', $nv, 2);
    $byName[trim($n)] = trim($n) . '=' . $v;
  }
  return implode('; ', array_values($byName));
}

/** @return string */
function pvt_env(string $k, string $default = ''): string
{
  $v = getenv($k);
  if ($v === false) return $default;
  $v = (string)$v;
  return $v === '' ? $default : $v;
}

/**
 * Loguea por API y devuelve cookie.
 * Requiere:
 *   TEST_USER / TEST_PASS
 * Opcionales:
 *   TEST_BASE_URL (default http://nginx)
 *   TEST_AUTH_PATH (default /api/auth/login)
 */
function pvt_login_cookie(): string
{
  $base = rtrim(pvt_env('TEST_BASE_URL', 'http://nginx'), '/');
  $path = pvt_env('TEST_AUTH_PATH', '/api/auth/login');
  $user = pvt_env('TEST_USER', '');
  $pass = pvt_env('TEST_PASS', '');

  if ($user === '' || $pass === '') {
    throw new RuntimeException('Faltan credenciales: setear TEST_USER y TEST_PASS para tests HTTP.');
  }

  $url = $base . $path;

  // Asumimos JSON {username,password}
  $resp = pvt_http_json('POST', $url, ['username' => $user, 'password' => $pass]);

  $cookie = pvt_cookie_from_set_cookie($resp['headers']);
  $j = $resp['json'];

  // Auth puede responder 200 ok:true o 401 ok:false
  if ($resp['status'] < 200 || $resp['status'] >= 300 || !is_array($j) || !($j['ok'] ?? false)) {
    $head = substr(trim((string)$resp['body']), 0, 250);
    throw new RuntimeException('Login falló. status=' . $resp['status'] . ' body_head=' . $head);
  }

  if ($cookie === '') {
    // Algunos setups devuelven cookie igual; si no, el sistema podría usar token en body.
    // No adivinamos: fallamos explícito.
    throw new RuntimeException('Login ok pero no vino Set-Cookie. Revisar auth stack / headers.');
  }

  return $cookie;
}

/** @return bool */
function pvt_header_has(array $headers, string $prefix): bool
{
  $p = strtolower($prefix);
  foreach ($headers as $h) {
    if (!is_string($h)) continue;
    if (str_starts_with(strtolower($h), $p)) return true;
  }
  return false;
}

/** @return string|null */
function pvt_header_get(array $headers, string $prefix): ?string
{
  $p = strtolower($prefix);
  foreach ($headers as $h) {
    if (!is_string($h)) continue;
    if (str_starts_with(strtolower($h), $p)) return trim(substr($h, strlen($prefix)));
  }
  return null;
}
