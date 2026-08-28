# Verificación — PLC Functional HIL e integración de consumidores

Fecha de actualización: 2026-08-28

## Estado

```text
TESTKIT_FRAMEWORK_IMPLEMENTATION: CLOSED
TESTKIT_LOCAL_DETERMINISTIC_GATES: PASS
CONSUMER_INTEGRATION: PENDIENTE
HARDWARE_REAL: NOT_EXECUTED
PHYSICAL_IO: NOT_AUTHORIZED
```

La expansión TestKit agrega lifecycle seguro, snapshots coherentes, espera por scan, stress/soak y artifacts PLC sin mover semántica de consumidores al framework.

## Gates owner TestKit ejecutados

Sobre el árbol de implementación de esta fase se ejecutaron con RC `0`:

```bash
php -l core/php/plc/FunctionalHilGate.php
php -l core/php/plc/FunctionalHilSession.php
php -l core/php/plc/PlcArtifact.php
php -l core/php/plc/CoherentSnapshotReader.php
php -l core/php/plc/ScanDrivenWait.php
php -l core/php/plc/FunctionalHilLifecycle.php
php -l core/php/plc/StressSoakRunner.php

php tests/framework/test_plc_modbus_functional_hil.php
php tests/framework/test_plc_functional_hil_gate.php
php tests/framework/test_plc_modbus_readonly_profiles.php
php tests/framework/test_plc_hil_validation_primitives.php
```

La evidencia es exclusivamente local/determinista y no se promociona a hardware PASS.

## Seguridad demostrada por framework

Los tests focales demuestran, sin PLC real:

```text
identity FAIL -> 0 FC06
identity UNKNOWN -> 0 FC06
unallowlisted logical stimulus -> 0 FC06
pre-arm failure -> 0 FC06
exception after arm -> release/cleanup attempted
heartbeat failure -> cleanup attempted
release failure -> bounded retry/cleanup, final FAIL
cleanup write failure -> final FAIL
transport failure after arm -> cleanup attempted
cleanup idempotent -> no repeated writes
snapshot torn -> rejected/retried, bounded INCONSISTENT
stalled scan -> bounded TIMEOUT
secret-like artifact metadata -> redacted
```

## Consumidores — todavía pendiente

El siguiente cierre debe hacerse fuera del commit framework de TestKit:

```text
1. fijar el nuevo SHA de TestKit en la topología contractual del consumidor;
2. adaptar el consumer adapter a FunctionalHilLifecycle / snapshot / scan primitives donde corresponda;
3. mantener maps, IDs, lease values y assertions en el consumidor;
4. retirar duplicación local sólo después de probar la ruta TestKit;
5. ejecutar smokes readonly y logic stress offline del consumidor;
6. mantener HIL real bloqueado mientras sus gates independientes no estén cerrados.
```

Para Locker, CoDeSys 2.3 import/compile y runtime HIL siguen siendo gates independientes. Este documento no los convierte en PASS.

## Qué no cubre

- PLC real;
- addresses reales de Locker u otro consumidor;
- compilación/import de CoDeSys;
- Run/Stop/Download/Online Change/Force;
- coils o FC16;
- `%Q` o actuadores físicos;
- autorización de salidas físicas.

## Criterio de cierre de esta verificación

```text
TestKit owner gates PASS
+
consumer pin actualizado de forma verificable
+
consumer integration/offline gates PASS
=> cerrar verificación de integración
```

`NOT_EXECUTED`, `UNKNOWN`, `UNAVAILABLE` o `BLOCKED` no equivalen a PASS.
