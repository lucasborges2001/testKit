# Uso operativo de testkit

## Esquema de configuración soportada

Para ver la superficie soportada del runner sin reconstruirla leyendo código:

```bash
php scripts/inspect.php config-schema
php scripts/inspect.php config-schema --json
```

Usalo para responder rápido:

- qué env vars reconoce el runner
- qué tipo espera cada una
- qué defaults visibles aplica
- qué comandos de ayuda/listado están soportados

`php runTest.php --help` da una vista corta. `config-schema` es la vista contractual operador-first.
