<?php
declare(strict_types=1);

function t_assert_true($cond, string $msg = 'assert_true failed'): void {
  if (!$cond) throw new RuntimeException($msg);
}

function t_assert_eq($a, $b, string $msg = 'assert_eq failed'): void {
  if ($a !== $b) {
    throw new RuntimeException($msg . " | got=" . var_export($a, true) . " expected=" . var_export($b, true));
  }
}

function t_run(string $name, callable $fn): void {
  try {
    $fn();
    echo "[OK]  $name\n";
  } catch (Throwable $e) {
    echo "[FAIL] $name :: " . $e->getMessage() . "\n";
    exit(1);
  }
}
