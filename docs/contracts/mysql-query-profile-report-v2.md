# Contrato `mysql-query-profile-report-v2`

## Identidad

```json
{
  "report_version": 2,
  "schema_version": "mysql-query-profile-report-v2",
  "instrumentation_version": "1.0",
  "engine": "mysql"
}
```

`report_version: 2` incorpora telemetría de instrumentación y estadísticas robustas. Los lectores humanos aceptan v1 en modo legacy; los productores nuevos emiten v2.

## Campos raíz

| Campo | Tipo | Semántica |
|---|---|---|
| `profile_enabled` | boolean | profiling activo en la ejecución |
| `run_id`, `meta_run_id`, `suite_id` | string | metadatos normalizados |
| `capture_session_id` | string | sesión que aísla shards del mismo run |
| `started_at`, `finished_at` | ISO-8601 UTC | ventana consolidada |
| `duration_ms` | integer | diferencia de ventana, no suma SQL |
| `run_metadata.shards` | object | read/accepted/corrupt/foreign |
| `config` | object | configuración pública efectiva, sin credenciales |
| `summary` | object | totales y clasificaciones |
| `coverage` | object | hechos, completitud calculable y cobertura global desconocida |
| `connections` | array | conexiones observadas y capacidades |
| `instrumentation` | object | status, métodos, findings y limitaciones |
| `rankings` | object | top por max/total/calls/avg |
| `queries` | array | agregados por fingerprint |
| `recommendations` | array | diagnóstico de performance, no política |
| `explain` | object | contrato existente de EXPLAIN |
| `profiler_metrics` | object | overhead diagnóstico |
| `limitations` | array | límites explícitos de la fase |

## `queries[]`

Campos obligatorios producidos por v2:

```text
fingerprint, sample_sql, calls, min_ms, avg_ms, max_ms, total_ms,
p50_ms, p95_ms, p99_ms, standard_deviation_ms, sample_count,
percentiles_approximate, first_seen_at, last_seen_at,
sources, callers, tests, suites, workers, modules, scenarios,
connection_ids, capture_methods, context_counts, context_truncated,
classification, recommendation
```

`capture_methods` es un mapa `método -> cantidad`. La suma debe coincidir con `calls` para productores v2. `sample_sql` está sanitizado y no es necesariamente ejecutable para `EXPLAIN`.

## `coverage`

```json
{
  "facts": {
    "captured_queries": 0,
    "captured_unique_fingerprints": 0,
    "instrumented_connections": 0,
    "connections_with_queries": 0,
    "queries_with_source": 0,
    "queries_with_caller": 0,
    "queries_with_test_context": 0,
    "queries_with_connection": 0,
    "queries_with_module": 0,
    "queries_with_scenario": 0,
    "queries_by_capture_method": {}
  },
  "calculable": {
    "source_context_coverage_pct": null,
    "caller_context_coverage_pct": null,
    "test_context_coverage_pct": null,
    "connection_context_coverage_pct": null,
    "module_context_coverage_pct": null,
    "scenario_context_coverage_pct": null
  },
  "unknown": {
    "total_application_queries": null,
    "overall_capture_coverage_pct": null,
    "overall_capture_coverage_status": "unknown",
    "reason": "PHP userland cannot observe queries executed outside instrumented adapters"
  }
}
```

Los porcentajes de `calculable` usan `captured_queries` como denominador y son `null` si no hubo consultas capturadas. Nunca representan cobertura total de la aplicación.

## `connections[]`

```json
{
  "connection_id": "conn_<hash>",
  "adapter": "profiled_pdo",
  "engine": "mysql",
  "capture_capabilities": {
    "query": true,
    "exec": true,
    "prepare_execute": true,
    "transactions": true
  },
  "created_at": "...",
  "first_query_at": "...",
  "last_query_at": "...",
  "query_count": 0,
  "prepared_statement_count": 0,
  "transaction_count": 0,
  "instrumented": true
}
```

El ID no contiene DSN, hostname, usuario ni contraseña. Es efímero por run/proceso/objeto.

## `instrumentation.findings[]`

```json
{
  "code": "existing_pdo_partial_capture",
  "severity": "info",
  "message": "...",
  "context": {},
  "recommendation": "..."
}
```

Severidades admitidas: `info`, `watch`, `warn`. No son gates en v2.

## Estadísticas

El productor usa una muestra determinista acotada. `percentiles_approximate=false` solo cuando todas las observaciones del fingerprint están retenidas. La desviación es poblacional sobre la muestra retenida.

## Shards

Cada shard v2 incluye identidad de contrato, `run_id`, `capture_session_id`, `config_hash`, contexto de proceso, queries, conexiones, findings y métricas del collector. El agregador:

1. acepta solo el run solicitado;
2. acepta solo la sesión activa cuando está presente;
3. informa JSON corrupto;
4. ignora shards foráneos;
5. ordena resultados determinísticamente.

## Compatibilidad

- Lectores CLI: aceptan v1 y generan cobertura legacy desconocida.
- Reporter: normaliza filas v1 sin muestras usando min/avg/max como evidencia aproximada.
- Helpers públicos: argumentos nuevos son opcionales.
- No hay migración destructiva de archivos históricos.

## Invariantes de seguridad

- no persistir parámetros SQL;
- no incluir `connection` privada en `config`;
- no incluir claves sensibles en contextos;
- normalizar rutas absolutas;
- sanitizar errores y `EXPLAIN`;
- escribir JSON de forma atómica.

## Extensión compatible: `policy_evaluation`

Fase 3 mantiene `report_version: 2` y agrega una sección opcional `policy_evaluation`.

Cuando no hay archivo de policies:

```json
{
  "policy_evaluation": {
    "enabled": false,
    "mode": "report_only",
    "schema_version": "mysql-query-policy-v1",
    "results": []
  }
}
```

Cuando está habilitada, la sección contiene el resultado definido en `docs/contracts/mysql-query-policy-v1.md`. Los campos `classification` y `policy_status` no son equivalentes ni se reemplazan mutuamente.

Cada fila `queries[]` puede agregar:

```text
policy_status
applied_policy_ids
violations_count
```

Los lectores v2 existentes deben ignorar campos desconocidos. Los productores no cambian la identidad del reporte ni la semántica de rankings, coverage, instrumentation o explain.
