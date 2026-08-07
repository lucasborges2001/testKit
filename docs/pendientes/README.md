# Pendientes de testKit

Esta carpeta contiene únicamente trabajo que todavía requiere implementación.

## Frontera documental

| Carpeta | Contenido |
|---|---|
| `docs/pendientes/` | Código, contratos, adapters, configuración, suites, integración o documentación funcional todavía no implementados. |
| `docs/verificaciones/` | Implementación existente cuya aceptación todavía requiere ejecutar gates reproducibles. |

Un trabajo implementado no permanece en `docs/pendientes/` como histórico.

## Ciclo obligatorio

```text
PENDIENTE
-> implementación
-> borrar/reducir pendiente
-> crear VERIFICACION si todavía falta evidencia
-> ejecutar gate
-> PASS: borrar verificación
-> FAIL: crear/reabrir pendiente de implementación
```

`BLOCKED` no equivale a `PASS` y no autoriza a retirar la verificación.

## Criterios para permanecer en pendientes

Un documento pertenece aquí si al menos una condición es cierta:

- falta crear o modificar código;
- falta definir o cambiar un contrato;
- falta un adapter o wrapper;
- falta una suite o un test necesario para demostrar el comportamiento;
- falta configuración o wiring;
- falta integración con un consumidor;
- un `FAIL` reproducible exige corregir implementación.

No conservar en esta carpeta documentos cuyo único paso restante sea ejecutar una validación ya definida.

## Inventario activo

- `normalizacion-contratos/pendiente-interno-testkit.md`: normalización interna todavía no implementada;
- `normalizacion-contratos/pendiente-integraciones-externas.md`: cambios/evidencia requeridos fuera de testKit;
- `external-runtime-executor.md`: executor genérico para runtimes externos todavía no implementado.

Los documentos antiguos de fases implementadas dentro de `normalizacion-contratos/` son deuda documental heredada y deben retirarse o consolidarse cuando se audite su evidencia; no son autoridad sobre el backlog actual.
