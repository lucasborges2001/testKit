# Contrato `mysql-query-baseline-v1`

## Propósito

`mysql-query-baseline-v1` conserva una referencia SQL explícita, versionable y reproducible creada desde un reporte `mysql-query-profile-report-v2`. No es una policy, no es un gate y nunca se actualiza durante una ejecución normal.

## Documento raíz

```json
{
  "schema_version": "mysql-query-baseline-v1",
  "baseline": {
    "id": "pruebas.back_php.catalog.v1",
    "description": "Baseline de catálogo",
    "created_at": "2026-07-11T10:00:00Z",
    "source": {},
    "compatibility": {},
    "comparison_defaults": {},
    "global": {},
    "queries": []
  }
}
```

Las claves desconocidas se rechazan. Los errores contractuales incluyen una ruta JSON estable.

## Origen

`baseline.source` contiene solamente metadata pública:

```text
repository
commit_sha
branch
run_id
profile_schema_version
profile_hash
policy_hash opcional
```

`profile_hash` es SHA-256. No se almacenan DSN, credenciales, hostname privado, parámetros SQL, payloads ni paths absolutos.

## Compatibilidad

Campos:

```text
engine                       mysql
engine_version               versión declarada
engine_version_mode          exact | major_minor | major | ignore
dataset_id
dataset_version
dataset_hash                 SHA-256 opcional
dataset_hash_mode            exact | warn | ignore
environment_id
suite_id
suite_scope                  exact | global
```

Defaults:

```text
engine_version_mode = major_minor
dataset_hash_mode = exact
suite_scope = exact
```

`ignore` debe quedar escrito en el baseline; nunca se infiere.

## Identidad de query

1. Si existe exactamente un `query_id` estable: `query_id:<id>`.
2. En otro caso: `fingerprint:<sha256(fingerprint normalizado)>`.
3. Varios query IDs generan `identity_status=ambiguous_query_ids` y fallback a fingerprint.
4. Nunca se usa posición de array o `sample_sql` crudo como identidad.

Cada identidad es única dentro del baseline.

## Métricas

Globales:

```text
total_queries
unique_fingerprints
total_sql_time_ms
instrumentation_findings
uninstrumented_findings
connections_observed
```

Por query:

```text
calls
min_ms
avg_ms
max_ms
total_ms
p50_ms
p95_ms
p99_ms
standard_deviation_ms
sample_count
percentiles_approximate
classification
capture_methods
modules
scenarios
suites
tests
```

Se valida `min <= avg <= max`, `p50 <= p95 <= p99` y `calls >= sample_count` cuando los campos están presentes.

## Plan normalizado

`plan` conserva únicamente estructura estable:

```text
status
signature SHA-256
flags
access_types
keys_used
possible_keys
estimated_rows
tables ordenadas
severity
policy_violations
```

No se persiste JSON crudo de `EXPLAIN` ni costos volátiles sin contrato.

## Tolerancias

Defaults:

```text
time_regression_pct
time_regression_abs_ms
calls_regression_pct
calls_regression_abs
rows_regression_pct
rows_regression_abs
minimum_time_ms_for_pct
ignore_metrics
```

Una query puede declarar `comparison` con las mismas claves. No se admiten callbacks, regex, scripts ni expresiones dinámicas. Este formato es independiente de `mysql-query-policy-v1`.

## Límites

```text
archivo <= 10 MB
queries <= 10000
resultados visibles <= 5000
listas de contexto <= 100
sample_sql <= 2000 caracteres
```

Todos los números deben ser finitos y no negativos.

## Creación explícita

```bash
php scripts/query_baseline.php create \
  --profile .testkit/reports/mysql_profile_latest.json \
  --output test/sql/baselines/back-php.v1.json \
  --baseline-id pruebas.back_php.v1 \
  --dataset-id pruebas-fixtures \
  --dataset-version 1 \
  --environment-id github-actions-mysql84
```

Un archivo existente no se reemplaza sin `--force`. Las ejecuciones de profiling no promueven ni aceptan baselines automáticamente.
