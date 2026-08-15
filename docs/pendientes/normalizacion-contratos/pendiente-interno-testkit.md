# Pendiente interno — Contratos restantes de testKit

## Estado

Activo. Este documento contiene únicamente deuda interna todavía no cerrada en `lucasborges2001/testKit`.

```text
Baseline auditado: ed1a35b85f87cf124495941211d39c6e3b9b6906
Fecha: 2026-08-15
```

La frontera documental vigente está en [`../README.md`](../README.md). Los documentos históricos `fase-*` son evidencia de decisiones anteriores y no forman parte del backlog activo.

## Ownership

Todo este pendiente pertenece a `testKit`. No autoriza modificar `Base`, `Pruebas`, consumidores externos, gitlinks, releases ni tags.

## Objetivo

Completar la normalización interna sin aliases públicos, fallbacks silenciosos ni contratos duales, manteniendo una única superficie pública verificable.

## Dependencia externa previa al cutover

Antes de eliminar bridges o aliases que puedan ser consumidos fuera de `testKit`, ejecutar el inventario read-only E1 de [`pendiente-integraciones-externas.md`](pendiente-integraciones-externas.md).

E1 no bloquea auditorías ni diseño interno. Sí bloquea un cutover que pueda romper consumidores desconocidos.

## Estado resumido

| Fase | Estado auditado | Evidencia principal |
|---|---|---|
| I3 — Stack estricto | `ACTIVO` | Bash y PowerShell todavía normalizan aliases de stack. |
| I4 — Selección única | `PARCIAL` | El contrato público ya usa `--test` / `--selection-file`; queda el bridge interno `TEST_MATCH*`. |
| I5 — Coverage único | `ACTIVO` | `TEST_COVERAGE_DIR` y rutas legacy bajo `test/coverage` siguen resolviéndose. |
| I6 — Protocolo de agentes v2 | `ACTIVO` | El planner todavía serializa comandos shell como contrato primario de acción. |
| I7-A — Paridad contractual de wrappers | `ACTIVO` | Deben cerrarse vectores y resultados equivalentes Bash/PowerShell. |
| I7-B — Evidencia runtime Windows | `VERIFICACION_PENDIENTE` | Requiere Windows real o runner equivalente; no se demuestra con parseo estático. |
| I8 — Reportes y exit codes v2 | `ACTIVO` | `CanonicalReport` todavía normaliza campos/fallbacks legacy y deriva estado desde múltiples fuentes. |
| I9 — Gates finales | `PARCIAL` | Ya existen registry generado y gates contractuales; falta convergencia final después de I3–I8. |

## I3 — Stack estricto

### Evidencia actual

`lib/bash/stack.sh` y `lib/powershell/Stack.ps1` todavía aceptan y normalizan equivalencias como:

```text
postgres | postgresql -> pg
influxdb              -> influx
```

### Objetivo

Aceptar únicamente los nombres canónicos del contrato de stack y fallar antes de Docker ante cualquier sinónimo o valor desconocido.

### Dependencia

- E1 debe identificar consumidores externos de aliases antes de eliminarlos del contrato efectivo.

### PASS

- Bash y PowerShell aceptan exactamente el mismo conjunto;
- no existen normalizaciones semánticas de aliases;
- valores inválidos fallan antes de invocar Docker;
- tests de paridad cubren permitidos y rechazados.

## I4 — Selección de tests única

### Estado actual

La superficie pública ya está tipada:

```text
--test <repo-relative>
--selection-file <repo-relative>
```

`core/php/config/RunRequest.php` valida rutas repo-relative, rechaza traversal y hace mutuamente excluyentes `--test` y `--selection-file`.

La parte pública de I4 está implementada. La deuda restante es exclusivamente el bridge interno que traduce esas entradas a:

```text
TEST_MATCH_LIST
TEST_MATCH_FILE
TEST_MATCH_LIST_MODE
```

### Objetivo restante

Eliminar el modelo de selección dual dentro de TestKit sin reintroducir compatibilidad pública.

### Dependencia

- E1 debe identificar consumidores externos de `TEST_MATCH*` antes del cutover.

### PASS

- `TEST_MATCH`, `TEST_MATCH_LIST`, `TEST_MATCH_FILE`, `TEST_MATCH_LIST_MODE` y `TEST_SELECTION_MATCH_MODE` dejan de ser entradas públicas y bridges internos de transición;
- CLI, UI, reporting y rerun usan un mismo modelo de selección;
- no existe selección implícita por substring;
- selección vacía o contradictoria falla explícitamente.

## I5 — Coverage único

### Evidencia actual

`core/php/common/Paths.php` todavía resuelve:

```text
TEST_COVERAGE_ROOT
TEST_COVERAGE_DIR     # legacy
.testkit/coverage/
test/coverage/       # candidatos legacy
```

### Dependencia

- E1 debe identificar consumidores externos de `TEST_COVERAGE_DIR` o rutas `test/coverage` antes del cutover.

### PASS

- `TEST_COVERAGE_ROOT` es la única raíz configurable;
- `TEST_COVERAGE_DIR` deja de ser entrada;
- no existen fallbacks bajo `test/coverage/`;
- reporting publica una sola ruta canónica;
- Linux y PowerShell generan el mismo plan.

## I6 — Protocolo de agentes v2

### Evidencia actual

