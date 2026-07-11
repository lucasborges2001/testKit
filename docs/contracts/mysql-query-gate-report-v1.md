# Contrato `mysql-query-gate-report-v1`

## Propósito

Representa una evaluación determinista del quality gate SQL.

## Estructura principal

```json
{
  "schema_version": "mysql-query-gate-report-v1",
  "enabled": true,
  "gate_id": "pruebas.sql.ci",
  "mode": "fail",
  "generated_at": "2026-07-11T00:00:00Z",
  "inputs": {},
  "summary": {},
  "decision": {},
  "findings": [],
  "allowlist": {},
  "stability": {},
  "outputs": {},
  "baseline_approval": {},
  "limitations": []
}
```

## Decisión

Estados:

```text
disabled
pass
warn
blocked
pending_stability
insufficient_evidence
invalid_configuration
operational_error
```

Códigos:

```text
0 evaluación completada sin bloqueo, off/report/warn
2 error operacional
3 configuración o contrato inválido
4 input incompatible no recuperable
5 bloqueo confirmado en modo fail
```

## Finding normalizado

Campos estables:

```text
finding_id, category, subcategory, source, source_artifact,
source_finding_id, query_identity, query_id, fingerprint_hash,
policy_id, module_id, scenario_id, suite_id, test_id,
metric, plan_flag, severity, confidence, stability_type,
stability_status, decision_requested, decision_effective,
matched_rule_id, matched_rule_precedence_rank, eligibility,
message, evidence, location, suppressed, suppression
```

`finding_id` no incluye timestamps ni paths absolutos. El orden es determinista por decisión, categoría, query identity e ID.

## Summary

```text
findings
blocking
warnings
observed
suppressed
suppressed_blocking
pending_stability
insufficient_evidence
truncated
```

Los findings truncados se contabilizan en `truncated`; la decisión se calcula sobre la lista acotada publicada por el contrato runtime configurado.

## Evidencia original

El reporte no modifica `policy_evaluation`, `baseline_comparison` ni findings de instrumentación. Las supresiones permanecen como metadata visible.

## Attachment del perfil y suite

El perfil v2 conserva un resumen acotado en `mysql_gate` y `quality_gate`. La suite agrega:

```text
mysql_gate
quality_gate
blocking_findings
gate_mode
gate_exit_code
quality_gate_status
```
