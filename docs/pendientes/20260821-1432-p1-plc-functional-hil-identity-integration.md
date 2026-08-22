# P1 — Hardening de PLC Functional HIL: identity gate e integración host

## Estado

```text
ABIERTO
T1 IDENTITY GATE: IMPLEMENTADO / EXECUTION NOT_VERIFIED
HOST INTEGRATION: PENDIENTE
CONSUMER IDENTITY PROBES: PENDIENTE
HARDWARE REAL: FUERA DE ALCANCE
```

Baseline original auditado:

```text
testKit/main: 95e341bdc2095099a78bba29b00423b6fca7de37
fecha de implementación T1: 2026-08-22
```

Implementación T1 publicada:

```text
2494f929e29013224422513676b62bc7afde65a0
feat(plc): add fail-closed Functional HIL identity gate
```

Documentación alineada posteriormente en `docs/PLC_FUNCTIONAL_HIL.md`.

## Evidencia implementada

TestKit ya expone:

```text
ModbusTcpFunctionalHilClient
FC06 single holding register
host-owned logical stimulus allowlist
max 64 registros exactos
writeEnabled explícito en low-level compatibility client
sin coil writes
sin FC16
sin ranges/wildcards
sin address scanning
FC03 read-only delegado a ModbusTcpReadOnlyClient
```

El corte T1 agrega:

```text
FunctionalHilGate
functional_hil_gate@1
FunctionalHilSession::open(...)
runtime/application/bridge explicit statuses
computed identities_pass
write_requested + writes_allowed decision report
bounded/sanitized scalar metadata
blocked session -> write_disabled before transport
fake Modbus request counter for negative-write evidence
```

## Regla implementada

La nueva ruta segura habilita writes sólo si:

```text
runtime.status == PASS
and application.status == PASS
and bridge.status == PASS
and stimulus allowlist is valid
and writeRequested == true
```

Cualquier `FAIL`, `UNKNOWN`, `UNAVAILABLE` o envelope incompleto bloquea la nueva ruta.

El mapa de registros continúa host-owned y no se incluye en `gateReport()`.

## Compatibilidad deliberada

El constructor histórico:

```text
ModbusTcpFunctionalHilClient(..., writeEnabled: bool)
```

se conserva para no introducir una ruptura pública incidental.

Ese constructor low-level no constituye prueba de identidad. Nuevas integraciones host deben usar `FunctionalHilSession`.

Eliminar o hacer breaking el constructor histórico requiere una decisión de compatibilidad separada.

## Tests implementados

Nuevo framework test:

```text
tests/framework/test_plc_functional_hil_gate.php
```

Cubre por diseño:

- tres identidades PASS;
- `FAIL|UNKNOWN|UNAVAILABLE` bloquean;
- envelope incompleto se rechaza;
- metadata con claves secret-like se rechaza;
- write opt-out bloquea;
- allowlist duplicada sigue rechazándose;
- gate report no expone el mapa físico;
- application FAIL + write request produce `write_disabled`;
- fake server registra 0 FC06 para gate bloqueado;
- tres PASS + opt-in producen exactamente un FC06 en fake server.

El fixture existente se amplió sólo con un contador opcional de requests FC06; su comportamiento previo sigue disponible sin `--count`.

## Validación pendiente

No existe evidencia de ejecución local ni statuses GitHub observados para el commit T1 desde esta operación.

Por lo tanto:

```text
IMPLEMENTED != VERIFIED PASS
```

Validación reproducible:

```bash
cd ~/Escritorio/Pruebas/submodules/Base/testkit

git status --short
php -l core/php/plc/FunctionalHilGate.php
php -l core/php/plc/FunctionalHilSession.php
php -l core/php/plc/bootstrap.php
php -l tests/framework/test_plc_functional_hil_gate.php
php -l tests/framework/fixtures/fake_modbus_functional_hil_server.php
php tests/framework/test_plc_modbus_functional_hil.php
php tests/framework/test_plc_functional_hil_gate.php
git diff --check
```

