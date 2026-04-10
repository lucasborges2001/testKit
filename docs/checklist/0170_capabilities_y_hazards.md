# 0170 — Capabilities y hazards por suite o test

## Problema

Algunas pruebas mutan catálogo, baseline, stores o helpers con semántica lateral. Si eso no se declara, el runner no puede planificar ni proteger.

## Objetivo

Agregar metadatos declarativos que describan riesgos y requerimientos.

## Checklist

- [ ] Definir vocabulario mínimo de capabilities/hazards.
- [ ] Permitir declaración por suite y por test.
- [ ] Usar esos metadatos para planificar concurrencia.
- [ ] Exponerlos en reportes.
- [ ] Validar inconsistencias entre metadatos y comportamiento observado cuando sea posible.

## Vocabulario inicial sugerido

- `requires_exclusive_db`
- `requires_baseline_pure`
- `mutates_optional_migrations`
- `touches_catalog_state`
- `non_parallelizable`
- `requires_network`
- `uses_shared_store`

## Ejemplo conceptual

```php
/**
 * HAZARDS: requires_exclusive_db, requires_baseline_pure
 * CAPABILITIES: integration, mysql
 */
```

## Criterio de aceptación

El runner puede rechazar o reordenar combinaciones peligrosas sin depender de inferencia frágil.
