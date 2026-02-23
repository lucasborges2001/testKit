# test/utils

Carpeta que centraliza los helpers y harness para los tests del repo.

Estructura:

- `pvt.php` : helpers ligeros para tests (scope, assert, env setters).
- `testlib.php` : helpers simples para runners pequeños (t_assert_true, t_run, ...).
- `php/assert.php` : helpers de aserciones y reporting para tests PHP.
- `php/testkit.php` : TestKit centrado usado por módulos que necesitaban `_lib/testkit.php` (ej. back/mysql).
- `js/assert.mjs` : helpers para tests JS en `public_html`.
- `constants.php` : constantes globales para tests (incluye contrato de exit codes).
- `js/constants.mjs` : exit codes (JS).

Objetivo
--------
Centralizar todos los helpers reutilizables para:

- evitar duplicación entre módulos (`test/_lib`, `test/_harness`, etc.)
- documentar en un único lugar qué provee cada helper
- permitir que el runner (`test/run.php`) y tests individuales incluyan los mismos helpers

Guía rápida de funciones (resumen):

- `pvt.php`:
  - `pvt_scope_allows(string $needed): bool` — decide si un test corre según `TEST_SCOPE`.
  - `pvt_assert($cond, string $msg, array $ctx)` — lanza `Exception` si falla.
  - `pvt_eq($a, $b, string $msg)` — compara estrictamente.
  - `pvt_contains(string $hay, string $needle, string $msg)` — assert substring.
  - `pvt_throws(callable $fn, ?string $class, ?string $msgContains): Throwable` — check excepciones.
  - `pvt_run(string $name, callable $fn)` — ejecuta y reporta duración.
  - `pvt_env_set(string $k, ?string $v)` — setea env vars para tests.

- `testlib.php`:
  - `t_assert_true($cond, string $msg)` — assert simple.
  - `t_assert_eq($a, $b, string $msg)` — assert equality.
  - `t_run(string $name, callable $fn)` — ejecuta caso simple.

- `php/assert.php`:
  - `t_assert`, `t_eq`, `t_ne`, `t_contains`, `t_match`, `t_case` — aserciones y helpers para tests CLI.
  - `t_print_fail(Throwable $e)`, `t_print_skip(Throwable $e)` — reporting.

- `php/testkit.php`:
  - `t_type(mixed $v): string` — tipo útil para debugging.
  - `t_fail(string $msg)` — lanza `RuntimeException`.
  - `t_true(bool $cond, string $msg)` — assert boolean.
  - `t_eq(mixed $exp, mixed $act, string $msg)` — strict equality check.
  - `t_throws(callable $fn, string $class, ?string $msgContains): Throwable` — assert excepciones.
  - `t_run_cli(array $cases)` — runner CLI para arrays de callables (usado por tests de back/mysql).

- `js/assert.mjs`:
  - exports `t_assert`, `t_eq`, `t_ne`, `t_contains`, `t_match`, `t_case`, `t_print_fail`, `t_print_skip`.

- `constants.php`:
  - `TEST_SCOPE_DEFAULT`, `TEST_FAIL_FAST_DEFAULT`, `TEST_DEFAULT_TIMEOUT_MS`.

Env/Contrato
--------------

- Exit codes (contrato):
  - 0 PASS
  - 1 FAIL
  - 2 SKIP
  - 3 ERROR

- Color ANSI:
  - PVT_COLOR=auto|1|0 (prioridad)
  - NO_COLOR=1
  - FORCE_COLOR=1

Notas y recomendaciones
----------------------

- Para helpers muy específicos de un módulo (p.ej. fixtures complejas), mantenerlos dentro
  de `test/back/<modulo>/_lib/` pero considerar extraerlos a `test/utils/` si se reutilizan.
- Documentar en cada test (header) qué busca probar: objetivo, dependencias (DB/env) y runner.
