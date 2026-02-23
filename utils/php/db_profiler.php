<?php
declare(strict_types=1);

/**
 * Query profiler (genérico) para tests.
 *
 * Objetivo:
 * - Medir cuántas queries se ejecutan, cuánto tardan y contra qué tablas/columnas pegan.
 * - Dejar un log reproducible que te ayude a decidir índices (por frecuencia / hot columns).
 *
 * Limitación intencional:
 * - El parser SQL es *best-effort* (regex), no un SQL parser completo.
 * - Sirve para 80/20: FROM/JOIN + columnas en WHERE/ON/ORDER BY.
 */

final class TK_QueryLog {
  private string $path;
  private string $driver;

  /** @var array<string,int> */
  private array $tableHits = [];
  /** @var array<string,int> */
  private array $columnHits = []; // "table.col" => count

  private int $n = 0;
  private int $slowN = 0;
  private float $slowMs = 0.0;

  public function __construct(string $path, string $driver = 'unknown') {
    $this->driver = $driver;
    $this->path = $path;
    @mkdir(dirname($path), 0775, true);
  }

  public function record(string $sql, float $ms, ?int $rowCount = null, ?string $err = null): void {
    $this->n++;
    if ($ms > $this->slowMs) { $this->slowMs = $ms; $this->slowN = $this->n; }

    $d = TK_SqlHeuristics::extractDetailed($sql);
    $tables = $d['tables'];
    $cols = $d['cols'];
    foreach ($tables as $t) $this->tableHits[$t] = ($this->tableHits[$t] ?? 0) + 1;
    foreach ($cols as $c)   $this->columnHits[$c] = ($this->columnHits[$c] ?? 0) + 1;

    $row = [
      'ts' => date('c'),
      'driver' => $this->driver,
      'ms' => (int)round($ms),
      'rowCount' => $rowCount,
      'tables' => $tables,
      'cols' => $cols,
      'whereCols' => $d['whereCols'],
      'joinCols' => $d['joinCols'],
      'orderCols' => $d['orderCols'],
      'groupCols' => $d['groupCols'],
      'sql' => $sql,
    ];
    if ($err !== null) $row['error'] = $err;
    file_put_contents($this->path, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
  }

  /** @return array{tables:array<string,int>, columns:array<string,int>, queries:int} */
  public function snapshot(): array {
    return [
      'queries' => $this->n,
      'tables' => $this->tableHits,
      'columns' => $this->columnHits,
    ];
  }
}

final class TK_SqlHeuristics {
  /**
   * Parser SQL *heurístico* pensado para tests (80/20).
   *
   * Qué extrae:
   * - tablas: FROM/JOIN
   * - cols: todos los tokens table.col que aparezcan
   * - whereCols: tokens table.col dentro de WHERE
   * - joinCols: tokens table.col dentro de ON (JOIN)
   * - orderCols/groupCols: tokens table.col dentro de ORDER BY / GROUP BY
   *
   * Nota: NO es un parser SQL real. Si el SQL es complejo (subqueries profundas, CTE, etc.),
   * tomalo como una señal, no como verdad absoluta.
   *
   * @return array{tables:list<string>, cols:list<string>, whereCols:list<string>, joinCols:list<string>, orderCols:list<string>, groupCols:list<string>}
   */
  public static function extractDetailed(string $sql): array {
    $s = self::strip_literals($sql);
    $s = preg_replace('/\s+/', ' ', $s ?? $sql);
    $lower = strtolower($s ?? $sql);

    // --- Tablas (FROM/JOIN) ---
    $tables = [];
    if (preg_match_all('/\b(from|join)\s+([`"\[]?[a-z0-9_\.]+[`"\]]?)/i', $lower, $m)) {
      foreach ($m[2] as $t) {
        $t = trim($t, "`\"[]");
        $t = preg_replace('/\s.*/', '', $t) ?? $t; // quita alias pegado
        if ($t !== '') $tables[] = $t;
      }
    }

    // --- Segmentos ---
    $whereSeg = self::segment($lower, ' where ', [' group by ', ' order by ', ' limit ', ' having ']);
    $orderSeg = self::segment($lower, ' order by ', [' limit ', ' where ', ' group by ', ' having ']); // order suele ir al final
    $groupSeg = self::segment($lower, ' group by ', [' order by ', ' limit ', ' where ', ' having ']);

    // ON segments: por cada JOIN, tomamos el " on ... " inmediato (best-effort)
    $joinSegs = [];
    if (preg_match_all('/\bjoin\b.*?\bon\b\s+(.*?)(?=\bjoin\b|\bwhere\b|\bgroup\s+by\b|\border\s+by\b|\blimit\b|$)/i', $lower, $jm)) {
      foreach ($jm[1] as $seg) $joinSegs[] = $seg;
    }

    // --- Columnas ---
    $cols = self::extract_cols($lower);
    $whereCols = self::extract_cols($whereSeg);
    $orderCols = self::extract_cols($orderSeg);
    $groupCols = self::extract_cols($groupSeg);

    $joinCols = [];
    foreach ($joinSegs as $seg) {
      foreach (self::extract_cols($seg) as $c) $joinCols[] = $c;
    }

    // normaliza + únicos
    $tables = array_values(array_unique($tables));
    $cols = array_values(array_unique($cols));
    $whereCols = array_values(array_unique($whereCols));
    $joinCols = array_values(array_unique($joinCols));
    $orderCols = array_values(array_unique($orderCols));
    $groupCols = array_values(array_unique($groupCols));

    return [
      'tables' => $tables,
      'cols' => $cols,
      'whereCols' => $d['whereCols'],
      'joinCols' => $d['joinCols'],
      'orderCols' => $d['orderCols'],
      'groupCols' => $d['groupCols'],
      'whereCols' => $whereCols,
      'joinCols' => $joinCols,
      'orderCols' => $orderCols,
      'groupCols' => $groupCols,
    ];
  }

