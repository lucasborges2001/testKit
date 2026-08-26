# Pendientes de TestKit

Esta carpeta contiene únicamente deuda accionable que todavía requiere implementación, cambio contractual o integración. La implementación que ya existe y sólo espera evidencia reproducible se registra en `docs/verificaciones/`.

## Corte auditado

```text
Repositorio: lucasborges2001/testKit
Rama: main
Baseline auditado: 132ed52e49f530231206e6c4358fe6d3dedf8b19
Fecha de actualización: 2026-08-26
```

## Frontera documental

| Carpeta | Contenido |
|---|---|
| `docs/pendientes/` | Deuda funcional, contractual o de integración todavía no implementada/cerrada. |
| `docs/verificaciones/` | Implementación existente cuya aceptación todavía requiere gates reproducibles o un entorno específico. |

Ciclo esperado:

```text
PENDIENTE
-> implementación
-> reducir/cerrar pendiente
-> VERIFICACION si sólo falta evidencia
-> ejecutar gate
-> PASS: cerrar verificación
```

`BLOCKED` no equivale a `PASS`.

## Inventario activo

- `run-suite-config-failure-output.md`: captura acotada de stdout/stderr para fallos grandes sin perder evidencia completa ni cambiar exit codes;
- `processrunner-timeout-windows.md`: timeout/terminación verificable de procesos PHP nativos en Windows;
- `external-runtime-executor.md`: executor genérico de runtimes externos; requiere evidencia de consumidores y debe reutilizar los contratos canónicos ya existentes;
- `normalizacion-contratos/pendiente-interno-testkit.md`: deuda interna restante de normalización;
- `normalizacion-contratos/pendiente-integraciones-externas.md`: inventario, migración y cutover de consumidores externos.

## Implementación existente con verificación pendiente

Estos puntos ya no son backlog de implementación:

- **I6 — `command_spec` v1**: contrato `testkit.command_spec@1` implementado. Referencias: `docs/COMMAND_SPEC.md` y `docs/verificaciones/i6-command-spec-v1.md`.
- **I8-A — `OperationResultV2`**: contrato implementado. Referencias: `docs/OPERATION_RESULT_V2.md` y `docs/verificaciones/i8-a-operation-result-v2.md`.
- **PLC Functional HIL**: implementación cerrada; la ejecución pendiente sigue en `docs/verificaciones/plc-functional-hil-identity-integration.md`.

Que una verificación siga abierta no autoriza a reintroducir la fase como deuda de implementación salvo que el gate encuentre un defecto concreto.

## Estado de la normalización contractual

El backlog operativo queda consolidado en:

```text
docs/pendientes/normalizacion-contratos/pendiente-interno-testkit.md
docs/pendientes/normalizacion-contratos/pendiente-integraciones-externas.md
```

El corte histórico de julio de 2026 ya estaba declarado cerrado y sus documentos `fase-*` eran evidencia, no pendientes. Con autorización explícita de limpieza se retiraron de `docs/pendientes`; la trazabilidad permanece en el historial Git.

## Contrato documental vigente

Para selectores públicos, la referencia canónica es `docs/CONTRACT_REGISTRY.md`, generado desde `Testkit\\Core\\Config\\ContractRegistry`.

La documentación operativa debe respetar exactamente uno de `--suite | --group | --category`, `--test` repetible y `--selection-file`; no reintroducir targets posicionales ni aliases públicos.

Para ejecución tipada usar `docs/COMMAND_SPEC.md`. Para resultados operativos tipados usar `docs/OPERATION_RESULT_V2.md`.

## Regla de mantenimiento

Un pendiente debe conservarse sólo mientras exista una deuda concreta con evidencia, objetivo, dependencias, criterio de aceptación y validación. Cuando el código requerido exista y sólo falte evidencia de ejecución, mover el seguimiento a `docs/verificaciones/` en vez de mantener dos fuentes de verdad.