La suite amplia debe ejecutarse posteriormente y sus fallos deben clasificarse entre baseline e introducidos.

## Ownership vigente

### TestKit

TestKit mantiene:

- schema/DTO neutral del gate;
- validación estructural;
- decisión fail-closed;
- sesión pública segura;
- transporte FC03/FC06 acotado;
- fake-server tests;
- reporting sanitizado del gate.

### Host/consumidor

Continúa siendo dueño de:

- cómo se prueba application identity;
- cómo se prueba bridge identity;
- expected application id/version/hash;
- stimulus map y observation map;
- bridges, leases y heartbeat PLC-local;
- scenarios e invariantes de dominio.

TestKit no debe conocer conceptos de Locker, CentroLogistico, Cargador ni otro consumidor.

### BasePLC

BasePLC conserva el PLC Test Model y `PlcExecutionBackend`. La frontera Python/PHP pertenece al host adapter.

## Gap real restante

T1 ya no es el bloqueo principal. Permanece abierto:

```text
Pruebas PHP/Python bridge
Pruebas PlcExecutionBackend adapter hacia TestKit
consumer runtime/application/bridge identity probes
consumer signal/stimulus maps
PLC-local lease/heartbeat implementation where applicable
pilot consumer
hardware HIL validation
```

También permanecen separados, fuera de T1:

```text
virtual serial/PTTY fixture
FTP fixture
deterministic HTTP/TCP fixtures
fault injection cross-transport
```

No deben mezclarse con el identity gate en un único cambio.

## Criterios PASS del siguiente corte host

- el host produce `functional_hil_gate@1` sin inventar estados;
- cualquier mismatch produce 0 writes;
- stimulus desconocido produce 0 writes;
- sólo logical ids allowlisted alcanzan FC06;
- observaciones permanecen bounded/read-only;
- pérdida del runner no deja ownership sintético indefinido porque el bridge posee lease local;
- el adapter host produce snapshots/result compatibles con BasePLC;
- tests de integración usan fake/doubles antes de PLC real.

## Criterios FAIL

- `RuntimeProfileDetector == DETECTED` habilita writes por sí solo;
- host evita `FunctionalHilSession` sin una razón de compatibilidad documentada;
- TestKit incorpora application ids de consumidores;
- TestKit importa BasePLC internals;
- dirección Modbus aparece en el plan lógico BasePLC;
- se agregan coil/FC16/ranges/scanning;
- se confunde gate PASS con autorización de outputs físicos;
- se declara hardware PASS con fake server.

## Relación con BasePLC

BasePLC ya puede expresar escenarios lógicos mediante su PLC Test Model y mantiene la frontera `PlcExecutionBackend`.

La composición pendiente es:

```text
BasePLC logical plan
-> Pruebas adapter
-> host/consumer identity evidence
-> FunctionalHilSession
-> TestKit FC06/FC03
-> observations
-> BasePLC snapshots/assertions/result
```

TestKit no se convierte en executor de BasePLC.

## Fuera de alcance

Este pendiente no autoriza por sí solo:

```text
modificar Pruebas gitlinks
modificar consumidores
red PLC activa
PLC deploy
CODESYS download
force %Q
coil/FC16 writes
actuadores físicos
operación de hardware real
```

## Criterio de cierre

Puede cerrarse cuando:

1. los tests T1 sean ejecutados y exista evidencia PASS o fallos corregidos;
2. `Pruebas` consuma la ruta pública segura mediante adapter;
3. al menos un consumidor aporte application/bridge identity real sin mover su semántica a TestKit;
4. los negativos prueben 0 FC06 ante gate no PASS también desde el host adapter;
5. no se amplíe la superficie a I/O físico ni arbitrary writes;
6. documentación y comportamiento permanezcan alineados.