  /** Compat: devuelve [tables, cols] */
  public static function extract(string $sql): array {
    $d = self::extractDetailed($sql);
    return [$d['tables'], $d['cols']];
  }

  /** @return list<string> */
  private static function extract_cols(string $sql): array {
    $cols = [];
    if ($sql === '') return $cols;
    if (preg_match_all('/\b([a-z0-9_]+)\.([a-z0-9_]+)\b/i', $sql, $m2)) {
      $n = count($m2[0]);
      for ($i = 0; $i < $n; $i++) $cols[] = $m2[1][$i] . '.' . $m2[2][$i];
    }
    return $cols;
  }

  /**
   * Devuelve el substring que arranca en $needle y corta en el primer terminador encontrado.
   * Si no encuentra needle, devuelve "".
   */
  private static function segment(string $sql, string $needle, array $terminators): string {
    $pos = strpos($sql, $needle);
    if ($pos === false) return '';
    $start = $pos + strlen($needle);
    $rest = substr($sql, $start);
    $cut = strlen($rest);
    foreach ($terminators as $t) {
      $p = strpos($rest, $t);
      if ($p !== false && $p < $cut) $cut = $p;
    }
    return trim(substr($rest, 0, $cut));
  }

  private static function strip_literals(string $sql): string {
    $s = preg_replace("/'(?:''|[^'])*'/", "'?'", $sql);
    $s = preg_replace('/\b\d+\b/', '0', $s ?? $sql);
    return $s ?? $sql;
  }
}

final class TK_ProfiledPDO {
 {
  private \PDO $pdo;
  private TK_QueryLog $log;

  public function __construct(\PDO $pdo, TK_QueryLog $log) {
    $this->pdo = $pdo;
    $this->log = $log;
  }

  public function query(string $sql): \PDOStatement|false {
    $t0 = microtime(true);
    try {
      $st = $this->pdo->query($sql);
      $ms = (microtime(true) - $t0) * 1000;
      $this->log->record($sql, $ms, $st ? $st->rowCount() : null);
      return $st;
    } catch (\Throwable $e) {
      $ms = (microtime(true) - $t0) * 1000;
      $this->log->record($sql, $ms, null, get_class($e) . ': ' . $e->getMessage());
      throw $e;
    }
  }

  public function prepare(string $sql, array $options = []): \PDOStatement|false {
    $st = $this->pdo->prepare($sql, $options);
    if (!$st) return false;
    return new TK_ProfiledPDOStatement($st, $sql, $this->log);
  }

  public function exec(string $sql): int|false {
    $t0 = microtime(true);
    try {
      $n = $this->pdo->exec($sql);
      $ms = (microtime(true) - $t0) * 1000;
      $this->log->record($sql, $ms, is_int($n) ? $n : null);
      return $n;
    } catch (\Throwable $e) {
      $ms = (microtime(true) - $t0) * 1000;
      $this->log->record($sql, $ms, null, get_class($e) . ': ' . $e->getMessage());
      throw $e;
    }
  }

  /** passthrough */
  public function __call(string $name, array $args): mixed { return $this->pdo->$name(...$args); }
}

final class TK_ProfiledPDOStatement extends \PDOStatement {
  private \PDOStatement $inner;
  private string $sql;
  private TK_QueryLog $log;

  public function __construct(\PDOStatement $inner, string $sql, TK_QueryLog $log) {
    $this->inner = $inner;
    $this->sql = $sql;
    $this->log = $log;
  }

  public function execute(?array $params = null): bool {
    $t0 = microtime(true);
    try {
      $ok = $params === null ? $this->inner->execute() : $this->inner->execute($params);
      $ms = (microtime(true) - $t0) * 1000;
      $this->log->record($this->sql, $ms, $this->inner->rowCount());
      return $ok;
    } catch (\Throwable $e) {
      $ms = (microtime(true) - $t0) * 1000;
      $this->log->record($this->sql, $ms, null, get_class($e) . ': ' . $e->getMessage());
      throw $e;
    }
  }

  public function __call(string $name, array $args): mixed { return $this->inner->$name(...$args); }
}

/**
 * Factory: PDO “profilado” si TEST_DB_PROFILE=1.
 *
 * Env esperado (dentro de test/.env.test):
 * - TEST_DB_DSN, TEST_DB_USER, TEST_DB_PASS
 * - TEST_DB_PROFILE=0|1
 * - TEST_DB_PROFILE_LOG=/app/test/_out/querylog.jsonl (default)
 */
function tk_pdo(): TK_ProfiledPDO|\PDO {
  $dsn  = (string)(getenv('TEST_DB_DSN') ?: '');
  if ($dsn === '') {
    throw new RuntimeException('TEST_DB_DSN vacío. Definilo en test/.env.test');
  }
  $user = (string)(getenv('TEST_DB_USER') ?: '');
  $pass = (string)(getenv('TEST_DB_PASS') ?: '');

  $pdo = new \PDO($dsn, $user, $pass, [
    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
  ]);

  $on = (getenv('TEST_DB_PROFILE') ?: '0') === '1';
  if (!$on) return $pdo;

  $logPath = (string)(getenv('TEST_DB_PROFILE_LOG') ?: (dirname(__DIR__, 2) . '/_out/querylog.jsonl'));
  $driver = strtolower(strtok($dsn, ':')) ?: 'unknown';
  $log = new TK_QueryLog($logPath, $driver);
  return new TK_ProfiledPDO($pdo, $log);
}
