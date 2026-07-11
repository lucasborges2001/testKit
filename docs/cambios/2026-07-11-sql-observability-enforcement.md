# SQL Observability Fase 5 — enforcement gradual y CI

Fecha: 2026-07-11

## Cambio

Se agregó a `testKit` un quality gate SQL opt-in que consume:

- findings de instrumentación;
- `policy_evaluation` de Fase 3;
- `baseline_comparison` y artifacts de comparación de Fase 4.

El gate normaliza evidencia, aplica reglas declarativas, verifica estabilidad, conserva supresiones temporales y publica JSON, JUnit, SARIF y Markdown.

## Integración

`BackPhpSuite` utiliza el callback final existente. Un fallo previo de tests o del runner conserva su exit code. Solo una suite exitosa puede transformarse en exit `2`, `3`, `4` o `5` por el gate.

## Seguridad operativa

- gate deshabilitado por default;
- sin reglas ocultas;
- sin repeticiones automáticas;
- sin auto-accept de baseline;
- sin cambios en Base o Pruebas;
- artifacts atómicos;
- paths y datos sensibles sanitizados.

## Contratos

```text
mysql-query-gate-v1
mysql-query-gate-report-v1
mysql-query-gate-allowlist-v1
mysql-query-gate-evidence-v1
mysql-query-baseline-approval-report-v1
```

## Tests

Se agregó `tests/framework/test_mysql_query_gate.php` al runner explícito. Cubre loaders, normalización, modos, estabilidad, allowlist, integración con suite, outputs CI, aprobación de baseline y códigos CLI.
