# Pendiente interno — Contratos restantes de testKit

## Estado

Activo. Sólo contiene deuda funcional/contractual todavía no cerrada dentro de `lucasborges2001/testKit`.

```text
Baseline auditado: 132ed52e49f530231206e6c4358fe6d3dedf8b19
Fecha: 2026-08-26
```

No autoriza cambios en `Base`, `Pruebas`, consumidores externos, gitlinks, releases ni tags. Antes de retirar un bridge o alias con posible consumo externo, ejecutar E1 read-only de [`pendiente-integraciones-externas.md`](pendiente-integraciones-externas.md).

## Estado resumido

| Fase | Estado | Evidencia |
|---|---|---|
| I3 — Stack estricto | `ACTIVO` | Bash y PowerShell aún normalizan aliases (`postgres/postgresql -> pg`, `influxdb -> influx`). |
| I4 — Selección única | `ACTIVO` | `RunRequest` conserva `TEST_MATCH_LIST`, `TEST_MATCH_FILE`, `TEST_MATCH_LIST_MODE`. |
| I5 — Coverage único | `ACTIVO` | `Paths` mantiene variables/rutas legacy de coverage. |
| I6 — `command_spec` | `IMPLEMENTADO` | Existe `testkit.command_spec@1`; queda verificación de checkout completo. |
| I7-A — Paridad wrappers | `ACTIVO` | Falta demostrar equivalencia contractual Bash/PowerShell. |
| I7-B — Windows runtime | `VERIFICACION_DEPENDIENTE` | Requiere I7-A PASS y ejecución Windows real. |
| I8-A — Resultado operativo v2 | `IMPLEMENTADO` | Existe `testkit.operation_result@2`; quedan gates de verificación. |
| I8-B — Reporting canónico | `ACTIVO` | `CanonicalReport` aún deriva/normaliza campos y fallbacks legacy. |
| I9 — Gates finales | `PARCIAL` | Registry/gates existen; falta convergencia después de la deuda activa. |

## I3 — Stack estricto

**Evidencia:** `lib/bash/stack.sh` y `lib/powershell/Stack.ps1` normalizan aliases semánticos.

**Objetivo:** aceptar sólo nombres canónicos y fallar antes de Docker ante alias o valor desconocido.

**Dependencia:** E1 debe identificar consumidores externos de esos aliases.

**PASS:**

- Bash y PowerShell aceptan el mismo conjunto canónico;
- no existen aliases semánticos;
- entrada inválida falla antes de Docker;
- tests de paridad cubren permitidos/rechazados.

**Validación:**

```bash
git grep -n -E "postgresql|postgres|influxdb" -- lib/bash/stack.sh lib/powershell/Stack.ps1 tests
bash -n lib/bash/stack.sh
```

```powershell
pwsh -NoProfile -NonInteractive -File tests/powershell/test_stack_resolution.ps1
```

## I4 — Selección única sin bridge `TEST_MATCH*`

**Evidencia:** la superficie pública ya usa `--test`/`--selection-file`, pero `core/php/config/RunRequest.php` conserva:

```text
TEST_MATCH_LIST
TEST_MATCH_FILE
TEST_MATCH_LIST_MODE
```

**Objetivo:** una sola representación tipada desde CLI/UI hasta ejecución, reporting y rerun.

**Dependencia:** E1 debe identificar consumidores externos de `TEST_MATCH*`.

**PASS:**

- `TEST_MATCH`, `TEST_MATCH_LIST`, `TEST_MATCH_FILE`, `TEST_MATCH_LIST_MODE` y `TEST_SELECTION_MATCH_MODE` dejan de ser entradas/bridges activos;
- no existe selección implícita por substring;
- selección vacía o contradictoria falla explícitamente;
- tests usan el modelo tipado canónico.

**Validación:**

```bash
git grep -n -E "TEST_MATCH|TEST_SELECTION_MATCH_MODE" -- core lib runners tests docs
php tests/framework/test_selection_sources.php
```

## I5 — Coverage único

**Evidencia:** `core/php/common/Paths.php` conserva `TEST_COVERAGE_DIR` y candidatos legacy bajo `test/coverage/`.

**Objetivo:** una sola raíz configurable y una sola ruta contractual.

