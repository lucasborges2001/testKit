# Troubleshooting operativo

## Ver esquema soportado

Cuando dudes si una variable o combinación existe realmente, no adivines:

```bash
php scripts/inspect.php config-schema
php scripts/inspect.php config-schema --json
```

Eso no reemplaza `doctor`, pero sí evita inventar flags/env que el runner no reconoce.
