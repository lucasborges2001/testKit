# Uso operativo de testkit

## Novedades de este corte

- `php runTest.php --help` muestra ayuda breve del runner.
- `php runTest.php <target> --list` es una ruta explícita soportada para listar selección sin ejecutar tests.
- `./bin/testkit inspect config-schema --json` expone el esquema soportado de configuración.
- Warnings por env inválido deben quedar visibles en consola y en los reportes persistidos.
- `inspect config-schema --json` incluye la matriz honesta de soporte por motor/servicio.

## Matriz corta de soporte

| Componente | Estado operativo |
|---|---|
| MySQL | ruta principal cerrada |
| PostgreSQL | parcial; sin snapshot/clone cerrado |
| Redis | auxiliar; no store estructural core |
| Influx | auxiliar/perfilado; no store driver principal |
| `TEST_DB_STRATEGY=clean` | rechazado explícitamente |
| `TEST_DB_STRATEGY=per_worker` | aislamiento intra-suite, no multi-runner top-level |

Para detalle contractual, leer `SUPPORT_MATRIX.md` y `docs/CONTRATO.md`.

## Reglas operativas nuevas

- Targets agregados (`all`, `back`, `front`, `php`, `js`) son válidos, pero no son la primera corrida diagnóstica más nítida.
- Category targets (`smoke`, `perf`, `stress`, `contract`, `critical`, `slow`) no deben mezclarse con `TEST_CATEGORY` explícito distinto.
- `TEST_JOBS>1` con `TEST_DB_STRATEGY=shared` es una señal visible de riesgo; preferí `TEST_JOBS=1` o `per_worker`.
- `TEST_DB_STRATEGY=per_worker` con `TEST_JOBS=1` no rompe contrato, pero suele ser sobreconfiguración.
- `TEST_DB_STRATEGY=clean` no está implementado; no lo uses como fallback.
- No uses `TEST_STORE_DRIVER=redis` ni `TEST_STORE_DRIVER=influx`: son servicios auxiliares, no stores estructurales.

## Comandos de referencia

```bash
./bin/testkit doctor --compact
./bin/testkit inspect config-schema --json
./bin/testkit run --rm testkit php runTest.php --help
./bin/testkit run --rm testkit php runTest.php back-php --list
```
