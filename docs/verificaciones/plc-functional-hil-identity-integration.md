# Verificación — PLC Functional HIL identity gate e integración

Fecha: 2026-08-23

## Estado

```text
STATUS: PENDIENTE
IMPLEMENTATION: CLOSED
TESTKIT_GATE: IMPLEMENTED
PRUEBAS_HOST_ROUTE: IMPLEMENTED
CENTROLOGISTICO_CONSUMER_PROBE: IMPLEMENTED
HARDWARE_REAL: OUT_OF_SCOPE_FOR_THIS_VERIFICATION
```

Baselines de referencia:

```text
TestKit/main: 2ec99c34c8230b2dee34c261481fb36b1206740f
Pruebas/main observado: fecd5075cafa061365f4ac8a60ac88b8ad66e32a
CentroLogistico/main observado: e8c18aa67c28e4fc876414f153ca2f791d0d9076
```

## Por qué es verificación y no pendiente

La implementación requerida ya existe:

- `FunctionalHilGate` y `functional_hil_gate@1`;
- `FunctionalHilSession`;
- write enable sólo con runtime/application/bridge `PASS` + opt-in explícito;
- metadata bounded/sanitizada;
- negativos TestKit con `write_disabled` y cero FC06;
- adapter host test-only en `Pruebas` que consume la API pública;
- consumidor CentroLogistico que obtiene application identity por FC03 y usa `FunctionalHilSession`.

El documento anterior bajo `docs/pendientes/` se retiró porque sus gaps de implementación quedaron cerrados. Lo pendiente es ejecutar gates reproducibles sobre los cortes integrados.

## V1 — TestKit owner

Ejecutar desde el checkout real:

```bash
cd ~/Escritorio/Pruebas/submodules/Base/testkit

export TESTKIT_PROJECT_ROOT=~/Escritorio/Pruebas

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

PASS:

- todos los comandos salen 0;
- gate no PASS produce cero FC06 en la fixture;
- all PASS + opt-in produce exactamente el write permitido;
- no aparecen coils, FC16, range writes ni scanning;
- gate report no filtra register map ni secretos.

## V2 — composición host Pruebas con fake local

Ejecutar desde `Pruebas` sin PLC real:

```bash
cd ~/Escritorio/Pruebas

git status --short
git submodule status --recursive | grep -E 'submodules/(BasePLC|Base($|/testkit))'
php test/infra/plc/baseplc_testkit_p4b.integration.test.php
TESTKIT_MODE=agent ./submodules/Base/testkit/bin/testkit run --rm testkit php runTest.php --suite infra-php
git diff --check
```

PASS requiere que el host demuestre success + identity mismatch con cero writes adicionales y artifacts/result compatibles sin acceder a internals de TestKit.

## V3 — consumidor CentroLogistico sin hardware

Con un checkout integrado de CentroLogistico:

```bash
cd ~/Escritorio/CentroLogistico

php test/infra/plc/security/functional_hil_identity_gate.security.test.php
php test/infra/plc/security/readonly_test_evidence_boundaries.security.test.php
git diff --check
```

PASS confirma que un consumidor real mantiene application/bridge semantics fuera de TestKit y que un mismatch queda fail-closed antes del transporte writable.

Esta verificación **no** exige ejecutar el PLC real. El mapping e!COCKPIT, FC03 real y Functional HIL real pertenecen al consumidor y a su nivel de evidencia autorizado.

## Resultado esperado

```text
V1 PASS
V2 PASS
V3 PASS
=> cerrar esta verificación
```

Un `FAIL` reproducible en un entorno correcto obliga a crear/reabrir un pendiente de implementación con owner y causa exactos.

`BLOCKED` por entorno no equivale a PASS.

## No cubre

- PLC/e!RUNTIME real;
- addresses reales de consumidores;
- deploy/download;
- `%Q`;
- actuadores físicos;
- FTP/serial/HTTP de consumidores;
- semántica de CentroLogistico, Locker u otro dominio.
