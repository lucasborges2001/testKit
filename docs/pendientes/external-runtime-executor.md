# Pendiente — Executor genérico de runtime externo

## Estado

Planificado. No implementado.

```text
Baseline auditado: 132ed52e49f530231206e6c4358fe6d3dedf8b19
Fecha: 2026-08-26
```

No está listo para implementación inmediata: el contrato de comando ya existe, pero todavía falta demostrar consumidores reales y cerrar la convergencia de resultados/reporting necesaria para no crear un contrato paralelo.

## Dependencias

### D1 — `command_spec` canónico

Estado: `IMPLEMENTED_VERIFICATION_PENDING`.

I6 ya no bloquea por falta de implementación. TestKit dispone de `testkit.command_spec@1`, documentado en `docs/COMMAND_SPEC.md`, con verificación abierta en `docs/verificaciones/i6-command-spec-v1.md`.

El executor externo debe reutilizar o extender ese contrato. No debe inventar un segundo modelo de comando ni volver a strings shell libres como contrato primario.

### D2 — Inventario de consumidores externos

Estado: `BLOCKED_BY_E1`.

Antes de fijar descriptor, requisitos o alcance, ejecutar E1 de [`normalizacion-contratos/pendiente-integraciones-externas.md`](normalizacion-contratos/pendiente-integraciones-externas.md).

E1 debe confirmar al menos un consumidor real, ownership, plataforma, evidencia esperada y límites de integración.

### D3 — Resultado/reporting canónico

Estado: `PARTIAL`.

`OperationResultV2` ya existe como I8-A, con verificación pendiente en `docs/verificaciones/i8-a-operation-result-v2.md`. Sin embargo, I8-B conserva deuda de convergencia en `CanonicalReport` y otros consumidores de reporting.

El executor externo debe producir/consumir el contrato operativo canónico y no definir estados o exit codes incompatibles en paralelo.

### D4 — Consumidor piloto verificado

Estado: `CANDIDATE_NOT_VERIFIED_IN_THIS_CUT`.

`Pruebas` + `AgenteCAD` es un candidato conocido, no una dependencia arquitectónica obligatoria ni evidencia suficiente para hardcodear AutoCAD.

## Problema

TestKit puede ejecutar suites reproducibles en sus plataformas soportadas, pero no tiene un contrato cerrado para runtimes propietarios, estaciones autorizadas o hardware que deban ejecutarse fuera del runner normal.

La solución sólo se justifica si E1 demuestra que el problema es reusable. Si un adapter owner-local resuelve mejor el caso, este pendiente debe cerrarse como `NO_JUSTIFICADO`.

## Objetivo

Definir, si la evidencia lo justifica, un executor genérico `external-runtime` que permita a un host declarar una ejecución externa y registrar evidencia mediante contratos neutrales de TestKit.

## Contrato conceptual — no canónico

```yaml
suite: external-runtime-example
executor: external
platform: windows
command_spec:
  schema: testkit.command_spec@1
  operation: run-host-adapter
  argv: []
requires:
  - proprietary-runtime
result_contract:
  schema: testkit.operation_result@2
artifacts:
  - "*.json"
```

Es un ejemplo de discusión, no un schema aprobado. El descriptor final debe derivarse de E1, `command_spec` e I8-B.

## Ownership

### TestKit

Si la necesidad queda demostrada:

- schema neutral del descriptor externo;
- validación estricta de plataforma/executor;
- reutilización de `testkit.command_spec@1`;
- política de timeout y códigos de salida compatible con contratos vigentes;
- resultado operativo/reporting versionado;
- registro de artifacts y metadata;
- distinción explícita entre ejecución realizada, no disponible, inválida y fallida.

### Proyecto host

- seleccionar la suite externa;
- proveer el adapter concreto;
- declarar requisitos/configuración;
- decidir qué artifacts son evidencia válida para su dominio;
- custodiar secretos, licencias y fixtures privados;
- autorizar acceso a software/hardware externo.

### Proyecto bajo prueba

