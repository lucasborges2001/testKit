<?php
declare(strict_types=1);

/**
 * /test/scripts/query_report.php
 *
 * Lee test/_out/querylog.jsonl (generado por db_profiler.php) y produce un reporte:
 * - top tablas / columnas
 * - candidatos de índices (heurístico)
 * - slow queries + sugerencia EXPLAIN
 *
 * Env:
 * - TEST_DB_PROFILE_LOG  (default: test/_out/querylog.jsonl)
 * - TEST_DB_PROFILE_TOP  (default: 20)
 * - TEST_DB_PROFILE_SLOW (default: 15)
 */

$root = dirname(__DIR__);
$log = getenv('TEST_DB_PROFILE_LOG') ?: ($root . '/_out/querylog.jsonl');

if (!is_file($log)) {
  fwrite(STDERR, "No existe log: {$log}\n\n");
  fwrite(STDERR, "Tip: activá profiling con TEST_DB_PROFILE=1 y corré tests que pegan a DB.\n");
  exit(2);
}

$tables = $cols = $whereCols = $joinCols = $orderCols = $combo = [];
$slow = [];
$slowByTable = [];
$n = 0;

$fh = fopen($log, 'rb');
if (!$fh) { fwrite(STDERR, "No se pudo abrir {$log}\n"); exit(2); }

while (($line = fgets($fh)) !== false) {
  $line = trim($line);
  if ($line === '') continue;

  $row = json_decode($line, true);
  if (!is_array($row)) continue;

  $n++;

  foreach (($row['tables'] ?? []) as $t) $tables[$t] = ($tables[$t] ?? 0) + 1;
  foreach (($row['cols'] ?? []) as $c) $cols[$c] = ($cols[$c] ?? 0) + 1;
  foreach (($row['whereCols'] ?? []) as $c) $whereCols[$c] = ($whereCols[$c] ?? 0) + 1;
  foreach (($row['joinCols'] ?? []) as $c)  $joinCols[$c]  = ($joinCols[$c] ?? 0) + 1;
  foreach (($row['orderCols'] ?? []) as $c) $orderCols[$c] = ($orderCols[$c] ?? 0) + 1;

  $byTable = [];
  foreach (($row['whereCols'] ?? []) as $tc) {
    $parts = explode('.', $tc, 2);
    if (count($parts) === 2) $byTable[$parts[0]][] = $parts[1];
  }
  foreach ($byTable as $t => $cc) {
    $cc = array_values(array_unique($cc));
    sort($cc);
    if (count($cc) >= 2) {
      $cc = array_slice($cc, 0, 3);
      $k = $t . ':(' . implode(',', $cc) . ')';
      $combo[$k] = ($combo[$k] ?? 0) + 1;
    }
  }

  $ms = (int)($row['ms'] ?? 0);
  $sql = (string)($row['sql'] ?? '');
  $driver = (string)($row['driver'] ?? 'unknown');
  $tlist = (array)($row['tables'] ?? []);

  $slow[] = [$ms, $sql, $driver, $tlist];

  foreach ($tlist as $t) {
    if (!isset($slowByTable[$t])) $slowByTable[$t] = ['count'=>0,'ms_sum'=>0,'ms_max'=>0,'samples'=>[]];
    $slowByTable[$t]['count']++;
    $slowByTable[$t]['ms_sum'] += $ms;
    if ($ms > $slowByTable[$t]['ms_max']) $slowByTable[$t]['ms_max'] = $ms;
    if (count($slowByTable[$t]['samples']) < 3) $slowByTable[$t]['samples'][] = preg_replace('/\s+/', ' ', trim($sql));
  }
}
fclose($fh);

arsort($tables);
arsort($cols);
arsort($whereCols);
arsort($joinCols);
arsort($orderCols);
arsort($combo);
usort($slow, fn($a,$b) => $b[0] <=> $a[0]);

function norm_sql(string $sql): string {
  $s = trim($sql);
  $s = preg_replace('/--.*$/m', '', $s);
  $s = preg_replace('/\/\*.*?\*\//s', '', $s);
  $s = preg_replace('/\s+/', ' ', trim($s));
  $s = rtrim($s, ';');
  $s = preg_replace("/'([^'\\\\]|\\\\.)*'/", "'?'", $s);
  $s = preg_replace('/"([^"\\\\]|\\\\.)*"/', '"?"', $s);
  $s = preg_replace('/\b\d+(\.\d+)?\b/', '?', $s);
  $s = preg_replace('/\bIN\s*\(\s*\?(\s*,\s*\?)+\s*\)/i', 'IN (?)', $s);
  return $s;
}

function explain_suggest(string $driver, string $sql): string {
  $s = norm_sql($sql);
  $d = strtolower($driver);
  if ($d === 'pgsql' || $d === 'postgres' || $d === 'postgresql') {
    return "EXPLAIN (ANALYZE, BUFFERS) " . $s . ";";
  }
  return "EXPLAIN " . $s . ";";
}

function top(array $arr, int $n): array {
  $out = [];
  $i = 0;
  foreach ($arr as $k => $v) { $out[$k] = $v; if (++$i >= $n) break; }
  return $out;
}

$topN  = (int)(getenv('TEST_DB_PROFILE_TOP') ?: 20);
$slowN = (int)(getenv('TEST_DB_PROFILE_SLOW') ?: 15);

echo "== DB Query Profile Report ==\n";
echo "log: {$log}\n";
echo "queries: {$n}\n\n";

echo "Top tablas (hits)\n";
foreach (top($tables, $topN) as $t => $c) echo str_pad((string)$c, 6, ' ', STR_PAD_LEFT) . "  {$t}\n";

echo "\nTop columnas (table.col)\n";
foreach (top($cols, $topN) as $cname => $c) echo str_pad((string)$c, 6, ' ', STR_PAD_LEFT) . "  {$cname}\n";

echo "\nTop columnas en WHERE (hits)\n";
foreach (top($whereCols, $topN) as $cname => $c) echo str_pad((string)$c, 6, ' ', STR_PAD_LEFT) . "  {$cname}\n";

echo "\nTop columnas en JOIN/ON (hits)\n";
foreach (top($joinCols, $topN) as $cname => $c) echo str_pad((string)$c, 6, ' ', STR_PAD_LEFT) . "  {$cname}\n";

echo "\nTop combos WHERE por tabla (candidatos índice compuesto)\n";
foreach (top($combo, $topN) as $k => $c) echo str_pad((string)$c, 6, ' ', STR_PAD_LEFT) . "  {$k}\n";

echo "\nSlow queries (top {$slowN})\n";
$i = 0;
foreach ($slow as [$ms, $sql, $driver, $tlist]) {
  echo str_pad((string)$ms, 6, ' ', STR_PAD_LEFT) . " ms  " . preg_replace('/\s+/', ' ', trim($sql)) . "\n";
  echo "          " . explain_suggest($driver, $sql) . "\n";
  if (++$i >= $slowN) break;
}

echo "\nNotas\n";
echo "- Heurístico (regex): sirve para detectar patrones, no como verdad absoluta.\n";
echo "- Para decidir índices: cruzá WHERE/JOIN + combos + slow + EXPLAIN.\n";