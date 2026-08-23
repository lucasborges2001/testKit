# Pendientes de TestKit

Esta carpeta contiene únicamente trabajo que todavía requiere implementación o cambios contractuales.

## Corte auditado

```text
Repositorio: lucasborges2001/testKit
Rama: main
Baseline funcional observado: 2ec99c34c8230b2dee34c261481fb36b1206740f
Fecha de actualización: 2026-08-23
```

## Frontera documental

| Carpeta | Contenido |
|---|---|
| `docs/pendientes/` | Código, contratos, adapters, configuración, suites, integración o documentación funcional todavía no implementados. |
| `docs/verificaciones/` | Implementación existente cuya aceptación todavía requiere ejecutar gates reproducibles. |

Ciclo obligatorio:

```text
PENDIENTE
-> implementación
-> reducir/cerrar pendiente
-> crear VERIFICACION si sólo falta evidencia
-> ejecutar gate
-> PASS: cerrar verificación
```

`BLOCKED` no equivale a `PASS`.

## Inventario activo

- `run-suite-config-failure-output.md`: captura acotada de stdout/stderr para fallos grandes sin perder evidencia completa ni cambiar exit codes;
- `normalizacion-contratos/pendiente-interno-testkit.md`: normalización interna restante;
- `normalizacion-contratos/pendiente-integraciones-externas.md`: trabajo que exige cambios o evidencia fuera de TestKit;
- `external-runtime-executor.md`: executor genérico para runtimes externos todavía no implementado;
- `processrunner-timeout-windows.md`: terminación/timeout de procesos PHP nativos en Windows todavía no implementada de forma verificable.

## PLC Functional HIL — implementación cerrada, verificación abierta

Ya existen:

```text
ModbusTcpReadOnlyClient / FC03
RuntimeProfileDetector
ReadOnlyApplicationMapProbe
ModbusTcpFunctionalHilClient / FC06 allowlisted
FunctionalHilGate
FunctionalHilSession
functional_hil_gate@1
```

La ruta pública segura exige:

```text
runtime.status == PASS
application.status == PASS
bridge.status == PASS
writeRequested == true
=> writes_allowed == true
```

El antiguo pendiente `20260821-1432-p1-plc-functional-hil-identity-integration.md` se retiró del backlog porque la implementación TestKit, la ruta host en Pruebas y un consumidor real en CentroLogistico ya existen.

La ejecución pendiente se registra en:

```text
docs/verificaciones/plc-functional-hil-identity-integration.md
```

El constructor low-level con booleano de write se conserva por compatibilidad; nuevas integraciones deben usar `FunctionalHilSession`.

## Serial/PTTY

No existe deuda genérica de PTY para el corte actual. TestKit ya dispone de:

```text
tests/framework/test_serial_readonly.php
tests/framework/fixtures/serial_pty_writer.py
```

La fixture cubre frames CR/LF/CRLF, frames consecutivos, timeout, overflow, config/device errors y el invariante read-only.

Los parsers y la gramática de scanner permanecen consumer-owned.

## FTP/HTTP/TCP fixtures

No se crea infraestructura preventiva.

Un fixture reusable nuevo requiere un consumidor real y un gap comprobado que no pueda resolverse con una fixture owner-local más pequeña. CentroLogistico todavía debe cerrar primero su escenario funcional determinista; FTP se evaluará después como dimensión de provisioning.

## Documentos históricos de normalización

Los documentos `fase-*` y `cierre-corte-2026-07-28.md` bajo `normalizacion-contratos/` son evidencia histórica, no backlog operativo. La autoridad para deuda actual sigue siendo `pendiente-interno-testkit.md` y `pendiente-integraciones-externas.md`.

## Contrato documental vigente

Para selectores públicos, la referencia canónica es `docs/CONTRACT_REGISTRY.md`, generado desde `Testkit\\Core\\Config\\ContractRegistry`.

La documentación operativa debe respetar exactamente uno de `--suite | --group | --category`, `--test` repetible y `--selection-file`; no reintroducir targets posicionales ni aliases públicos.