- conservar runners públicos propios cuando corresponda;
- no depender de TestKit por obligación;
- producir una salida traducible por el adapter host al contrato neutral.

## Invariantes

- ningún dominio propietario hardcodeado en core;
- no existe una segunda jerarquía de comandos paralela a `command_spec`;
- reporting externo respeta `OperationResultV2` y la convergencia de I8-B;
- dependencia ausente nunca se confunde con PASS;
- TestKit registra evidencia técnica; aceptación manual/visual del dominio permanece separada;
- el host es la frontera con runtimes propietarios o hardware.

## Seguridad

- no aceptar shell arbitrario como contrato canónico;
- argv/env/cwd deben pasar admission/allowlist del contrato vigente;
- no iniciar software propietario sin autorización del host;
- no incluir secretos en JSON, logs o artifacts;
- validar containment, tamaño, tipo y origen de artifacts;
- no sobrescribir artifacts por defecto;
- registrar plataforma, executor, timestamps y exit code;
- distinguir indisponibilidad de fallo.

## Fases

### Fase 0 — evidencia de necesidad

`BLOCKED_BY_E1`.

1. inventariar consumidores reales;
2. separar requisitos comunes de detalles de dominio;
3. definir ownership;
4. decidir si corresponde executor genérico o adapters owner-local.

**PASS:** evidencia suficiente de reutilización; de lo contrario cerrar `NO_JUSTIFICADO`.

### Fase 1 — command specification

El contrato base ya existe. La tarea futura es demostrar si necesita una extensión compatible para runtimes externos.

**FAIL:** crear otro schema de comandos o depender de shell libre.

### Fase 2 — descriptor y adapter

Sólo después de Fase 0:

- schema versionado del descriptor;
- plataforma/requisitos validados;
- adapter host explícito;
- core libre de conocimiento del runtime piloto.

### Fase 3 — resultados y artifacts

Dependiente de I8-B y de no introducir una semántica paralela:

- validar resultado operativo versionado;
- mapear estado/exit code al contrato canónico;
- persistir metadata;
- registrar artifacts sin asumir semántica de dominio;
- representar explícitamente ejecución no realizada o inválida.

### Fase 4 — piloto autorizado

Demostrar en un host real que el adapter vive fuera del core, produce evidencia reproducible y que un runtime no disponible no aparece como verde.

### Fase 5 — generalidad

Contrastar contra una segunda clase de consumidor o requisitos documentados independientes. Si el contrato requiere condiciones específicas del piloto, reabrir diseño en vez de agregar aliases/excepciones.

## Criterio PASS

```text
consumidor real verificado
-> descriptor válido
-> testkit.command_spec@1
-> requisitos resueltos
-> adapter host autorizado
-> ejecución externa
-> exit code capturado
-> testkit.operation_result@2 compatible
-> artifacts registrados
-> reporting distingue ejecución real de no ejecutada
```

Además, TestKit no contiene lógica del consumidor piloto y los estados/exit codes no contradicen I8-B.

## Criterio FAIL

- hardcodear AutoCAD, PLC u otro dominio en core;
- obligar al proyecto bajo prueba a incorporar TestKit sin necesidad arquitectónica;
- shell libre como contrato;
- segundo `command_spec` o segundo resultado operativo;
- estados incompatibles con reporting canónico;
- dependencia ausente tratada como PASS;
- artifacts sin metadata de origen;
- implementar antes de E1.

## Criterio de cierre

Cerrar cuando ocurra una de estas condiciones:

1. **IMPLEMENTADO_Y_VERIFICADO:** descriptor, executor, tests, piloto y evidencia de generalidad suficientes; o
2. **NO_JUSTIFICADO:** E1 demuestra que la abstracción genérica no aporta valor frente a adapters específicos del host.

No mantener abierto indefinidamente por extensibilidad hipotética.

## Acciones excluidas

- modificar `Pruebas`, `AgenteCAD` u otro consumidor;
- incorporar dominios propietarios al core;
- instalar software propietario o ejecutar hardware real;
- publicar soporte nuevo, release o tag;
- afirmar soporte de runtime externo antes de implementación y evidencia.
