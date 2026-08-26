# Normalización contractual — backlog vigente

## Estado

```text
ACTIVO
BASELINE: 132ed52e49f530231206e6c4358fe6d3dedf8b19
FECHA: 2026-08-26
```

Este directorio contiene únicamente los dos frentes de normalización que siguen abiertos:

- [`pendiente-interno-testkit.md`](pendiente-interno-testkit.md): cambios que pertenecen a `testKit`;
- [`pendiente-integraciones-externas.md`](pendiente-integraciones-externas.md): inventario, migración y cutover que requieren evidencia o cambios en consumidores.

## Autoridades vigentes

Contrato público:

```text
docs/CONTRACT_REGISTRY.md
docs/CONTRATO.md
docs/USO.md
```

Ejecución tipada:

```text
docs/COMMAND_SPEC.md
docs/verificaciones/i6-command-spec-v1.md
```

Resultado operativo tipado:

```text
docs/OPERATION_RESULT_V2.md
docs/verificaciones/i8-a-operation-result-v2.md
```

`docs/CONTRACT_REGISTRY.md` se genera desde `Testkit\Core\Config\ContractRegistry`; no debe sustituirse por listas históricas mantenidas manualmente.

## Limpieza del corte histórico

El corte iniciado el 28 de julio de 2026 ya estaba documentado como cerrado. Los archivos `fase-*` y `cierre-corte-2026-07-28.md` se retiraron de `docs/pendientes/` porque eran evidencia histórica, no backlog operativo.

La trazabilidad de esas decisiones permanece disponible en el historial Git. No copiar comandos, aliases, variables o rutas de esos commits a integraciones nuevas sin revalidarlos contra el contrato actual.

## Deuda interna resumida

| Fase | Estado actual |
|---|---|
| I3 — Stack estricto | `ACTIVO` |
| I4 — Selección única sin bridge `TEST_MATCH*` | `ACTIVO` |
| I5 — Coverage único | `ACTIVO` |
| I6 — `command_spec` | `IMPLEMENTADO / VERIFICACION_PENDIENTE` |
| I7-A — Paridad contractual Bash/PowerShell | `ACTIVO` |
| I7-B — Runtime Windows | `VERIFICACION_DEPENDIENTE_DE_I7_A` |
| I8-A — `OperationResultV2` | `IMPLEMENTADO / VERIFICACION_PENDIENTE` |
| I8-B — Convergencia de reporting | `ACTIVO` |
| I9 — Gates finales | `PARCIAL` |

El detalle ejecutable y la evidencia actual están en `pendiente-interno-testkit.md`.

## Deuda externa

`pendiente-integraciones-externas.md` no autoriza modificar `Base`, `Pruebas` ni otros consumidores. E1 es inventario read-only; cualquier migración requiere autorización y baseline propios por repositorio.

## Regla para nuevas actualizaciones

No volver a crear archivos `fase-*` en este directorio.

- deuda nueva independiente: `docs/pendientes/<tema>.md`;
- deuda de normalización interna: actualizar `pendiente-interno-testkit.md`;
- implementación existente pendiente sólo de evidencia: `docs/verificaciones/`.

## No verificado por esta auditoría documental

- PASS de I3, I4, I5, I7-A, I8-B o I9;
- runtime Windows real;
- estado actual de consumidores externos;
- CI completa sobre el baseline auditado;
- cutover, release o tag.
