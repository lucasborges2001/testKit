# 0130 — Seed y bootstrap state explícitos

## Problema

Hoy parte de la verdad del bootstrap queda inferida o distribuida entre tests. Eso empuja lógica del runner hacia los tests y obliga a meter `skip`s defensivos.

## Objetivo

Convertir el estado de bootstrap/seed en una entidad explícita, visible y reusable.

## Checklist

- [ ] Definir un modelo canónico de seed state.
- [ ] Exponer baseline activo.
- [ ] Exponer migraciones opt-in pedidas.
- [ ] Exponer migraciones efectivamente aplicadas.
- [ ] Exponer si el run es `baseline_pure` o `baseline_plus_optins`.
- [ ] Permitir precondiciones declarativas en tests.
- [ ] Evitar que los tests tengan que parsear env vars crudas para entender el contexto.

## API/DSL deseable

```php
// ejemplo conceptual

t_requires_seed_mode('baseline_pure');
t_requires_optional_migrations_absent(['016_checkout']);
```

## JSON sugerido

```json
{
  "seed_mode": {
    "baseline": "mysql",
    "profile": "baseline_pure",
    "requested_migrations": ["016_checkout"],
    "applied_migrations": ["016_checkout"],
    "historical_absorbed": ["012_telegram_por_sitio", "015_grupotarifa_grupo_organizacion"]
  }
}
```

## Regla importante

La fuente de verdad del seed mode debe ser el runner, no un helper local dentro de cada test.

## Criterio de aceptación

Las pruebas que dependen de baseline puro o de opt-ins específicos pueden declararlo sin reimplementar lógica de interpretación del entorno.
