<?php
declare(strict_types=1);

/**
 * TestKit mínimo para tests del módulo back/mysql.
 *
 * Objetivo:
 * - Permitir ejecutar cada archivo como script (php archivo.php)
 * - Y también permitir que el runner del repo lo "include" sin side-effects
 *   (los tests se exponen como array de callables).
 */

/** @param mixed $v */
function t_type(mixed $v): string {
  return is_object($v) ? ('object(' . get_class($v) . ')') : gettype($v);
}

function t_fail(string $msg): void {
  throw new RuntimeException($msg);
}

function t_true(bool $cond, string $msg = 'assert_true failed'): void {
  if (!$cond) t_fail($msg);
}

/** @param mixed $exp @param mixed $act */
function t_eq(mixed $exp, mixed $act, string $msg = ''): void {
  if ($exp !== $act) {
    $m = $msg !== '' ? ($msg . ' ') : '';
    $m .= 'expected=' . var_export($exp, true) . ' actual=' . var_export($act, true);
    t_fail($m);
  }
}

/** @param callable():mixed $fn */
function t_throws(callable $fn, string $class, ?string $msgContains = null): Throwable {
  try {
    $fn();
  } catch (Throwable $e) {
    t_true($e instanceof $class, 'expected exception ' . $class . ' got ' . get_class($e));
    if ($msgContains !== null) {
      t_true(str_contains($e->getMessage(), $msgContains), 'exception message does not contain: ' . $msgContains . ' (got: ' . $e->getMessage() . ')');
    }
    return $e;
  }
  throw new RuntimeException('expected exception ' . $class . ' but none was thrown');
}

/**
 * Ejecuta casos (name => callable) en CLI.
 * @param array<string,callable():void> $cases
 */
function t_run_cli(array $cases): void {
  $failed = 0;
  $passed = 0;
  $t0 = microtime(true);

  $dur = []; // name => ms

  foreach ($cases as $name => $fn) {
    try {
      $c0 = microtime(true);
      $fn();
      $dur[$name] = (int)round((microtime(true) - $c0) * 1000);
      $passed++;
      fwrite(STDOUT, "[OK]  $name\n");
    } catch (Throwable $e) {
      $dur[$name] = isset($c0) ? (int)round((microtime(true) - $c0) * 1000) : 0;
      $failed++;
      fwrite(STDERR, "[FAIL] $name\n");
      fwrite(STDERR, '  ' . get_class($e) . ': ' . $e->getMessage() . "\n");
      $file = $e->getFile();
      $line = $e->getLine();
      fwrite(STDERR, "  at $file:$line\n");
    }
  }

  $ms = (int)round((microtime(true) - $t0) * 1000);

  // Top N más lentos
  $topN = (int)(getenv('TEST_TIME_TOP') ?: 10);
  if ($topN > 0 && count($dur) > 0) {
    arsort($dur);
    fwrite(STDOUT, "\nSlowest (top {$topN})\n");
    $i = 0;
    foreach ($dur as $n => $dms) {
      fwrite(STDOUT, str_pad((string)$dms, 6, ' ', STR_PAD_LEFT) . " ms  " . $n . "\n");
      $i++;
      if ($i >= $topN) break;
    }
  }

  fwrite(STDOUT, "\nSummary: passed=$passed failed=$failed time_ms=$ms\n");
  exit($failed === 0 ? 0 : 1);
}
