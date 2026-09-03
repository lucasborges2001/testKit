# Pendientes de TestKit

Esta carpeta contiene únicamente deuda accionable que todavía requiere implementación o cambio contractual dentro de TestKit. La implementación existente que espera integración/evidencia se registra en `docs/verificaciones/`.

## Corte auditado

```text
Repositorio: lucasborges2001/testKit
Rama: main
Baseline inicial de la expansión PLC: 3c7c4b3c2212092c7a5cb7f6f7e381c1aab1e69d
Fecha de actualización: 2026-09-03
```

## Frontera documental

| Carpeta | Contenido |
|---|---|
| `docs/pendientes/` | Deuda funcional/contractual TestKit todavía no implementada. |
| `docs/verificaciones/` | Implementación existente cuya aceptación/integración todavía requiere gates. |

`BLOCKED`, `NOT_EXECUTED`, `UNKNOWN` y `UNAVAILABLE` no equivalen a `PASS`.

## Inventario activo

- `remote-target-suite-isolation.md`: aislamiento de suites focalizadas por owner para que un fallo baseline ajeno no impida validar el SHA candidato exacto;
- `run-suite-config-failure-output.md`: captura acotada de stdout/stderr para fallos grandes, incluyendo causas tardías, sin perder evidencia completa ni cambiar exit codes;
- `suite-policy-disposable-cleanup.md`: lifecycle/cleanup machine-readable para suites `disposable` sin duplicar policy reforzada en cada host;
- `processrunner-timeout-windows.md`: timeout/terminación verificable de procesos PHP nativos en Windows;
- `external-runtime-executor.md`: executor genérico de runtimes externos; requiere evidencia de consumidores y reutilización de contratos canónicos;
- `normalizacion-contratos/pendiente-interno-testkit.md`: deuda interna restante de normalización;
- `normalizacion-contratos/pendiente-integraciones-externas.md`: inventario, migración y cutover de consumidores externos.

## PLC

La infraestructura PLC reusable de esta expansión ya no es deuda de implementación TestKit:

```text
identity-gated FunctionalHilSession
exact allowlisted FC06
FunctionalHilLifecycle
coherent snapshots
scan-driven waiting
stress/soak orchestration
safe PLC artifacts
readonly multi-runtime/application-map infrastructure
browser WebVisu black-box con TLS policy/artifacts sanitizados
```

La integración de consumidores continúa en:

```text
docs/verificaciones/plc-functional-hil-identity-integration.md
docs/verificaciones/plc-webvisu-blackbox-integration.md
```

Los siguientes elementos no deben reabrirse como deuda de TestKit porque pertenecen a consumidores u otros owners:

```text
consumer register/signal maps
consumer screen selectors/actions
consumer fixture/lease values
consumer CoDeSys/e!COCKPIT build/import/compile
consumer application identities
BasePLC IEC-ST analysis
runtime hardware HIL
physical I/O authorization
real WebVisu target/auth/navigation policy
host-specific snapshot/host-live execution scope
host .env access policy
host Git/systemd/polling/report publication
```

Un gate que encuentre un defecto reproducible en una primitive TestKit sí justifica crear un pendiente nuevo y concreto.

## Regla de mantenimiento

Conservar un pendiente sólo mientras exista deuda concreta con owner, evidencia, criterio de aceptación y validación. Cuando el código requerido exista y sólo falte integración o evidencia de ejecución, mover el seguimiento a `docs/verificaciones/` en vez de mantener dos fuentes de verdad.
