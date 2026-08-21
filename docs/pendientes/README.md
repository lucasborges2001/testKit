# Pendientes de testKit

Esta carpeta contiene únicamente trabajo que todavía requiere implementación o cambios contractuales.

## Corte auditado

```text
Repositorio: lucasborges2001/testKit
Rama: main
Baseline documental vigente: 5e63de167469ca89c5a7f5adcc0862e4484958b2
Fecha de actualización: 2026-08-21
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

- `20260821-1432-p1-plc-functional-hil-identity-integration.md`: hardening del PLC Functional HIL para exigir evidencia versionada de runtime/application/bridge identity antes de habilitar writes, sin mover semántica de consumidores a TestKit;
- `run-suite-config-failure-output.md`: captura acotada de stdout/stderr para fallos grandes sin perder evidencia completa ni cambiar exit codes;
- `normalizacion-contratos/pendiente-interno-testkit.md`: normalización interna restante, con estado por fase y sin mezclar trabajo ya cerrado;
- `normalizacion-contratos/pendiente-integraciones-externas.md`: trabajo que exige cambios o evidencia fuera de testKit;
- `external-runtime-executor.md`: executor genérico para runtimes externos todavía no implementado;
- `processrunner-timeout-windows.md`: terminación/timeout de procesos PHP nativos en Windows todavía no implementada de forma verificable.

## PLC Functional HIL

Capacidad implementada hoy:

```text
FC06 single holding register
logical stimulus ids host-owned
allowlist bounded
explicit write enable
no coils
no FC16
no ranges
no scanning
```

La documentación vigente exige que el host realice runtime/application identity gate antes de habilitar writes. El cliente actual no implementa por sí mismo un contrato versionado de application/bridge identity; esa deuda queda explícita en:

```text
docs/pendientes/20260821-1432-p1-plc-functional-hil-identity-integration.md
```

Esto no invalida la capability FC06 existente. Define el hardening requerido antes de usarla como pieza de una integración HIL real de un consumidor.

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