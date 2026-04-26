# InfluxDB Query Profiling

Influx Query Profiling agrega a TestKit una primera capa de **discovery + ranking + reporte accionable** para consultas Influx ejecutadas durante tests PHP.

Está apagado por defecto y se activa con:

```bash
TESTKIT_INFLUX_PROFILE=1 php runTest.php back-php
php scripts/influx_query_report.php
```

## Diferencia con MySQL profiling

No copia el modelo de MySQL uno a uno. En MySQL el profiler puede complementar el ranking con `EXPLAIN FORMAT=JSON`; en InfluxDB esta fase no tiene un equivalente universal y seguro. Por eso el análisis de Influx es **heurístico**: observa la forma de la query y marca riesgos típicos.

El reporte no prueba que una query sea mala. Señala consultas que merecen inspección por señales como:

- falta de `range()` / `WHERE time`;
- rango temporal amplio;
- falta de filtros por tags;
- filtros principales sobre fields;
- `pivot()` antes de filtrar;
- `join()`;
- `group()` amplio;
- regex/contains amplios;
- `sort()` sin `limit()`;
- demasiadas llamadas repetidas;
- alto costo acumulado o latencia individual alta.

## Activación

```bash
export TESTKIT_INFLUX_PROFILE=1
php runTest.php back-php
php scripts/influx_query_report.php
```

Variables principales:

```bash
TESTKIT_INFLUX_PROFILE=1
TESTKIT_INFLUX_PROFILE_RUN_ID=
TESTKIT_INFLUX_PROFILE_TOP_N=20
TESTKIT_INFLUX_PROFILE_SLOW_MAX_MS=800
TESTKIT_INFLUX_PROFILE_HOTSPOT_TOTAL_MS=3000
TESTKIT_INFLUX_PROFILE_HIGH_CALLS=100
TESTKIT_INFLUX_PROFILE_WATCH_RATIO=0.75
TESTKIT_INFLUX_PROFILE_MAX_QUERY_LENGTH=4000
TESTKIT_INFLUX_PROFILE_REQUIRE_RANGE=1
TESTKIT_INFLUX_PROFILE_REQUIRE_TAG_FILTERS=0
TESTKIT_INFLUX_PROFILE_MAX_RANGE_HOURS=168
TESTKIT_INFLUX_PROFILE_TAG_FILTERS=charger_id,station_id,connector_id,site_id,tenant_id,device_id,host
```

Salidas:

```text
.testkit/reports/influx_profile_latest.json
.testkit/history/influx_profile/
.testkit/influx_profile/shards/<run_id>/
```

Según `TESTKIT_ARTIFACTS_ROOT`, esos paths pueden resolverse bajo otro directorio de artefactos.

## API pública

Registro explícito:

```php
tk_influx_profile_record(
    $query,
    $durationMs,
    'flux',
    'sessions.test.php',
    'InfluxSessionRepository.php:42'
);
```

Wrapper simple:

```php
$result = tk_influx_profile_wrap(
    fn() => $client->query($query),
    $query,
    'flux',
    'sessions.test.php'
);
```

Wrapper con cliente opaco:

```php
$result = tk_profiled_influx_query(
    $client,
    $query,
    static fn($client, string $query) => $client->query($query),
    'flux'
);
```

El wrapper mide duración, registra en `finally` y no oculta excepciones originales.

## Fingerprint y sanitización

El normalizador reemplaza strings, números, timestamps, UUIDs, URLs y valores sensibles por `?`. Mantiene la estructura principal del pipeline Flux y funciones relevantes como `from`, `range`, `filter`, `aggregateWindow`, `pivot`, `group`, `join`, `map`, `window` y `yield`.

Ejemplo:

```flux
from(bucket: "prod_metrics")
  |> range(start: -30d)
  |> filter(fn: (r) => r._measurement == "charger_sessions" and r.charger_id == "ABC123")
```

se agrupa como:

```flux
from(bucket: ?) |> range(start: ?) |> filter(fn: (r) => r._measurement == ? and r.charger_id == ?)
```

## Clasificaciones

Cada fingerprint obtiene una clasificación principal:

- `ok`: sin señales relevantes;
- `watch`: cerca de umbrales o con flags de observación;
- `slow`: `max_ms >= TESTKIT_INFLUX_PROFILE_SLOW_MAX_MS`;
- `hotspot`: `total_ms >= TESTKIT_INFLUX_PROFILE_HOTSPOT_TOTAL_MS`;
- `n_plus_one_candidate`: muchas llamadas del mismo fingerprint con latencia individual baja/media;
- `risky_query`: heurística con al menos un flag `warn`.

Además se conservan `risk_flags` y `risk_severity` para no perder el dato cuando una query es lenta y riesgosa a la vez.

## Reporte JSON

El reporte estable se escribe en:

```text
.testkit/reports/influx_profile_latest.json
```

Campos principales:

```json
{
  "report_version": 1,
  "engine": "influx",
  "profile_enabled": true,
  "summary": {
    "total_queries": 184,
    "unique_fingerprints": 12,
    "total_query_time_ms": 4200,
    "slow_count": 2,
    "hotspot_count": 1,
    "n_plus_one_candidates": 0,
    "risky_count": 4
  },
  "rankings": {
    "by_max_ms": [],
    "by_total_ms": [],
    "by_calls": [],
    "by_avg_ms": [],
    "by_risk": []
  },
  "queries": [],
  "recommendations": [],
  "limitations": []
}
```

## Reporte humano

```bash
php scripts/influx_query_report.php
```

Muestra:

- resumen general;
- top por latencia máxima;
- top por costo acumulado;
- top por llamadas;
- queries riesgosas;
- recomendaciones accionables;
- path del reporte leído.

Maneja correctamente reporte inexistente, vacío o JSON inválido.

## Ejemplo simulado

```bash
TESTKIT_INFLUX_PROFILE=1 php examples/influx-query-profiling/simulated_profile.php
php scripts/influx_query_report.php
```

El ejemplo registra consultas OK, sin rango, con `pivot()` temprano, con `join()`, repetidas tipo N+1 y una lenta.

## Limitaciones

- PHP userland no puede interceptar automáticamente todos los clientes Influx existentes.
- No se guardan tokens, URLs sensibles, orgs, buckets privados ni valores literales de queries.
- El análisis estático es heurístico; no reemplaza mediciones reales ni conocimiento de cardinalidad del bucket.
- No hay performance gates todavía.
- No se aplican optimizaciones automáticas.
- No requiere servidor Influx real para tests unitarios.

## Fase futura

Siguientes mejoras razonables:

- adapters opcionales para clientes Influx concretos usados por proyectos consumidores;
- baseline histórico por fingerprint;
- detección de regresiones;
- reglas configurables por suite/módulo;
- performance gates opt-in después de estabilizar umbrales.
