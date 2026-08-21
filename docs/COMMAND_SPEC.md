# Command specification v1

## Propósito

`testkit.command_spec@1` es el contrato de máquina para acciones ejecutables generadas por TestKit.

La cadena `next_action.command` puede conservarse como presentación para un operador, pero **no es un contrato de ejecución** y no debe parsearse para decidir qué proceso lanzar.

Flujo canónico:

```text
planner
-> command_spec versionado
-> validación/admission
-> argv/env/cwd exactos
-> ProcessRunner
-> exit code / payload
-> artifact del agente
```

## Schema

```json
{
  "schema": "testkit.command_spec@1",
  "executor": "process",
  "argv": ["php", "runTest.php", "--suite", "back-php"],
  "env": {
    "TESTKIT_MODE": "agent"
  },
  "cwd": ".",
  "expects_json": false
}
```

### `schema`

Valor exacto en v1:

```text
testkit.command_spec@1
```

Schemas desconocidos se rechazan antes de ejecutar.

### `executor`

Executor admitido en v1:

```text
process
```

Este executor usa `ProcessRunner` con un array de `argv`. `external-runtime` no forma parte de este corte y no se acepta como alias anticipado.

### `argv`

Lista no vacía de argumentos exactos.

Ejemplos canónicos del agente:

```text
php runTest.php --suite back-php --test test/back/auth/login.test.php
php runTest.php --group all --list
php scripts/inspect.php latest --run=<id> --json
```

No se admite un campo `command` libre dentro del schema ni ejecución shell inline mediante formas como:

```text
bash -c "..."
pwsh -Command "..."
cmd /c "..."
```

Los metacaracteres contenidos en un argumento normal no son reinterpretados por shell; `ProcessRunner` recibe `argv` como array.

### `env`

Mapa explícito `string -> string` de overrides.

En modo agente, la continuación conserva explícitamente:

```text
TESTKIT_MODE=agent
```

El spec no usa `TEST_MATCH*` para representar selección. Los reruns usan `--suite` y `--test`.

### `cwd`

Ruta relativa al root de TestKit. `.` significa el root de TestKit.

Se rechazan:

- rutas absolutas;
- `..`;
- null bytes.

### `expects_json`

Booleano que declara si stdout debe decodificarse como payload JSON hijo.

No cambia el exit code del proceso.

## Admission

`AgentRunExecute` ejecuta únicamente `next_action.command_spec`.

No reconstruye argv desde `kind`, `target`, `selection` ni `first_failure`, y no parsea `next_action.command`.

Un spec inválido produce:

```text
executed=false
admission.accepted=false
result.exit_code=2
```

sin iniciar el proceso hijo.

## Compatibilidad de presentación

`next_action.command` continúa disponible como texto orientado al operador para no romper la salida humana existente.

Invariante:

```text
command_spec = contrato de máquina
command      = presentación, no parseable
```

## Ownership

### TestKit

- schema y validación;
- admission de executor;
- ejecución de argv/env/cwd;
- persistencia del spec dentro de decisión/ejecución del agente;
- rechazo de formas shell libres.

### Host/consumidor

- seleccionar qué suites/tests invoca mediante contratos públicos existentes;
- proveer env/fixtures propios cuando corresponda;
- no interpretar `command` como contrato de automatización.

## Fuera de alcance de v1

- `external-runtime`;
- AutoCAD, PLC, HMI u otros dominios propietarios;
- nuevos estados/reportes de I8;
- nuevos executors remotos;
- secretos dentro de specs;
- cambios en Base, Pruebas o consumidores.

## Tests focales

```bash
php tests/framework/test_command_spec_contract.php
php tests/framework/test_agent_command_spec_admission.php
php tests/framework/test_agent_decision_contract.php
php tests/framework/test_agent_run_contract.php
```

La suite framework completa sigue siendo el gate de regresión:

```bash
php tests/framework/run.php
```