`AgentActionPlanner` todavía construye comandos textuales como `./bin/testkit ...` y trata una cadena shell como parte primaria de la acción sugerida.

### Objetivo

Sustituir el string shell como contrato primario por un `command_spec` neutral y versionado.

### Dependencia

El pendiente [`../external-runtime-executor.md`](../external-runtime-executor.md) depende de este contrato. No crear un segundo modelo de command specification dentro del executor externo.

### PASS

```text
planner
-> command_spec versionado
-> schema validation
-> executor admission
-> argv/env/cwd exactos
-> exit code
-> artifact persistido
```

No es PASS si una acción solo publica una cadena ejecutable en Bash o PowerShell.

## I7-A — Paridad contractual de wrappers

### Objetivo

Cerrar la equivalencia contractual Bash/PowerShell sin confundirla con evidencia de una plataforma runtime real.

### PASS

- mismos vectores de entrada;
- mismo selector, env, compose files y entrypoint;
- mismo código de salida contractual;
- divergencia de vectores provoca fallo automático.

Cuando I7-A sea PASS, cualquier evidencia pendiente exclusivamente por entorno debe salir de este archivo y registrarse en `docs/verificaciones/`.

## I7-B — Evidencia runtime Windows

### Estado

Verificación pendiente. La CI remota actual no aporta evidencia Windows nueva y el runtime Docker Desktop/MySQL real debe demostrarse en un host Windows o runner equivalente.

### Criterio de cierre

- ejecutar smokes reproducibles en Windows real o runner equivalente;
- registrar baseline, entorno, comandos, artifacts y exit codes;
- comparar el resultado contractual con Linux;
- usar estado `PASS|FAIL|BLOCKED`, nunca inferir PASS desde parseo estático.

La deuda específica de terminación de procesos en Windows permanece separada en [`../processrunner-timeout-windows.md`](../processrunner-timeout-windows.md).

## I8 — Reportes y códigos de salida v2

### Evidencia actual

`core/php/reporting/CanonicalReport.php` todavía acepta y normaliza varias representaciones anteriores. Entre otros casos:

```text
outcome_status
-> suite_status
-> final_status
-> exit_code como fallback
```

También reconstruye información canónica a partir de campos top-level legacy cuando el shape explícito no está presente.

Esto confirma deuda real de normalización; I8 deja de estar clasificado como “no verificado como cerrado” y pasa a `ACTIVO`.

### Objetivo

Definir un único schema canónico y versionado para reportes suite/meta y cerrar la semántica de exit codes sin fallbacks ambiguos.

### PASS

- schema canónico versionado y validado;
- tabla cerrada de exit codes;
- campos duplicados o aliases de shape eliminados;
- suite/meta/inspect/agente consumen el mismo significado contractual donde corresponda;
- ningún significado depende de una suite de dominio;
- consola es presentación, no contrato de máquina;
- fixtures negativos prueban rechazo de payloads inválidos.

## I9 — Gates finales de convergencia

### Estado actual

Esta fase ya no representa “crear documentación contractual desde cero”. Actualmente existen:

- `docs/CONTRACT_REGISTRY.md` generado desde `ContractRegistry`;
- validación del registry;
- tests que comprueban selectores tipados y rechazo de aliases conocidos;
- gate que detecta drift entre el documento generado y el registry.

La deuda restante de I9 es convertir la convergencia final I3–I8 en gates permanentes y demostrar su ejecución en los entornos correspondientes.

### Dependencias

- I3–I8 resueltos o convertidos explícitamente a verificaciones de entorno;
- CI disponible para declarar evidencia remota; mientras permanezca deshabilitada, ese punto queda `BLOCKED`, no `PASS`.

### PASS

- test contractual ausente = FAIL;
- alias semántico nuevo = FAIL;
- dominio externo en core = FAIL;
- documento generado desactualizado = FAIL;
- schema inválido = FAIL;
- vector Bash/PowerShell divergente = FAIL;
- los gates requeridos se ejecutan y existe evidencia reproducible del corte.

## Orden recomendado

```text
E1 inventario read-only de consumidores
|
+-> I3 stack
+-> I4 bridge interno de selección
+-> I5 coverage
|
v
I6 command_spec
+-> I8 reportes/exit codes
|
v
I7-A paridad contractual
|
v
I7-B evidencia Windows -> docs/verificaciones/
|
v
I9 gates finales
```

I6 es además precondición arquitectónica del executor genérico de runtime externo.

## Baseline obligatorio por fase

```bash
git branch --show-current
git rev-parse HEAD
git status --short
git log --oneline -8
```

## Validación mínima por fase

```bash
git status --short
git diff --check
find . -type f -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -r -n1 php -l
php tests/framework/run.php
find bin scripts lib -type f \( -name '*.sh' -o -name 'testkit' \) -print0 | xargs -0 -r -n1 bash -n
```

```powershell
pwsh -NoProfile -NonInteractive -File tests/powershell/run.ps1
```

Las pruebas de una fase deben ampliarse con tests focalizados sobre el contrato modificado. Estos comandos son un mínimo, no evidencia suficiente por sí solos.

## Conversión a verificación

Una fase sale de este archivo cuando el código requerido existe y únicamente queda obtener evidencia en un entorno concreto. En ese caso crear o actualizar `docs/verificaciones/` con baseline, entorno, comandos, resultado esperado y estado `PASS|FAIL|BLOCKED`.
