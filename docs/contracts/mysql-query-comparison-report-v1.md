# Contrato `mysql-query-comparison-report-v1`

## Propósito

Compara un reporte SQL actual contra un `mysql-query-baseline-v1` seleccionado explícitamente. El único modo de Fase 4 es `report_only`; una regresión no cambia el exit code de la suite o de la CLI.

## Documento raíz

```json
{
  "schema_version": "mysql-query-comparison-report-v1",
  "mode": "report_only",
  "comparison_id": "cmp_<hash>",
  "generated_at": "2026-07-11T10:00:00Z",
  "baseline": {},
  "current": {},
  "compatibility": {},
  "summary": {},
  "global_metrics": [],
  "queries": [],
  "new_queries": [],
  "removed_queries": [],
  "ambiguous_queries": [],
  "recommendations": [],
  "limitations": []
}
```

`comparison_id` deriva de los hashes del baseline y del current. Las colecciones se ordenan por identidad, no por ejecución.

## Compatibilidad

Estados:

```text
compatible
compatible_with_warnings
incompatible
insufficient_metadata
legacy_current
legacy_baseline
```

Scopes:

```text
full
structural_only
none
```

Las latencias solo se clasifican cuando engine, versión según modo, dataset, environment y suite son compatibles. Un entorno distinto permite evidencia estructural, pero las métricas temporales quedan `structural_only`.

## Deltas

Cada métrica publica:

```text
metric
baseline
current
delta
delta_pct
direction
status
confidence
reason
thresholds
baseline_approximate
current_approximate
```

Fórmulas:

```text
delta = current - baseline
delta_pct = delta / baseline * 100
```

Con baseline cero:

```json
{"delta_pct": null, "reason": "baseline_zero"}
```

Una métrica ausente produce `insufficient_data`. Un percentil muestreado produce `confidence=approximate`. Para tiempos se exigen conjuntamente umbral absoluto, porcentual y `minimum_time_ms_for_pct`.

## Estados de query

```text
unchanged
improved
regressed
new
removed
plan_changed
insufficient_data
not_comparable
incompatible_context
structural_only
```

Prioridad global:

```text
incompatible_context
insufficient_data
regressed
plan_changed
improved
unchanged
```

Una query nueva no es automáticamente regresión. Una removida no es automáticamente mejora.

## Comparación de planes

El comparador usa la firma normalizada del baseline y del current. Publica:

```text
baseline_signature
current_signature
added_flags
removed_flags
added_keys
removed_keys
access_type_changes
estimated_rows
```

Se consideran regresiones estructurales, entre otras:

```text
nuevo full_table_scan o filesort
índice perdido
access type degradado, por ejemplo ref -> ALL
estimated_rows aumentado por encima de ambos umbrales
```

Un índice agregado, flag problemático removido o `ALL -> ref` puede clasificarse como mejora. Ausencia de `EXPLAIN` produce `insufficient_data`.

## Artifacts

Default:

```text
.testkit/reports/mysql_comparison_latest.json
.testkit/history/mysql_comparison/mysql_comparison_<UTC>_<token>_<comparison_id>.json
```

La escritura usa temporal en el mismo directorio, `fflush`, `fsync` cuando existe y `rename()`.

## Integración con reporte v2

El perfil mantiene:

```text
report_version: 2
schema_version: mysql-query-profile-report-v2
```

Agrega `baseline_comparison` acotado y, por query:

```text
baseline_status
baseline_metric_regressions
baseline_plan_status
```

El artefacto completo permanece separado.

## CLI y exit codes

```bash
php scripts/query_comparison_report.php \
  --current .testkit/reports/mysql_profile_latest.json \
  --baseline test/sql/baselines/back-php.v1.json
```

```text
0 comparación realizada, incluso con regresiones o incompatibilidad contextual
2 error operacional
3 baseline inválido
4 current incompatible/no recuperable
```

No existe `--fail-on-regression` en Fase 4.
