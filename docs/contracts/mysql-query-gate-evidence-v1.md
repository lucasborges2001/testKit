# Contrato `mysql-query-gate-evidence-v1`

## Propósito

Selecciona explícitamente comparaciones de múltiples corridas para confirmar estabilidad. No ejecuta tests ni descubre artifacts por glob.

## Estructura

```json
{
  "schema_version": "mysql-query-gate-evidence-v1",
  "artifacts": [
    {
      "path": "comparison-run-1.json",
      "sha256": "<64 hex>"
    }
  ]
}
```

## Invariantes

- máximo 20 artifacts;
- paths relativos a la carpeta del manifest;
- sin URLs, paths absolutos o `..`;
- archivo regular obligatorio;
- symlink resuelto dentro de la raíz permitida;
- SHA-256 obligatorio y coincidente;
- JSON estricto;
- schema de comparación compatible;
- artifacts deduplicados por hash.

`maximum_age_hours` se aplica después de cargar el manifest. Un artifact válido pero vencido no cuenta como corrida estable.