**Dependencia:** E1 debe identificar consumidores de `TEST_COVERAGE_DIR`/`test/coverage/`.

**PASS:**

- `TEST_COVERAGE_ROOT` es la única raíz configurable;
- `TEST_COVERAGE_DIR` deja de ser entrada;
- no existen fallbacks `test/coverage/`;
- wrappers/reporting convergen en la misma ruta.

**Validación:**

```bash
git grep -n -E "TEST_COVERAGE_DIR|test/coverage" -- core lib runners tests docs
```

## I6 — `command_spec`

Estado: `IMPLEMENTADO`; se retira del backlog de implementación.

Referencias:

```text
docs/COMMAND_SPEC.md
docs/verificaciones/i6-command-spec-v1.md
core/php/config/CommandSpec.php
core/php/config/AgentAdmissionResult.php
```

Un fallo de los gates puede reabrir un defecto concreto, no la fase general.

## I7-A — Paridad contractual Bash/PowerShell

**Objetivo:** mismos vectores de entrada, selector, env, compose files, entrypoint y código de salida contractual.

**PASS:** cualquier divergencia relevante provoca fallo automático. La interfaz PowerShell entra por `bin/testkit.ps1` y reutiliza módulos bajo `lib/powershell/`.

## I7-B — Evidencia runtime Windows

No es implementación autónoma mientras I7-A siga abierto. Después de I7-A PASS, registrar baseline, entorno, comandos, artifacts y `PASS|FAIL|BLOCKED` en `docs/verificaciones/`.

La terminación/timeout nativa permanece separada en [`../processrunner-timeout-windows.md`](../processrunner-timeout-windows.md).

## I8-A — `OperationResultV2`

Estado: `IMPLEMENTADO`; se retira del backlog de implementación.

Referencias:

```text
docs/OPERATION_RESULT_V2.md
docs/verificaciones/i8-a-operation-result-v2.md
```

## I8-B — Convergencia de reporting y exit codes

**Evidencia:** `core/php/reporting/CanonicalReport.php` todavía acepta múltiples fuentes/fallbacks, por ejemplo:

```text
outcome_status -> suite_status -> final_status -> exit_code
seed_state explícito -> campos top-level legacy
```

**Objetivo:** converger reporting alrededor de contratos versionados sin reconstruir semántica desde shapes legacy ambiguos.

**Dependencias:** E1 antes de retirar shapes potencialmente externos; reutilizar `OperationResultV2`, no crear un segundo resultado operativo.

**PASS:**

- schema canónico versionado/validado;
- estados y exit codes sin semántica ambigua;
- aliases/fallbacks eliminados o documentados como compatibilidad temporal con criterio de retiro;
- suite/meta/inspect/agente consumen el mismo significado donde corresponda;
- fixtures negativos rechazan payloads inválidos.

**Validación focal existente:**

```bash
php tests/framework/test_exit_code_v2_contract.php
php tests/framework/test_operation_result_v2_contract.php
php tests/framework/test_failure_classification_contracts.php
php tests/framework/test_reporting_contract.php
```

## I9 — Gates finales

Estado: `PARCIAL`.

**Objetivo:** convertir el cierre de I3/I4/I5/I7-A/I8-B en gates permanentes y obtener evidencia reproducible.

**PASS:**

- test contractual ausente = FAIL;
- alias semántico nuevo = FAIL;
- dominio externo en core = FAIL;
- documento generado desactualizado = FAIL;
- schema inválido = FAIL;
- vector Bash/PowerShell divergente = FAIL;
- gates ejecutados con evidencia del corte.

## Orden recomendado

```text
E1 inventario read-only
|
+-> I3 stack
+-> I4 selección
+-> I5 coverage
|
v
I8-B reporting
|
v
I7-A paridad contractual
|
v
I7-B -> docs/verificaciones/
|
v
I9 gates finales
```

I6 e I8-A quedan fuera de este flujo porque ya existen y sólo conservan verificaciones.

## Validación mínima futura

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

Estos comandos son criterios de fases futuras; esta auditoría documental remota no los ejecutó.

## Criterio de cierre

Una fase se elimina sólo cuando la deuda funcional deja de existir. Si sólo falta evidencia de un gate o entorno, el seguimiento debe pasar a `docs/verificaciones/`.
