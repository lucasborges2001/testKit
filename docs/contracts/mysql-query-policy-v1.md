# Contrato `mysql-query-policy-v1`

## Propósito

`mysql-query-policy-v1` define expectativas declarativas sobre un reporte MySQL de testKit. No reemplaza las heurísticas `ok`, `watch`, `slow`, `hotspot` o `n_plus_one_candidate`: esas clasificaciones describen señales generales del profiler, mientras que una policy describe una expectativa específica del consumidor.

En Fase 3 el único modo permitido es:

```json
"mode": "report_only"
```

Una violation no modifica el exit code de la suite.

## Documento raíz

```json
{
  "schema_version": "mysql-query-policy-v1",
  "policy_set": {
    "id": "host.sql",
    "description": "Políticas SQL del host",
    "mode": "report_only",
    "defaults": {
      "severity": "warning",
      "on_missing": "report",
      "on_insufficient_data": "report"
    },
    "policies": []
  }
}
```

Claves desconocidas se rechazan. El loader informa la ruta JSON exacta del error.

## Límites

- archivo máximo: 2 MB;
- máximo de policies: 500;
- ID máximo: 160 caracteres;
- descripción de policy: 500 caracteres;
- descripción del set: 1000 caracteres;
- máximo de valores por selector: 50;
- máximo de resultados publicado: configurable entre 1 y 5000.

## Policies

```json
{
  "id": "catalog.product_search",
  "description": "Búsqueda principal",
  "scope": "query",
  "selector": {
    "module_id": "catalogo",
    "scenario_id": "product_search",
    "fingerprint": "select * from products where category_id = ?"
  },
  "budgets": {
    "max_calls": 3,
    "max_p95_ms": 75
  },
  "plan": {
    "forbid_flags": ["full_table_scan"],
    "require_any_key": true
  },
  "severity": "warning",
  "on_missing": "report",
  "on_insufficient_data": "report"
}
```

`scope` admite:

- `query`: requiere selector no vacío;
- `global`: prohíbe selector y restricciones de plan.

Una policy debe declarar al menos un budget o una restricción de plan.

## Selectores

Selectores permitidos:

```text
fingerprint
query_id
module_id
scenario_id
suite_id
test_id
capture_method
classification
source
caller
```

Semántica:

- las claves se combinan con AND;
- varios valores dentro de una clave se combinan con OR;
- no hay regex;
- `fingerprint` usa `SqlFingerprint::fingerprint()`;
- `capture_method` debe pertenecer al catálogo de Fase 1;
- `query_id` se asocia mediante findings declarados de EXPLAIN; no se deriva del orden de aparición.

Ejemplo OR dentro de una clave:

```json
{
  "module_id": ["catalogo", "search"],
  "scenario_id": "product_search"
}
```

## Budgets por query

| Clave | Evidencia | Tipo |
|---|---|---|
| `max_calls` | `queries[].calls` | entero >= 0 |
| `max_max_ms` | `queries[].max_ms` | número >= 0 |
| `max_avg_ms` | `queries[].avg_ms` | número >= 0 |
| `max_total_ms` | `queries[].total_ms` | número >= 0 |
| `max_p50_ms` | `queries[].p50_ms` | número >= 0 |
| `max_p95_ms` | `queries[].p95_ms` | número >= 0 |
| `max_p99_ms` | `queries[].p99_ms` | número >= 0 |
| `max_standard_deviation_ms` | `queries[].standard_deviation_ms` | número >= 0 |
| `max_rows_examined` | `explain.findings[].plan_summary.estimated_rows` | entero >= 0 |

## Budgets globales

Solo válidos con `scope: global`:

```text
max_total_queries
max_unique_fingerprints
max_total_sql_time_ms
max_instrumentation_findings
max_uninstrumented_findings
```

`max_uninstrumented_findings` cuenta findings estables asociados a captura incompleta, por ejemplo `query_without_connection`, `unknown_capture_method`, `bootstrap_not_confirmed`, `existing_pdo_partial_capture` y `mysqli_statement_sql_missing`.

