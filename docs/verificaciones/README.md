# Verificaciones pendientes

Esta carpeta conserva validaciones de implementaciones existentes que todavía requieren ejecución en consumidores, CI, Docker, Windows u otra infraestructura específica.

## Frontera documental

| Carpeta | Contenido |
|---|---|
| `docs/pendientes/` | Trabajo funcional/contractual todavía no implementado. |
| `docs/verificaciones/` | Implementación existente con gates o integración todavía no confirmados. |

## Estados

```text
PENDIENTE     gate todavía no ejecutado o evidencia incompleta
PASS          comportamiento esperado demostrado
FAIL          comportamiento incorrecto demostrado
BLOCKED       entorno/dependencia impide ejecutar el gate
NOT_EXECUTED  ejecución deliberadamente no realizada
```

Ningún estado distinto de `PASS` se promociona implícitamente a PASS.

## Inventario

- `02_store_explicito.md`: cierre de I2; valida store explícito sin aliases, inferencias ni fallback de driver.
- `i6-command-spec-v1.md`: verificación del contrato command spec.
- `i8-a-operation-result-v2.md`: verificación de OperationResult v2.
- `plc-functional-hil-identity-integration.md`: TestKit PLC HIL framework ya implementado y gateado localmente; resta pin/integración verificable de consumidores. Hardware real continúa fuera de este gate.

## Cierre

Después de obtener PASS total del alcance documentado:

1. registrar el baseline y RCs reales;
2. actualizar documentación estable si el gate descubre diferencias;
3. retirar la verificación cerrada del inventario activo;
4. conservar la trazabilidad histórica en Git.

Un FAIL reproducible debe volver a deuda de implementación con owner y causa concretos; no debe esconderse como simple verificación pendiente.
