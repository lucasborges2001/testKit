<?php
/**
 * test/utils/pvt.php
 *
 * Helpers mínimos para tests (sin dependencias del runner).
 */

if (!function_exists('pvt_scope_allows')) {
  function pvt_scope_allows(string $needed): bool {
    $scope = getenv('TEST_SCOPE');
    $scope = is_string($scope) && $scope !== '' ? strtolower(trim($scope)) : 'all';
    $needed = strtolower(trim($needed));

    if ($scope === 'all') return true;
    return $scope === $needed;
  }
}

if (!function_exists('pvt_assert')) {
  function pvt_assert($cond, string $msg = 'assert failed', array $ctx = []): void {
    if ($cond) return;
    $suffix = '';
    if (!empty($ctx)) {
      $suffix = ' | ctx=' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    throw new Exception($msg . $suffix);
  }
}

if (!function_exists('pvt_eq')) {
  function pvt_eq($a, $b, string $msg = 'not equal'): void {
    if ($a === $b) return;
    throw new Exception($msg . ' | a=' . var_export($a, true) . ' b=' . var_export($b, true));
  }
}

if (!function_exists('pvt_contains')) {
  function pvt_contains(string $hay, string $needle, string $msg = 'does not contain'): void {
    if (strpos($hay, $needle) !== false) return;
    throw new Exception($msg . ' | needle=' . $needle . ' hay=' . $hay);
  }
}

if (!function_exists('pvt_throws')) {
  function pvt_throws(callable $fn, ?string $class = null, ?string $msgContains = null): Throwable {
    try {
      $fn();
    } catch (Throwable $e) {
      if ($class !== null) {
        pvt_assert(is_a($e, $class), 'unexpected exception class', ['got' => get_class($e), 'want' => $class]);
      }
      if ($msgContains !== null) {
        pvt_contains($e->getMessage(), $msgContains, 'unexpected exception message');
      }
      return $e;
    }
    throw new Exception('expected exception, got none');
  }
}

if (!function_exists('pvt_run')) {
  function pvt_run(string $name, callable $fn): void {
    $t0 = microtime(true);
    $fn();
    $ms = (int)round((microtime(true) - $t0) * 1000);
    echo "[OK] {$name} ({$ms}ms)\n";
  }
}

if (!function_exists('pvt_env_set')) {
  function pvt_env_set(string $k, ?string $v): void {
    if ($v === null) {
      putenv($k);
      unset($_ENV[$k], $_SERVER[$k]);
      return;
    }
    putenv($k . '=' . $v);
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
  }
}