## Restricciones de plan

```text
forbid_flags
require_any_key
require_keys
forbid_access_types
max_estimated_rows
```

Catálogo de flags permitido:

```text
full_table_scan
no_possible_keys
no_key_used
filesort
temporary_table
high_rows_examined
dependent_subquery
range_or_index_merge
invalid_json_plan
```

Sin finding `explain_status=analyzed`, la restricción queda `insufficient_data` o `not_evaluated` cuando `on_insufficient_data=ignore`. Nunca se considera `pass` por ausencia de evidencia.

## Precedencia

La precedencia se calcula por especificidad, no por posición en el JSON. Pesos estables:

```text
query_id       1000
fingerprint     900
test_id          80
suite_id         70
scenario_id      60
module_id        50
capture_method   30
classification   20
source            10
caller            10
scope global       0
```

Las policies se aplican desde menor hacia mayor especificidad. Cada clave de budget o plan se reemplaza independientemente. La evidencia conserva:

```text
source_policy_id
effective_policy_id
precedence_rank
```

Dos policies aplicables con igual precedencia y valores diferentes para la misma clave forman un conflicto irresoluble. La evaluación se rechaza como contrato inválido; no se utiliza el orden del archivo para desempatar.

## Estados

Catálogo estable:

```text
pass
violation
not_applicable
not_evaluated
insufficient_data
invalid_policy
legacy_report
```

Estados auxiliares de policy no utilizada:

```text
unused_no_match
unused_missing_context
unused_report_legacy
unused_explain_unavailable
```

Razones de evidencia insuficiente:

```text
missing_metric
missing_context
explain_not_enabled
legacy_report_field_missing
no_matching_query
corrupted_input_excluded
```

## Severidades

```text
info
warning
error
```

Son informativas en Fase 3.

## Resultado por budget

Cada resultado contiene al menos:

```text
policy_id
budget_key
status
severity
selector
matched_by
actual
expected
operator
delta
unit
evidence_path
message
effective_policy_id
source_policy_id
precedence_rank
```

## Sección del profile v2

El profile conserva `report_version: 2` y agrega:

```text
policy_evaluation
```

Campos principales:

```text
enabled
mode
schema_version
policy_set_id
policy_file
policy_file_hash
profile_schema_version
profile_report_version
profile_compatibility_status
loaded_policies
applicable_policies
unused_policies
evaluated_budgets
passed_budgets
violated_budgets
insufficient_data_budgets
status_counts
results
effective_policies
conflicts
warnings
```

Cada `queries[]` incorpora únicamente:

```text
policy_status
applied_policy_ids
violations_count
```

## Artefactos

Fuente lógica única: el array `policy_evaluation` generado por `MysqlQueryPolicyEvaluator`.

Se publica:

```text
.testkit/reports/mysql_profile_latest.json          # sección policy_evaluation
.testkit/reports/mysql_policy_latest.json           # proyección derivada
.testkit/history/mysql_policy/mysql_policy_*.json
```

Las escrituras del artefacto separado son temporales y se publican con `rename()`.

## Compatibilidad

- reportes v2: evaluación completa según evidencia disponible;
- reportes v1: métricas existentes pueden evaluarse; campos ausentes quedan `insufficient_data` con `legacy_report_field_missing`;
- versiones mayores a v2: incompatibilidad no recuperable;
- sin archivo de policies: evaluación desactivada y sin artefactos separados;
- profiling desactivado: no se ejecuta el reporter ni se crean artefactos.

## Seguridad

El contrato no permite código ejecutable, regex arbitrarias, `eval`, `unserialize`, interpolación SQL ni parámetros de query. Se rechazan claves desconocidas y se normalizan IDs, fingerprints y rutas. El reporte persiste un hash SHA-256 del archivo de policy y una ruta relativa/segura, no el contenido completo ni rutas absolutas.

## Fuera de alcance

```text
baseline entre commits
comparación histórica
delta porcentual
gates fail
dashboard
CREATE INDEX
migraciones
reescritura SQL
```
