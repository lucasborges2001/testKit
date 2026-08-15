# Pendientes de testKit

Esta carpeta contiene únicamente trabajo que todavía requiere implementación o cambios contractuales.

## Corte auditado

```text
Repositorio: lucasborges2001/testKit
Rama: main
Baseline documental: 8fd6cca8b91167c57bd4189e81365e5e4d34e3da
Fecha de auditoría: 2026-08-15
```

## Frontera documental

| Carpeta | Contenido |
|---|---|
| `docs/pendientes/` | Código, contratos, adapters, configuración, suites, integración o documentación funcional todavía no implementados. |
| `docs/verificaciones/` | Implementación existente cuya aceptación todavía requiere ejecutar gates reproducibles. |

Un trabajo implementado no debe permanecer como backlog activo. Si no se autoriza borrar un documento histórico, debe quedar marcado explícitamente como referencia histórica y no como fuente de verdad operativa.

## Ciclo obligatorio

```text
PENDIENTE
-> implementación
-> reducir/cerrar pendiente
-> crear VERIFICACION si todavía falta evidencia
-> ejecutar gate
-> PASS: cerrar verificación
-> FAIL: crear/reabrir pendiente de implementación
```

`BLOCKED` no equivale a `PASS`.

## Inventario activo verificado

- `run-suite-config-failure-output.md`: captura acotada de stdout/stderr para fallos grandes sin perder evidencia completa ni cambiar exit codes;
- `normalizacion-contratos/pendiente-interno-testkit.md`: normalización interna restante, con estado por fase y sin mezclar trabajo ya cerrado;
- `normalizacion-contratos/pendiente-integraciones-externas.md`: trabajo que exige cambios o evidencia fuera de testKit;
- `external-runtime-executor.md`: executor genérico para runtimes externos todavía no implementado;
- `processrunner-timeout-windows.md`: terminación/timeout de procesos PHP nativos en Windows todavía no implementada de forma verificable.

## Documentos históricos de normalización

Los documentos `fase-*` y `cierre-corte-2026-07-28.md` bajo `normalizacion-contratos/` registran decisiones y evidencia de cortes anteriores. No son autoridad sobre el backlog actual.

La autoridad para trabajo interno pendiente es:

```text
docs/pendientes/normalizacion-contratos/pendiente-interno-testkit.md
```

La autoridad para integraciones externas pendientes es:

```text
docs/pendientes/normalizacion-contratos/pendiente-integraciones-externas.md
```

No reabrir una fase histórica por su sola presencia en el árbol. Reabrir únicamente ante evidencia actual de deuda funcional o contractual.

## Contrato documental vigente

Para selectores públicos, la referencia canónica es `docs/CONTRACT_REGISTRY.md`, generado desde `Testkit\Core\Config\ContractRegistry`.

La documentación operativa debe respetar:

```text
exactamente uno de --suite | --group | --category
--test <repo-relative> repetible para selección explícita
--selection-file <repo-relative> para lotes declarados
sin targets posicionales
sin aliases públicos
```

Si un documento contradice el registro generado o el parser de `RunRequest`, el documento debe tratarse como desactualizado.
