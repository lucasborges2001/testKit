# Cambios operativos recientes para doctor

## Lectura más dura de targets

`doctor` ya no deja varios targets comunes en `TARGET_RULESET_PARTIAL` por defecto. Ahora:

- clasifica targets de suite (`back-php`, `back-py`, `front-php`, `front-js`)
- clasifica targets agregados (`all`, `back`, `front`, `php`, `js`)
- clasifica targets por categoría (`smoke`, `perf`, `stress`, `contract`, `critical`, `slow`)
- mantiene `migration-contract` como ruta técnica cerrada

## Señales nuevas

- `MULTIWORKER_SHARED_VISIBLE_RISK`: `TEST_JOBS>1` sin `per_worker`
- `PER_WORKER_SINGLE_WORKER_OVERCONFIGURED`: `per_worker` con `TEST_JOBS=1`
- `AGGREGATE_TARGET_NOISY_FIRST_DIAG`: target agregado como primera corrida
- `TARGET_CATEGORY_MISMATCH`: target por categoría mezclado con `TEST_CATEGORY` distinto

Lectura correcta:

- `WARN` acá no es cosmético; indica que la configuración visible ya muestra una ruta operativamente floja
- `all` y otros agregados siguen siendo válidos, pero dejan de presentarse como “solo falta contexto”: ahora `doctor` te dice explícitamente por qué son mala primera corrida
