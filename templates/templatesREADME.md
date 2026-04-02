# Templates

Plantillas genericas para crear tests sin acoplarse a dominio.

## Estructura

- `php/`:
  - `UNIT_TEST_TEMPLATE.php`
  - `INTEGRATION_TEST_TEMPLATE.php`
  - `CONTRACT_TEST_TEMPLATE.php`
  - `PERF_SMOKE_TEMPLATE.php`
- `js/`:
  - `UNIT_TEST_TEMPLATE.mjs`
  - `CONTRACT_TEST_TEMPLATE.mjs`
  - `PERF_SMOKE_TEMPLATE.mjs`
- `python/`:
  - `UNIT_TEST_TEMPLATE.py`
  - `INTEGRATION_TEST_TEMPLATE.py`
  - `SMOKE_PERF_TEMPLATE.py`

## Convenciones

- `TAGS:` o `@tags` en cabecera.
- `SCOPE:` con `unit|integration|e2e`.
- Tags recomendados:
  - `critical`
  - `contract`
  - `smoke`
  - `perf`
  - `stress`
  - `slow`
  - `fragile`

## Nota

Son esqueletos de infraestructura, no tests de negocio.
