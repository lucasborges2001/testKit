# Salida humana compacta

Estado: **IMPLEMENTED**

Baseline de diseño: `testKit/main` en `cbec3cda71d042e54bada91c9b50ffa02fa842d4`.

## Objetivo

Reducir ruido vertical en terminal sin cambiar el contrato de ejecución, los códigos de salida ni los artefactos JSON persistidos. La consola es una interfaz humana; los reportes de `.testkit/reports/` siguen siendo la interfaz canónica para automatización.

## Política por defecto

`TESTKIT_CONSOLE_MODE=compact` es el modo humano predeterminado.

Una validación verde se resume en una fila:

```text
PASS PHP lint                     556/556  2.8s
PASS Bash syntax                    34/34  0.2s
PASS Framework tests                45/45  3.4s

Summary: 635 PASS · 0 FAIL · 0 SKIP · 6.4s
```

Los éxitos individuales no se imprimen uno por línea. El conteo sí permanece visible.

## Fallos

Los fallos se expanden automáticamente y conservan información accionable:

```text
FAIL Framework tests                44/45  3.4s

  FAIL ConsoleReporter compact pass
    exit_code=1
    expected compact output
    rerun: php tests/framework/test_console_reporter_compact_pass.php
```

El excerpt de salida queda limitado en consola para evitar otra fuente de ruido. El diagnóstico completo debe seguir viviendo en los artefactos/reportes correspondientes.

## SKIP

Un gate completamente omitido se representa explícitamente:

```text
SKIP Smoke                          35/35  0ms
  reason: dependency failure
```

Un `SKIP` no se presenta como `PASS`.

## Color

La interfaz humana reutiliza la política pública de `UI`:

- `PASS`: verde;
- `FAIL`: rojo;
- `SKIP`: amarillo;
- comando `rerun`: cian;
- metadatos secundarios y duración: gris.

`NO_COLOR=1` elimina ANSI sin modificar contenido semántico ni exit codes. El modo agente conserva su contrato propio y no debe activarse sólo para reducir ruido.

## Compatibilidad verbose

Para recuperar la presentación histórica detallada de suites y meta:

```bash
TESTKIT_CONSOLE_MODE=live php runTest.php --suite back-php
```

`live` afecta presentación. No debe cambiar selección, fail-fast, jobs, estrategia DB ni códigos de salida.

## Lint y sintaxis

El agregado reusable es:

```bash
php scripts/static_checks.php
```

Selectores focalizados:

```bash
php scripts/static_checks.php php
php scripts/static_checks.php bash
php scripts/static_checks.php node
```

Cada categoría muestra un único resultado agregado cuando está verde. Un archivo inválido expande sólo ese fallo y entrega un comando de rerun.

## Framework self-tests

`php tests/framework/run.php` deja de imprimir un `[PASS]` por self-test. Los casos verdes se agregan; cualquier self-test fallido conserva su salida y rerun individual.

## Runner declarativo de hosts

`runners/runSuiteConfig.php` mantiene separados dos contratos:

- `output=live|failures` controla captura de stdout/stderr;
- `TESTKIT_CONSOLE_MODE=compact|live` controla presentación humana.

Por compatibilidad con hosts que ya consumen `output=failures`, el runner declarativo sólo activa la fila compacta cuando `TESTKIT_CONSOLE_MODE=compact` se declara explícitamente. Un resultado completamente verde se muestra así:

```text
PASS Base PHP syntax                556/556 2.8s
```

No se imprimen el bloque `Summary:` ni el `OK <suite>` redundante en ese caso. Si aparece cualquier fallo, el runner conserva el detalle y el resumen diagnóstico histórico; no se compactan resultados no verdes.

Para conservar explícitamente la presentación histórica aun usando captura `failures`:

```bash
TESTKIT_CONSOLE_MODE=live php runners/runSuiteConfig.php config/testkit-suites.php all
```

Esta separación permite que hosts como Base migren sólo la presentación sin cambiar comandos, fail-fast, composición, `success_stderr` ni códigos de salida.

## Suites y meta

Una suite completamente verde usa una sola fila compacta. Si hay `FAIL`, `SKIP`, `TIMEOUT`, evidencia inválida o violaciones de performance, se conserva el reporter detallado existente.

El meta-runner sigue la misma regla: sólo los resultados completamente verdes se colapsan. Un agregado con cualquier suite no exitosa mantiene el diagnóstico por suite.

## Docker Compose

Los wrappers Bash y PowerShell fijan `COMPOSE_PROGRESS=quiet` únicamente cuando:

- el usuario no definió `COMPOSE_PROGRESS`; y
- `TESTKIT_CONSOLE_MODE` no es `live`.

Esto reduce mensajes de progreso de Compose sin usar `docker compose run -q` y, por lo tanto, sin silenciar stdout/stderr del proceso de test.

Overrides válidos:

```bash
COMPOSE_PROGRESS=plain ./bin/testkit up -d
TESTKIT_CONSOLE_MODE=live ./bin/testkit run --rm testkit php runTest.php --suite back-php
```

## Invariantes

La compactación no debe alterar:

- exit codes;
- stdout/stderr de un fallo;
- warnings relevantes;
- selección de suites/tests;
- persistencia de JSON/reportes;
- comandos de rerun;
- política `NO_COLOR`;
- semántica de Docker Compose.

No parsear la consola como API. Consumir `inspect --json`, reportes canónicos y artefactos persistidos.

## Validación esperada

```bash
php scripts/static_checks.php
php tests/framework/run.php
NO_COLOR=1 php tests/framework/test_compact_batch_reporter.php
NO_COLOR=1 php tests/framework/test_console_reporter_compact_pass.php
php tests/framework/test_static_checks_contract.php
php tests/framework/test_run_suite_config_compact_contract.php
php tests/framework/test_wrapper_compact_output_contract.php
bash -n bin/testkit
```

En Windows, además:

```powershell
pwsh -NoProfile -NonInteractive -File tests/powershell/run.ps1
```

La aceptación final requiere CI verde en `main`, incluyendo `windows-static`, `framework-self-tests` y runtimes existentes.
