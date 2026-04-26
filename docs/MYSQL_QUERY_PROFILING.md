# MySQL Query Profiling

Primera versión de observabilidad para queries MySQL durante tests PHP.

## Activación

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

TestKit actual escribe artefactos bajo `.testkit/` por convención del repo:

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

## Captura PDO

PHP userland no puede interceptar de forma transparente todos los `new PDO(...)` existentes. Por eso esta versión expone integración opt-in.

### Opción recomendada: constructor perfilado

```php
$pdo = tk_profiled_pdo($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
```

Captura:

- `PDO::query()`
- `PDO::exec()`
- `PDOStatement::execute()` de statements preparados

### Opción para PDO existente

```php
$pdo = tk_mysql_profile_enable_pdo($pdo);
$stmt = $pdo->prepare('select * from users where id = ?');
$stmt->execute([$id]);
```

Esta opción captura `execute()` de statements preparados creados después de habilitar el atributo. No captura `query()` ni `exec()` directos de ese PDO existente.

### Hook manual para mysqli u otros adapters

```php
$start = microtime(true);
$result = mysqli_query($conn, $sql);
tk_mysql_profile_record($sql, (microtime(true) - $start) * 1000);
```

## Fingerprint

El fingerprint normaliza queries para agrupar equivalentes:

- strings -> `?`
- números -> `?`
- fechas/timestamps -> `?`
- UUIDs -> `?`
- `IN (?, ?, ?)` -> `IN (?)`
- espacios normalizados
- SQL en minúsculas consistente

Ejemplo:

```sql
SELECT * FROM transactions WHERE user_id = 123 AND status = 'paid'
```

se agrupa como:

```sql
select * from transactions where user_id = ? and status = ?
```

## Ranking

El reporte JSON incluye:

- `by_max_ms`: peor latencia individual
- `by_total_ms`: mayor costo acumulado
- `by_calls`: queries más repetidas
- `by_avg_ms`: peor promedio

## Clasificación

Criterios por defecto:

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

## Limitaciones de esta fase

- Solo MySQL.
- No InfluxDB todavía.
- No ejecuta `EXPLAIN`.
- No sugiere índices automáticamente.
- No bloquea pipeline por queries lentas.
- No captura mágicamente todo `new PDO(...)`; requiere helper/wrapper/factory o hook manual.
- Observabilidad y diagnóstico, no enforcement.

## Lectura rápida

```bash
php scripts/query_report.php
```

Salida esperada:

```text
MySQL Query Profile

Summary
- Total queries: 1840
- Unique fingerprints: 37
- Total DB time: 8420 ms
- Slow queries: 3
- Hotspots: 4
- N+1 candidates: 2

Top by max latency
1. 842 ms total | 842 ms max | 70.1 ms avg | 12 calls | slow | select ...
```
