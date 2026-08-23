# Verificaciones pendientes

Esta carpeta conserva únicamente validaciones de implementaciones que ya existen, pero todavía requieren ejecución local, CI real, Docker, Windows u otra infraestructura externa.

## Frontera documental

| Carpeta | Contenido |
|---|---|
| `docs/pendientes/` | Trabajo todavía no implementado. |
| `docs/verificaciones/` | Implementación existente con gates reproducibles todavía no confirmados. |

Un documento no entra aquí mientras todavía requiera crear o modificar código, contratos, adapters, suites o configuración para poder ejecutar el gate.

## Estados

```text
PENDIENTE  gate todavía no ejecutado o evidencia incompleta
PASS       comportamiento esperado demostrado
FAIL       comportamiento incorrecto demostrado
BLOCKED    entorno o dependencia impide ejecutar el gate
```

`BLOCKED` no equivale a `PASS`.

## Criterios de entrada

1. la implementación necesaria ya existe;
2. no faltan archivos funcionales para ejecutar el gate;
3. existe un procedimiento reproducible;
4. están definidos resultado esperado y evidencia;
5. un `PASS` permite cerrar sin otra fase de implementación.

## Inventario

- `02_store_explicito.md`: cierre de I2; valida store explícito sin aliases, inferencias ni fallback de driver.
- `i6-command-spec-v1.md`: verificación del contrato de command spec ya implementado.
- `i8-a-operation-result-v2.md`: verificación del contrato OperationResult v2 ya implementado.
- `plc-functional-hil-identity-integration.md`: gate/session Functional HIL + integración host + consumidor real; implementación cerrada, falta ejecutar gates owner/host/consumer sin requerir PLC real.

## Cierre

Después de obtener `PASS`:

1. registrar baseline y resultado donde corresponda;
2. actualizar documentación estable si el gate descubre diferencias;
3. borrar el documento de `docs/verificaciones/`;
4. no mantener verificaciones cerradas como histórico.

Después de un `FAIL` reproducible:

1. identificar la causa y el owner;
2. crear o reabrir un pendiente de implementación;
3. definir archivos, contrato, pruebas y rollback;
4. no conservar el defecto como simple verificación.
