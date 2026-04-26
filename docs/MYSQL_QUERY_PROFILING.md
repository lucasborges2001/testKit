# MySQL Query Profiling

TestKit expone observabilidad para queries MySQL durante tests PHP. La fase actual tiene dos capas:

1. **Query Discovery**: captura, agrupa, rankea y clasifica queries ejecutadas durante tests.
2. **EXPLAIN Analysis**: analiza opcionalmente planes de ejecución de queries seguras o declaradas.

El objetivo sigue siendo diagnóstico. No hay gates, no se bloquea el pipeline y no se aplican cambios de schema.

## Activación básica

El profiling está apagado por defecto.

```bash
TESTKIT_DB_PROFILE=1 php runTest.php back-php
php scripts/query_report.php
```

También sirve con targets agregados que ejecuten `back_php`:

```bash
TESTKIT_DB_PROFILE=1 php runTest.php back
php scripts/query_report.php
```

## Salidas

Por convención, TestKit escribe bajo `.testkit/`:

```text
.testkit/reports/mysql_profile_latest.json
.testkit/history/mysql_profile/mysql_profile_YYYYmmdd_HHMMSS.json
.testkit/mysql_profile/shards/<run_id>/*.json
```

Se puede cambiar por env:

```bash
TESTKIT_DB_PROFILE_REPORT_PATH=/tmp/mysql_profile_latest.json
TESTKIT_DB_PROFILE_HISTORY_PATH=/tmp/mysql_profile_history
TESTKIT_DB_PROFILE_SHARD_DIR=/tmp/mysql_profile_shards
```

## API pública para proyectos consumidores

Los proyectos consumidores no deberían depender de clases internas. Usar estos helpers.

### PDO recomendado

```php
$pdo = tk_profiled_pdo($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
```

Captura cuando `TESTKIT_DB_PROFILE=1`:

- `PDO::query()`
- `PDO::exec()`
- `PDOStatement::execute()` de statements preparados

### PDO existente

```php
$pdo = tk_mysql_profile_enable_pdo($pdo);
$stmt = $pdo->prepare('select * from users where id = ?');
$stmt->execute([$id]);
```

Esto solo puede capturar `execute()` de statements preparados **creados después** de llamar al helper. No intercepta `query()` ni `exec()` directos de un PDO existente.

### mysqli o wrappers propios

```php
$start = microtime(true);
$result = mysqli_query($conn, $sql);
tk_mysql_profile_record($sql, (microtime(true) - $start) * 1000, 'source opcional', 'caller opcional');
```

Para `mysqli_stmt` se puede asociar el SQL en prepare y registrar en execute:

```php
$stmt = $db->prepare($sql);
tk_mysql_profile_mysqli_remember($stmt, $sql);
$start = microtime(true);
$stmt->execute();
tk_mysql_profile_mysqli_record_execute($stmt, (microtime(true) - $start) * 1000);
tk_mysql_profile_mysqli_forget($stmt);
```

## Fingerprint y sanitización

El fingerprint agrupa queries equivalentes:

- strings -> `?`
- números -> `?`
- fechas/timestamps -> `?`
- UUIDs -> `?`
- booleanos/null -> `?`
- `IN (?, ?, ?)` -> `IN (?)`
- espacios normalizados
- SQL en minúsculas

Ejemplo:

```sql
SELECT * FROM transactions WHERE user_id = 123 AND status = 'paid'
```

se agrupa como:

```sql
select * from transactions where user_id = ? and status = ?
```

`sample_sql` también se sanitiza y trunca. No debe contener valores sensibles reales.

## Rankings

El reporte JSON incluye:

- `by_max_ms`: peor latencia individual
- `by_total_ms`: mayor costo acumulado
- `by_calls`: queries más repetidas
- `by_avg_ms`: peor promedio

## Clasificación

Defaults:

```yaml
slow_max_ms: 500
hotspot_total_ms: 3000
high_calls: 100
watch_ratio: 0.75
```

Clases:

- `ok`: sin señal inmediata
- `watch`: cerca de umbral
- `slow`: alta latencia individual
- `hotspot`: alto costo acumulado
- `n_plus_one_candidate`: muchas llamadas con latencia individual baja/media

Override por env:

```bash
TESTKIT_DB_PROFILE_TOP_N=20
TESTKIT_DB_PROFILE_SLOW_MAX_MS=500
TESTKIT_DB_PROFILE_HOTSPOT_TOTAL_MS=3000
TESTKIT_DB_PROFILE_HIGH_CALLS=100
TESTKIT_DB_PROFILE_WATCH_RATIO=0.75
TESTKIT_DB_PROFILE_MAX_SQL_LENGTH=2000
```

## EXPLAIN Analysis

EXPLAIN está apagado por defecto y requiere discovery activo:

