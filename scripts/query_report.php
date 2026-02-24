<?php
declare(strict_types=1);

// File: test/scripts/query_report.php
// Lee test/_out/querylog.jsonl y genera un reporte de “hot tables/columns”.

$root = dirname(__DIR__);
$log = getenv('TEST_DB_PROFILE_LOG') ?: ($root . '/_out/querylog.jsonl');

if (!is_file($log)) {
  fwrite(STDERR, "No existe log: {$log}\n\n");
  fwrite(STDERR, "Tip: activá profiling con TEST_DB_PROFILE=1 y corré los tests que pegan a DB.\n");
  exit(2);
}

$tables = [];
$cols = [];
$whereCols = [];
$joinCols = [];
$orderCols = [];
$combo = []; // 'table:(a,b)' => count
$slow = []; // [ms, sql, driver, tables]
$slowByTable = []; // table => {count, ms_sum, ms_max, samples[]}
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
  foreach (($row['joinCols'] ?? []) as $c)  $joinCols[$c] = ($joinCols[$c] ?? 0) + 1;
  foreach (($row['orderCols'] ?? []) as $c) $orderCols[$c] = ($orderCols[$c] ?? 0) + 1;

  // combos: columnas de WHERE por tabla (candidate para índice compuesto)
  $byTable = [];
  foreach (($row['whereCols'] ?? []) as $tc) {
    $parts = explode('.', $tc, 2);
    if (count($parts) === 2) $byTable[$parts[0]][] = $parts[1];
  }
  foreach ($byTable as $t => $cc) {
    $cc = array_values(array_unique($cc));
    sort($cc);
    if (count($cc) >= 2) {
      // limitamos a 3 cols para no explotar
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
  // Agrupar por tabla (para ir directo al pain point)
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


/**
 * Normaliza SQL para sugerencias de EXPLAIN:
 * - colapsa espacios
 * - elimina ';' final
 * - reemplaza literales/nums por '?'
 * Nota: heurístico (no parser).
 */
function norm_sql(string $sql): string {
  $s = trim($sql);
  $s = preg_replace('/--.*$/m', '', $s);
  $s = preg_replace('/\/\*.*?\*\//s', '', $s);
  $s = preg_replace('/\s+/', ' ', trim($s));
  $s = rtrim($s, ';');
  // strings single/double
  $s = preg_replace("/'([^'\\\\]|\\\\.)*'/", "'?'", $s);
  $s = preg_replace('/"([^"\\\\]|\\\\.)*"/', '"?"', $s);
  // números (enteros/decimales)
  $s = preg_replace('/\b\d+(\.\d+)?\b/', '?', $s);
  // listas IN (?, ?, ?) -> IN (?)
  $s = preg_replace('/\bIN\s*\(\s*\?(\s*,\s*\?)+\s*\)/i', 'IN (?)', $s);
  return $s;
}

function explain_suggest(string $driver, string $sql): string {
  $s = norm_sql($sql);
  $d = strtolower($driver);
  if ($d === 'pgsql' || $d === 'postgres' || $d === 'postgresql') {
    return "EXPLAIN (ANALYZE, BUFFERS) " . $s . ";";
  }
  // default: MySQL/MariaDB
  return "EXPLAIN " . $s . ";";
}

function top(array $arr, int $n): array {
  $out = [];
  $i = 0;
  foreach ($arr as $k => $v) {
    $out[$k] = $v;
    $i++; if ($i >= $n) break;
  }
  return $out;
}

$topN = (int)(getenv('TEST_DB_PROFILE_TOP') ?: 20);
$slowN = (int)(getenv('TEST_DB_PROFILE_SLOW') ?: 15);

echo "== DB Query Profile Report ==\n";
echo "log: {$log}\n";
echo "queries: {$n}\n\n";

echo "Top tablas (hits)\n";
foreach (top($tables, $topN) as $t => $c) {
  echo str_pad((string)$c, 6, ' ', STR_PAD_LEFT) . "  {$t}\n";
}

echo "\nTop columnas (table.col)\n";
foreach (top($cols, $topN) as $cname => $c) {
  echo str_pad((string)$c, 6, ' ', STR_PAD_LEFT) . "  {$cname}\n";
}


echo "\nCandidatos de índices (heurístico)\n";
echo "Idea: priorizar columnas con muchos hits en WHERE/JOIN; considerar compuestos si aparecen juntas en WHERE.\n\n";

echo "Top columnas en WHERE (hits)\n";
foreach (top($whereCols, $topN) as $cname => $c) {
  echo str_pad((string)$c, 6, ' ', STR_PAD_LEFT) . "  {$cname}\n";
}

echo "\nTop columnas en JOIN/ON (hits)\n";
foreach (top($joinCols, $topN) as $cname => $c) {
  echo str_pad((string)$c, 6, ' ', STR_PAD_LEFT) . "  {$cname}\n";
}

echo "\nTop combos de WHERE por tabla (candidatos de índice compuesto)\n";
foreach (top($combo, $topN) as $k => $c) {
  echo str_pad((string)$c, 6, ' ', STR_PAD_LEFT) . "  {$k}\n";
}

echo "\nSiguiente paso recomendado\n";
echo "- Para los top 5 candidatos: corré EXPLAIN / EXPLAIN ANALYZE sobre las queries lentas asociadas.\n";
echo "- Mirá cardinalidad/selectividad: un índice en columna de baja selectividad puede no servir.\n";
echo "- Si hay ORDER BY frecuente sobre misma tabla, evaluar índices que cubran (WHERE + ORDER BY).\n";


echo "\nSlow queries por tabla (top 10 por ms_max)\n";
$tblRank = [];
foreach ($slowByTable as $t => $st) {
  $avg = $st['count'] > 0 ? (int)round($st['ms_sum'] / $st['count']) : 0;
  $tblRank[$t] = ['ms_max' => (int)$st['ms_max'], 'avg' => $avg, 'count' => (int)$st['count'], 'samples' => $st['samples']];
}
uasort($tblRank, fn($a,$b) => ($b['ms_max'] <=> $a['ms_max']) ?: ($b['count'] <=> $a['count']));
$i=0;
foreach ($tblRank as $t => $st) {
  echo str_pad((string)$st['ms_max'], 6, ' ', STR_PAD_LEFT) . " ms_max  "
     . str_pad((string)$st['avg'], 6, ' ', STR_PAD_LEFT) . " ms_avg  "
     . str_pad((string)$st['count'], 5, ' ', STR_PAD_LEFT) . " hits  {$t}\n";
  foreach ($st['samples'] as $s) {
    echo "          - " . $s . "\n";
  }
  $i++; if ($i >= 10) break;
}

echo "\nSlow queries (top {$slowN})\n";
$i = 0;
foreach ($slow as [$ms, $sql, $driver, $tlist]) {
  echo str_pad((string)$ms, 6, ' ', STR_PAD_LEFT) . " ms  " . preg_replace('/\s+/', ' ', trim($sql)) . "\n";
  echo "          " . explain_suggest($driver, $sql) . "\n";
  $i++; if ($i >= $slowN) break;
}

echo "\nNotas\n";
echo "- 'columnas/where/join' es heurístico (regex). Usalo para detectar patrones, no como verdad absoluta.\n";
echo "- Para decidir índices: mirá top WHERE/JOIN, combos frecuentes y cruzalo con slow queries + EXPLAIN.\n";
echo "- Combos: se limitan a 3 columnas (top) para evitar ruido.
- Complemento MySQL: performance_schema / slow log (ver docs en test/docs/07_db_profiling.md).\n";