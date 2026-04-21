# Uso operativo de testkit

## Novedades de este corte

- `php runTest.php --help` muestra ayuda breve del runner.
- `php runTest.php <target> --list` es una ruta explícita soportada para listar selección sin ejecutar tests.
- `./bin/testkit inspect config-schema --json` expone el esquema soportado de configuración.
- Warnings por env inválido deben quedar visibles en consola y en los reportes persistidos.

## Reglas operativas nuevas

- Targets agregados (`all`, `back`, `front`, `php`, `js`) son válidos, pero no son la primera corrida diagnóstica más nítida.
- Category targets (`smoke`, `perf`, `stress`, `contract`, `critical`, `slow`) no deben mezclarse con `TEST_CATEGORY` explícito distinto.
- `TEST_JOBS>1` con `TEST_DB_STRATEGY=shared` es una señal visible de riesgo; preferí `TEST_JOBS=1` o `per_worker`.
- `TEST_DB_STRATEGY=per_worker` con `TEST_JOBS=1` no rompe contrato, pero suele ser sobreconfiguración.

## Comandos de referencia

```bash
./bin/testkit doctor --compact
./bin/testkit inspect config-schema --json
./bin/testkit run --rm testkit php runTest.php --help
./bin/testkit run --rm testkit php runTest.php back-php --list
```
