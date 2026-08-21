# P1 — Hardening de PLC Functional HIL: identity gate e integración host

## Estado

```text
ABIERTO
IMPLEMENTACION PENDIENTE
HARDWARE REAL: FUERA DE ALCANCE
```

Baseline auditado:

```text
testKit/main: 5e63de167469ca89c5a7f5adcc0862e4484958b2
BasePLC/main observado: f9e0ab03927fd5b178896949bb9675a036298622
Pruebas/main observado: 2020e5638e11e97590e7ca7d090d4b8840c9a43d
fecha: 2026-08-21
```

## Evidencia verificada

TestKit ya expone una capacidad PLC Functional HIL deliberadamente acotada:

```text
ModbusTcpFunctionalHilClient
FC06 single holding register
host-owned logical stimulus allowlist
max 64 registros exactos
writeEnabled explícito
sin coil writes
sin FC16
sin ranges/wildcards
sin address scanning
FC03 read-only delegado a ModbusTcpReadOnlyClient
```

También existen capacidades separadas para:

```text
RuntimeProfileDetector
ReadOnlyApplicationMapValidator
ReadOnlyApplicationMapProbe
```

La documentación vigente exige al host ejecutar un `runtime/application identity gate` antes de habilitar writes y exige un bridge PLC-local con lease/timeout.

## Gap exacto

El cliente Functional HIL recibe actualmente `writeEnabled: true|false`, pero TestKit no dispone de un contrato público versionado que permita verificar, antes de construir/habilitar la sesión de escritura, evidencia host-owned de:

```text
runtime identity
application identity
logical test bridge identity
```

`RuntimeProfileDetector` demuestra identidad/perfil de runtime. No demuestra por sí mismo que la aplicación esperada ni el bridge lógico correcto estén desplegados.

La semántica de aplicación pertenece al host/consumidor y **no debe moverse a TestKit**. El gap de TestKit es una frontera reutilizable para consumir y validar evidencia de gate, no implementar reglas de Locker/Cargador/BasePLC.

## Objetivo

Agregar una frontera pública fail-closed para abrir una sesión Functional HIL sólo después de recibir evidencia explícita y válida del host.

Diseño objetivo conceptual:

```text
host-owned identity probes
-> functional_hil_gate@1 evidence
-> TestKit validates gate envelope
-> all required identities PASS
-> allowlist validated
-> Functional HIL write session enabled
```

No:

```text
RuntimeProfileDetector == DETECTED
-> writeEnabled=true
```

## Ownership

### TestKit

Debe ser dueño de:

- schema/DTO neutral del gate de habilitación HIL;
- validación estructural y estados permitidos;
- decisión fail-closed `writes_allowed` basada únicamente en evidencia explícita;
- integración con su cliente Functional HIL/factory pública;
- tests con fake Modbus server;
- reporting machine-readable sanitizado cuando corresponda.

### Host/consumidor

Debe ser dueño de:

- cómo se prueba application identity;
- cómo se prueba bridge identity;
- expected application id/version/hash cuando aplique;
- stimulus map y observation map;
- bridges, leases y heartbeat PLC-local;
- escenarios e invariantes de dominio.

TestKit no debe conocer conceptos de Locker, Cargador ni otro consumidor.

### BasePLC

BasePLC conserva su PLC Test Model y `PlcExecutionBackend`. La frontera Python/PHP con TestKit pertenece al host adapter; este pendiente no convierte TestKit en executor de BasePLC.

## Contrato candidato

Nombre conceptual:

```text
functional_hil_gate@1
```

Debe distinguir al menos:

```text
runtime: PASS | FAIL | UNKNOWN | UNAVAILABLE
application: PASS | FAIL | UNKNOWN | UNAVAILABLE
bridge: PASS | FAIL | UNKNOWN | UNAVAILABLE
writes_allowed: boolean
```

Metadata permitida debe ser sanitizada y bounded. No incluir:

```text
passwords
secrets
raw memory dumps
arbitrary shell output
unbounded register maps
physical output addresses
```

Regla:

```text
writes_allowed == true
iff
runtime == PASS
and application == PASS
and bridge == PASS
and allowlist válida
and write opt-in explícito
```

Cualquier otro estado debe mantener writes deshabilitados.

