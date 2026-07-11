# Contrato `mysql-query-gate-v1`

## Propósito

Configura decisiones declarativas sobre findings SQL ya calculados. El gate no recalcula policies, comparación, fingerprints ni EXPLAIN.

## Estructura

```json
{
  "schema_version": "mysql-query-gate-v1",
  "gate": {
    "id": "pruebas.sql.ci",
    "description": "Gate SQL del host",
    "mode": "report",
    "defaults": {
      "minimum_severity": "warning",
      "on_incompatible_context": "report",
      "on_insufficient_data": "report",
      "on_invalid_embedded_evidence": "error"
    },
    "stability": {
      "temporal": {
        "required_runs": 3,
        "required_confirmations": 2,
        "minimum_sample_count": 20,
        "maximum_age_hours": 168
      },
      "structural": {
        "required_runs": 1,
        "required_confirmations": 1,
        "minimum_sample_count": 0,
        "maximum_age_hours": 168
      }
    },
    "rules": [],
    "outputs": {
      "json": true,
      "junit": true,
      "sarif": true,
      "summary": true,
      "github_annotations": false,
      "github_step_summary": false
    },
    "baseline_approval": {
      "enabled": true,
      "minimum_policy_severity": "error",
      "minimum_sample_count": 20,
      "minimum_successful_runs": 1,
      "require_full_compatibility": true,
      "require_source_commit": true,
      "require_dataset_identity": true,
      "require_environment_identity": true
    }
  }
}
```

## Modos

Solo se admiten `off`, `report`, `warn` y `fail`. Cualquier otro valor es un error contractual.

## Reglas

Campos:

```text
id, description, selectors, decision, allow_structural_only, stability_type
```

Decisiones: `observe`, `warn`, `block`.

`stability_type`: `auto`, `temporal`, `structural`, `none`.

Selectores permitidos:

```text
category, subcategory, source, severity, confidence,
module_id, scenario_id, suite_id, test_id,
query_identity, policy_id, metric, plan_flag, source_finding_id
```

Claves diferentes usan AND; valores de un array usan OR. No se admiten regex, scripts, callbacks ni expresiones.

## Precedencia

```text
query_identity 1000
policy_id       900
test_id          80
suite_id         70
scenario_id      60
module_id        50
metric            40
plan_flag         40
subcategory       30
category          20
severity          10
confidence         5
source             3
source_finding_id  2
```

El orden JSON no decide. Reglas con selectores equivalentes y decisiones incompatibles son inválidas.

## Límites

- archivo: 2 MiB;
- reglas: 500;
- valores por selector: 100;
- `required_runs`: 1..20;
- `required_confirmations <= required_runs`;
- `maximum_age_hours`: 1..720;
- findings visibles runtime: 1..5000.

## Defaults seguros

Sin archivo de gate, el sistema queda deshabilitado. Con reglas vacías no existe bloqueo implícito.
