# Pendiente — Executor genérico de runtime externo

## Estado

Planificado. No implementado.

```text
Baseline auditado: ed1a35b85f87cf124495941211d39c6e3b9b6906
Fecha: 2026-08-15
```

Este pendiente no está listo para implementación inmediata. Primero deben cerrarse sus dependencias contractuales y verificarse consumidores reales.

## Dependencias obligatorias

### D1 — I6 command specification neutral

Este pendiente depende de I6 en [`normalizacion-contratos/pendiente-interno-testkit.md`](normalizacion-contratos/pendiente-interno-testkit.md).

El executor externo **no debe inventar un segundo modelo de `command_spec`**. Debe reutilizar o extender el contrato neutral y versionado que TestKit defina para acciones/ejecución.

Estado actual: `BLOCKED_BY_I6`.

### D2 — Inventario de consumidores externos

Antes de fijar schema, estados o requisitos específicos, ejecutar E1 de [`normalizacion-contratos/pendiente-integraciones-externas.md`](normalizacion-contratos/pendiente-integraciones-externas.md).

E1 debe confirmar al menos un consumidor real, su ownership, plataforma, evidencia esperada y límites de integración.

Estado actual: `BLOCKED_BY_E1`.

### D3 — Consumidor piloto verificado

Un consumidor piloto debe aportar evidencia suficiente para validar que la abstracción no está modelada alrededor de un único producto propietario.

`Pruebas` + `AgenteCAD` es un candidato conocido, no una dependencia arquitectónica obligatoria ni una razón para hardcodear AutoCAD.

Estado actual: `CANDIDATE_NOT_VERIFIED_IN_THIS_CUT`.

## Problema

TestKit puede ejecutar suites reproducibles en sus plataformas soportadas, pero hoy no tiene un contrato cerrado para runtimes propietarios, estaciones autorizadas o hardware que deban ejecutarse fuera del runner normal.

La solución no debe introducir conocimiento de AutoCAD, PLC, cargadores u otro dominio dentro del core.

## Objetivo

Definir un executor genérico `external-runtime` que permita a un proyecto host declarar una ejecución externa y consumir su evidencia mediante un contrato neutral.

La abstracción debe resolver un problema común demostrado por consumidores reales, no anticipar extensibilidad sin evidencia.

## Contrato conceptual — no canónico

El siguiente YAML es únicamente un ejemplo de discusión. **No constituye el schema aprobado**:

```yaml
suite: autocad-runtime
executor: external
platform: windows
command_spec:
  operation: run-host-adapter
  argv: []
requires:
  - autocad
result_contract:
  format: json
artifacts:
  - "*.dwg"
  - "*.json"
```

El ejemplo usa AutoCAD como posible consumidor inicial. El runtime debe seguir siendo genérico y el shape final debe derivarse de I6 + E1.

## Ownership

### TestKit

Si las dependencias confirman la necesidad, TestKit sería owner de:

- schema neutral del descriptor externo;
- validación estricta de plataforma y executor;
- extensión del `command_spec` canónico, no shell libre;
- timeout y códigos de salida contractuales;
- contrato JSON versionado de resultado;
- registro de artifacts y metadata;
- distinción explícita entre `NOT_RUN`, `PASS`, `FAIL`, `UNAVAILABLE` e `INVALID_RESULT` si esos estados sobreviven al diseño de I8;
- integración con reporting sin declarar PASS cuando la ejecución externa no ocurrió.

Los nombres de estados y exit codes no deben cerrarse aquí antes de I8.

### Proyecto host

- seleccionar la suite externa;
- proveer el adapter concreto;
- declarar requisitos y configuración;
- decidir qué artifacts son evidencia válida para su dominio;
- custodiar secretos, licencias y fixtures privados;
- autorizar explícitamente el acceso a software o hardware externo.

### Proyecto bajo prueba

- conservar runners públicos propios y standalone cuando corresponda;
- no depender de TestKit por obligación;
- producir una salida que el adapter host pueda traducir al contrato neutral.

## Invariantes arquitectónicas

- ningún dominio propietario aparece hardcodeado en core;
- no existe una segunda jerarquía de comandos paralela a I6;
- reporting externo usa los mismos principios de schema/exit codes definidos por I8;
- una dependencia ausente nunca se confunde con PASS;
- TestKit registra evidencia técnica; la aceptación manual/visual del dominio permanece separada;
- el proyecto host es la frontera de adaptación con runtimes propietarios o hardware.

## Reglas de seguridad

- no aceptar strings de shell arbitrarios como contrato canónico;
- usar argv/env/cwd tipados y allowlisted cuando I6 los defina;
- no iniciar software propietario salvo autorización explícita del host;
- no incluir secretos en JSON, logs o artifacts;
- verificar containment de rutas;
- no sobrescribir artifacts existentes por defecto;
- registrar plataforma, executor, timestamps y exit code;
- distinguir indisponibilidad de ejecución fallida;
- validar tamaño, tipo y origen de artifacts antes de incorporarlos al reporte.