## Integración pública candidata

Preferir una factory/session API pública antes que ampliar `ModbusTcpFunctionalHilClient` con múltiples flags ambiguos.

Ejemplo conceptual, no API implementada:

```php
$session = FunctionalHilSession::open(
    gateEvidence: $hostEvidence,
    stimulusMap: $hostOwnedMap,
    writeRequested: true,
    transport: $transportConfig,
);

$session->writeStimulus('input.raw_known', 1);
```

La API definitiva debe derivarse de los patrones reales del repo y mantener compatibilidad cuando sea razonable.

## Criterios PASS

### Contrato/gate

- evidencia incompleta no habilita writes;
- `UNKNOWN` y `UNAVAILABLE` no habilitan writes;
- runtime PASS + application FAIL produce 0 writes;
- runtime PASS + application PASS + bridge FAIL produce 0 writes;
- sólo los tres PASS permiten continuar;
- ningún estado se infiere desde texto libre.

### Allowlist/transporte

- logical id desconocido falla antes del socket write;
- máximo 64 registros exactos se conserva;
- direcciones duplicadas siguen rechazadas;
- no aparece API arbitrary-address write;
- no se agregan coils, FC16, ranges ni scanning;
- errores Modbus siguen propagándose como fallo/unavailable explícito.

### Bridge safety

- el contrato sigue exigiendo lease/timeout PLC-local;
- TestKit no afirma poder liberar ownership sintético remotamente tras una caída de red;
- la ausencia de evidencia de lease/bridge válido bloquea writes.

### Reporting

- la evidencia puede registrarse sin secretos ni direcciones físicas arbitrarias;
- si se integra con `operation_result@2`, no se redefine su semántica ni exit codes.

### Tests

- todo el gate se prueba con doubles/fake Modbus server;
- 0 conexiones a PLC real;
- test negativo confirma 0 FC06 cuando cualquier gate no es PASS.

## Criterios FAIL

- mantener únicamente `writeEnabled=true` como control suficiente;
- asumir que runtime profile detectado implica application identity;
- mover expected application ids de consumidores a TestKit;
- crear un segundo modelo de dominio PLC paralelo a BasePLC;
- permitir raw register address desde el escenario del consumidor;
- ampliar la capability a outputs físicos;
- requerir PLC real para validar el contrato base.

## Dependencias

- preservar API pública PLC existente durante el hardening o documentar cualquier ruptura explícita;
- coordinar el adapter real con el host que consume TestKit;
- el consumidor debe definir application/bridge identity antes de una ejecución HIL real;
- el bridge PLC-local debe existir antes de cualquier write sobre hardware.

## Validación reproducible futura

Antes de implementar, desde testKit:

```bash
cd ~/Escritorio/Pruebas/submodules/Base/testkit
git status --short
git branch --show-current
```

Luego, según archivos reales creados:

```bash
php -l <archivos-php-modificados>
php tests/framework/test_plc_modbus_functional_hil.php
php tests/framework/<gate-contract-test>.php
php tests/framework/<gate-negative-write-test>.php
git diff --check
```

La suite amplia debe reportarse con resultados exactos y distinguir fallos del baseline.

## Relación con BasePLC P4B

BasePLC cerró P4A con un executor neutral de backend. Su P4B pendiente requiere un host adapter hacia TestKit.

Este pendiente de TestKit habilita una frontera más segura para ese adapter, pero no implementa:

```text
Pruebas PHP/Python bridge
BasePLC PlcExecutionBackend adapter
consumer signal map
consumer application identity probe
hardware HIL
```

Esos cambios pertenecen a sus repositorios/owners respectivos.

## Fuera de alcance

Este pendiente no autoriza:

```text
modificar BasePLC
modificar Pruebas
modificar Locker/Cargador
actualizar gitlinks
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

1. existe un gate público/versionado y fail-closed;
2. Functional HIL no puede habilitar writes mediante la nueva ruta sin runtime+application+bridge PASS;
3. la semántica de application identity sigue host-owned;
4. los negativos prueban 0 FC06 ante gate no PASS;
5. no se amplió la superficie a I/O físico ni arbitrary writes;
6. documentación y tests reflejan la API realmente implementada.