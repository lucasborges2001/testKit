# Pendiente interno — Contratos restantes de testKit

## Estado

Activo. Este documento contiene únicamente deuda interna todavía no cerrada en `lucasborges2001/testKit`.

```text
Baseline auditado: 8fd6cca8b91167c57bd4189e81365e5e4d34e3da
Fecha: 2026-08-15
```

La frontera documental vigente está en [`../README.md`](../README.md). Los documentos históricos `fase-*` no definen el backlog actual.

## Ownership

Todo este pendiente pertenece a `testKit`. No autoriza modificar `Base`, `Pruebas`, consumidores externos, gitlinks, releases ni tags.

## Objetivo

Completar la normalización interna sin aliases públicos, fallbacks silenciosos ni contratos duales, manteniendo una única superficie pública verificable.

## Estado resumido

| Fase | Estado auditado | Evidencia principal |
|---|---|---|
| I3 — Stack estricto | `ACTIVO` | Bash y PowerShell todavía normalizan aliases de stack. |
| I4 — Selección única | `PARCIAL` | CLI pública ya usa `--test` / `--selection-file`, pero `RunRequest` conserva un puente interno mediante `TEST_MATCH*`. |
| I5 — Coverage único | `ACTIVO` | `TEST_COVERAGE_DIR` y rutas legacy bajo `test/coverage` siguen resolviéndose. |
| I6 — Protocolo de agentes v2 | `ACTIVO` | el planner todavía serializa comandos shell y sintaxis POSIX como contrato de acción. |
| I7 — Paridad wrappers | `ACTIVO` | falta cerrar paridad mediante vectores y runtime Windows verificable. |
| I8 — Reportes y exit codes v2 | `NO_VERIFICADO_COMO_CERRADO` | no existe evidencia suficiente en este corte para retirarlo. |
| I9 — Documentación y gates | `PARCIAL` | existe registro generado, pero se detectó drift documental transversal. |

## I3 — Stack estricto

### Evidencia actual

`lib/bash/stack.sh` y `lib/powershell/Stack.ps1` todavía aceptan y normalizan equivalencias como:

```text
postgres | postgresql -> pg
influxdb              -> influx
```

### Objetivo

Aceptar únicamente los nombres canónicos del contrato de stack y fallar antes de Docker ante cualquier sinónimo o valor desconocido.

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

Sin embargo, todavía existe un puente interno que traduce esas entradas a:

```text
TEST_MATCH_LIST
TEST_MATCH_FILE
TEST_MATCH_LIST_MODE
```

Por eso esta fase no está cerrada.

### PASS

- `TEST_MATCH`, `TEST_MATCH_LIST`, `TEST_MATCH_FILE`, `TEST_MATCH_LIST_MODE` y `TEST_SELECTION_MATCH_MODE` dejan de ser entradas públicas y puentes internos de transición;
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

### PASS

- `TEST_COVERAGE_ROOT` es la única raíz configurable;
- `TEST_COVERAGE_DIR` deja de ser entrada;
- no existen fallbacks bajo `test/coverage/`;
- reporting publica una sola ruta canónica;
- Linux y PowerShell generan el mismo plan.

## I6 — Protocolo de agentes v2

### Evidencia actual

`AgentActionPlanner` todavía construye comandos textuales como `./bin/testkit ...` y antepone `TESTKIT_MODE=agent` con semántica POSIX.

### Objetivo

Sustituir el string shell como contrato primario por un `command_spec` neutral y versionado.

### PASS

```text
planner
-> schema validation
-> executor admission
-> argv/env/cwd exactos
-> exit code
-> artifact persistido
```

No es PASS si una acción solo publica una cadena ejecutable en Bash.

## I7 — Paridad de wrappers

### PASS

- mismos vectores de entrada;
- mismo selector, env, compose files y entrypoint;
- mismo código de salida contractual;
- divergencia de vectores provoca fallo;
- soporte runtime Windows se declara únicamente con smoke real.

La deuda específica de terminación de procesos en Windows se mantiene separada en `../processrunner-timeout-windows.md`.

## I8 — Reportes y códigos de salida v2

No retirar esta fase hasta auditar de forma específica todos los schemas de suite, meta, inspect y agentes.

### PASS

- JSON Schema validado;
- tabla cerrada de exit codes;
- campos duplicados eliminados;
- ningún significado depende de una suite de dominio;
- consola es presentación, no contrato de máquina.

## I9 — Documentación generada y gates finales

### Estado actual

`docs/CONTRACT_REGISTRY.md` ya se genera desde `ContractRegistry` y representa la autoridad de selectores. El corte documental del 2026-08-15 corrigió `docs/CONTRATO.md` y `docs/USO.md`, pero la eliminación de drift debe mantenerse como gate permanente.

### PASS

- test contractual ausente = FAIL;
- alias semántico nuevo = FAIL;
- dominio externo en core = FAIL;
- documento generado desactualizado = FAIL;
- schema inválido = FAIL;
- vector Bash/PowerShell divergente = FAIL.

## Orden recomendado

```text
I3 stack
-> I4 selección
-> I5 coverage
-> I6 agentes
-> I7 wrappers
-> I8 reportes
-> I9 gates/documentación
```

No adelantar fases posteriores para ocultar aliases o bridges todavía activos.

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

## Conversión a verificación

Una fase sale de este archivo cuando el código requerido existe y únicamente queda obtener evidencia en un entorno concreto. En ese caso crear o actualizar `docs/verificaciones/` con baseline, entorno, comandos, resultado esperado y estado `PASS|FAIL|BLOCKED`.
