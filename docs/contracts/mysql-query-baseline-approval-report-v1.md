# Contrato `mysql-query-baseline-approval-report-v1`

## Propósito

Determina si una ejecución puede presentarse como candidata humana a un baseline nuevo. Es exclusivamente report-only.

## Estados

```text
eligible
ineligible
pending_stability
insufficient_evidence
incompatible
```

## Criterios

```text
comparison compatible y scope full
contrato de gate válido
sin blocking findings
sin supresiones de findings bloqueantes
sin allowlist expirada
estabilidad satisfecha
minimum successful runs
minimum sample count
instrumentación sin bloqueos
policies dentro de severidad permitida
source commit presente
dataset identity presente
environment identity presente
```

## Estructura

```json
{
  "schema_version": "mysql-query-baseline-approval-report-v1",
  "generated_at": "2026-07-11T00:00:00Z",
  "gate_id": "pruebas.sql.ci",
  "status": "eligible",
  "reason": "all_approval_criteria_satisfied",
  "eligible": true,
  "checks": [],
  "summary": {},
  "inputs": {},
  "criteria": {},
  "limitations": []
}
```

Cada check contiene `id`, `status`, `reason` y evidencia sanitizada.

## Prohibiciones

El evaluator y su CLI no crean, reemplazan, promueven, aceptan, commitean ni publican baselines. Las opciones `--accept`, `--promote` y `--update-baseline` son errores contractuales.