## Fases

### Fase 0 — evidencia y contrato

**Bloqueada por E1.**

1. inventariar consumidores externos reales;
2. identificar requisitos comunes y específicos;
3. definir ownership;
4. separar necesidades universales de detalles del piloto;
5. decidir si un executor genérico está justificado o si basta un adapter del host.

### Criterio de avance

Debe existir evidencia de al menos un consumidor real y una segunda clase de runtime plausible o una razón técnica suficiente para que la abstracción pertenezca a TestKit.

### Fase 1 — command specification

**Bloqueada por I6.**

Reutilizar o extender el `command_spec` canónico para representar operación, argv/env/cwd, requisitos de plataforma y política de timeout.

No crear un schema paralelo específico de `external-runtime` para representar comandos.

### Fase 2 — descriptor y adapter externo

Solo después de Fase 0 y Fase 1:

- definir schema versionado del descriptor externo;
- validar plataforma/requisitos;
- invocar un adapter host explícito;
- mantener el core libre de conocimiento de AutoCAD, PLC u otro dominio.

### Fase 3 — resultados y artifacts

**Dependiente de I8.**

- validar resultado JSON versionado;
- mapear estado y exit code al contrato canónico de reporting;
- persistir metadata;
- registrar artifacts sin asumir su semántica de dominio;
- representar explícitamente ejecución no realizada o inválida.

### Fase 4 — consumidor piloto

Validar el contrato con un host real autorizado.

El piloto debe demostrar:

- que el adapter concreto vive fuera del core;
- que la ejecución externa produce evidencia reproducible;
- que el mismo contrato no está acoplado al nombre o API del runtime piloto;
- que un runtime no disponible no aparece como verde.

### Fase 5 — segunda validación de generalidad

Antes de declarar el executor “genérico”, contrastar el contrato contra una segunda clase de consumidor o, como mínimo, contra requisitos documentados de otro runtime externo.

Si el contrato requiere condiciones específicas del piloto, reabrir diseño en lugar de agregar aliases o excepciones.

## Criterio PASS

```text
consumidor real verificado
-> descriptor con schema válido
-> command_spec canónico
-> requisitos resueltos
-> adapter host autorizado
-> ejecución externa
-> exit code capturado
-> result JSON validado
-> artifacts registrados
-> reporte TestKit distingue ejecución real de no ejecutada
```

Además:

- TestKit no contiene nombres, imports ni lógica específicos del consumidor piloto;
- el proyecto bajo prueba no necesita incorporar TestKit para cumplir el contrato;
- Linux y Windows pueden interpretar el descriptor aunque solo la plataforma declarada pueda ejecutar el runtime;
- un runtime no disponible nunca aparece como PASS;
- los estados y exit codes son compatibles con I8;
- los comandos reutilizan I6.

## Criterio FAIL

- incorporar AutoCAD, PLC u otro dominio como suite hardcodeada del core;
- requerir que el submódulo conozca TestKit sin necesidad arquitectónica;
- ejecutar mediante un comando de shell libre sin contrato tipado;
- crear un `command_spec` distinto del canónico de I6;
- definir estados/exit codes incompatibles con I8;
- tratar un `SKIP` o dependencia ausente como validación real;
- copiar artifacts sin metadata de origen;
- mezclar aceptación visual/manual con PASS técnico sin estados separados;
- implementar la abstracción antes de verificar el problema en E1.

## Consumidor piloto candidato

`Pruebas` integrando `AgenteCAD` es un candidato conocido por contexto previo:

```text
portable contracts/fake COM -> TestKit actual
AutoCAD real                -> adapter externo del host en Windows
future                      -> posible external-runtime
```

Este dato debe verificarse en E1 antes de usarlo como base del diseño.

## Criterio de cierre del pendiente

Este documento deja de ser pendiente cuando ocurre una de estas dos condiciones:

1. **Implementado y verificado:** I6/I8 están alineados, existe schema, executor, tests, piloto real y evidencia de generalidad suficiente; o
2. **No justificado:** E1 demuestra que la abstracción genérica no aporta valor suficiente y el problema se resuelve mejor con adapters específicos del host.

No mantener el pendiente abierto indefinidamente solo porque una abstracción futura podría ser útil.

## Acciones excluidas

Este documento no autoriza:

- modificar `Pruebas`, `AgenteCAD` u otro consumidor;
- incorporar AutoCAD/PLC al core;
- instalar software propietario;
- ejecutar hardware real;
- publicar soporte nuevo en la matriz;
- hacer release/tag;
- afirmar que existe runtime externo soportado antes de implementación y evidencia.