```bash
TESTKIT_DB_PROFILE=1 TESTKIT_DB_PROFILE_EXPLAIN=1 php runTest.php back-php
php scripts/query_report.php
```

Configurable por env:

```bash
TESTKIT_DB_PROFILE_EXPLAIN=1
TESTKIT_DB_PROFILE_EXPLAIN_MAX_QUERIES=20
TESTKIT_DB_PROFILE_EXPLAIN_MIN_TOTAL_MS=0
TESTKIT_DB_PROFILE_EXPLAIN_MIN_MAX_MS=0
TESTKIT_DB_PROFILE_EXPLAIN_TIMEOUT_MS=2000
TESTKIT_DB_PROFILE_EXPLAIN_INCLUDE_CLASSES=slow,hotspot,watch,n_plus_one_candidate
```

Conexión para EXPLAIN:

- por defecto usa `TEST_DB_DSN`, `TEST_DB_USER`, `TEST_DB_PASS` si el DSN es `mysql:`;
- se puede overridear con `TESTKIT_DB_PROFILE_EXPLAIN_DSN`, `TESTKIT_DB_PROFILE_EXPLAIN_USER`, `TESTKIT_DB_PROFILE_EXPLAIN_PASS`;
- si no hay conexión, EXPLAIN se omite con `skip_reason=mysql_connection_unavailable`.

### Qué queries puede analizar automáticamente

Solo se consideran queries explainables:

- `SELECT`
- `WITH`
- un solo statement
- sin placeholders `?` ni `:name`

No se ejecuta EXPLAIN sobre:

- `INSERT`
- `UPDATE`
- `DELETE`
- `DROP`
- `ALTER`
- `CREATE`
- `TRUNCATE`
- múltiples statements

Las queries descubiertas suelen estar sanitizadas, por lo que pueden contener `?`. En ese caso se omiten con:

```json
"explain_status": "skipped",
"skip_reason": "sample_sql_not_executable"
```

TestKit no inventa valores para placeholders.

### Queries declaradas

Para analizar queries ejecutables, declarar ejemplos explícitos en JSON o YAML simple y apuntar el archivo:

```bash
TESTKIT_DB_PROFILE_EXPLAIN_QUERIES_FILE=./test/mysql-profile-explain.json
```

Ejemplo JSON:

```json
{
  "mysql_profile_explain": {
    "queries": [
      {
        "id": "user.lookup",
        "sql": "SELECT * FROM users WHERE id = 1",
        "max_rows_examined": 100,
        "forbid": ["full_table_scan", "filesort", "temporary_table"]
      }
    ]
  }
}
```

Ejemplo YAML simple:

```yaml
mysql_profile_explain:
  queries:
    - id: user.lookup
      sql: |
        SELECT * FROM users WHERE id = 1
      max_rows_examined: 100
      forbid:
        - full_table_scan
        - filesort
        - temporary_table
```

## Flags de EXPLAIN

El parser detecta, cuando el plan lo expone:

- `full_table_scan`
- `no_possible_keys`
- `no_key_used`
- `filesort`
- `temporary_table`
- `high_rows_examined`
- `dependent_subquery`
- `range_or_index_merge` como información, no warning automático

Cada finding incluye:

```json
{
  "query_id": "optional",
  "fingerprint": "...",
  "sample_sql": "...",
  "explain_status": "analyzed|skipped|failed",
  "skip_reason": "",
  "error": "",
  "plan_summary": {
    "tables": [],
    "access_types": [],
    "keys_used": [],
    "possible_keys": [],
    "estimated_rows": 0
  },
  "flags": ["full_table_scan"],
  "severity": "info|watch|warn",
  "recommendation": "..."
}
```

## Reporte humano

```bash
php scripts/query_report.php
php scripts/query_report.php --path /tmp/mysql_profile_latest.json
```

Muestra:

- resumen general;
- top por latencia/costo/calls;
- candidatos N+1;
- sección `Explain analysis`;
- findings por severidad;
- recomendaciones.

## Smoke simulado sin MySQL

El ejemplo no requiere servidor MySQL. Valida reporter/ranking y demuestra que EXPLAIN se omite claramente si no hay conexión:

```bash
php examples/mysql-query-profiling/simulated_profile.php
php scripts/query_report.php --path /tmp/<ruta-impresa>/reports/mysql_profile_latest.json
```

## Limitaciones actuales

- Solo MySQL.
- No InfluxDB.
- No performance gates.
- No `CREATE INDEX` automático ni cambios de schema.
- No se reemplazan valores de placeholders para EXPLAIN.
- EXPLAIN requiere una conexión MySQL disponible o queries declaradas ejecutables más credenciales.
- `EXPLAIN FORMAT=JSON` usa fallback a `EXPLAIN` tabular si JSON falla.
- La próxima fase razonable es análisis más profundo de plan/costos y baseline de planes; todavía no está implementada.
