# Contrato `mysql-query-gate-allowlist-v1`

## Propósito

Declara excepciones temporales y auditables. Una entrada nunca elimina el finding ni altera la evidencia fuente.

## Estructura

```json
{
  "schema_version": "mysql-query-gate-allowlist-v1",
  "allowlist": {
    "id": "pruebas.sql.temporary",
    "description": "Excepciones temporales",
    "maximum_duration_days": 90,
    "entries": [
      {
        "id": "catalog-search-p95-temporary",
        "selectors": {
          "category": ["baseline.temporal_regression"],
          "query_identity": ["query_id:catalog.product_search"]
        },
        "reason": "Investigación en curso",
        "owner": "backend",
        "ticket": "PERF-123",
        "notes": "",
        "created_at": "2026-07-11T00:00:00Z",
        "expires_at": "2026-07-25T00:00:00Z"
      }
    ]
  }
}
```

## Reglas

- archivo máximo de 2 MiB;
- máximo 500 entradas;
- IDs únicos;
- timestamps UTC exactos `YYYY-MM-DDTHH:MM:SSZ`;
- expiración posterior a creación;
- duración máxima default de 90 días, configurable hasta 365;
- selector no vacío;
- selector específico obligatorio: query, policy, metric, plan flag, finding, test, módulo, escenario, suite o subcategoría;
- sin regex ni suppress-all global;
- entrada expirada no suprime y genera `allowlist.expired`;
- entrada no usada se informa en `allowlist.unused`.

## Supresión visible

```json
{
  "suppressed": true,
  "suppression": {
    "suppression_id": "catalog-search-p95-temporary",
    "reason": "Investigación en curso",
    "owner": "backend",
    "ticket": "PERF-123",
    "expires_at": "2026-07-25T00:00:00Z"
  }
}
```

## No suprimibles

No se permiten categorías contractuales u operacionales como evidencia inválida, allowlist inválida, exposición de secretos, path traversal o error de escritura de artifacts.
