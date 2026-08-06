# Pendiente — Executor genérico de runtime externo

## Estado

Planificado. No implementado.

## Problema

TestKit puede ejecutar suites reproducibles en sus plataformas soportadas, pero hoy no tiene un contrato cerrado para runtimes propietarios o hardware que deben ejecutarse fuera del runner normal, por ejemplo AutoCAD real en una estación Windows autorizada.

La solución no debe introducir conocimiento de AutoCAD, PLC, cargadores u otro dominio dentro del core.

## Objetivo

Definir un executor genérico `external-runtime` que permita a un proyecto host declarar una ejecución externa y consumir su evidencia mediante un contrato neutral.

Contrato conceptual inicial:

```yaml
suite: autocad-runtime
executor: external
platform: windows
command: powershell
requires:
  - autocad
result_contract:
  format: json
artifacts:
  - "*.dwg"
  - "*.json"
```

El ejemplo usa AutoCAD solo como consumidor inicial. El runtime debe seguir siendo genérico.

## Ownership

### TestKit

- schema del descriptor externo;
- validación estricta de plataforma y executor;
- command specification neutral, sin shell libre;
- timeout y códigos de salida;
- contrato JSON de resultado;
- registro de artifacts y metadata;
- distinción explícita entre `NOT_RUN`, `PASS`, `FAIL`, `UNAVAILABLE` y `INVALID_RESULT`;
- integración con reporting sin declarar PASS cuando la ejecución externa no ocurrió.

### Proyecto host

- seleccionar la suite externa;
- proveer el adapter concreto;
- declarar requisitos y configuración;
- decidir qué artifacts son evidencia válida;
- custodiar secretos, licencias y fixtures privados.

### Proyecto bajo prueba

- conservar runners públicos propios y standalone;
- no depender de TestKit;
- producir el resultado contractual que el adapter host traduzca o entregue.

## Reglas de seguridad

- no aceptar strings de shell arbitrarios como contrato canónico;
- usar argv/env/cwd tipados y allowlisted;
- no iniciar software propietario salvo que el contrato del host lo autorice explícitamente;
- no incluir secretos en JSON o artifacts;
- verificar containment de rutas;
- no sobrescribir artifacts existentes por defecto;
- una dependencia ausente debe producir `UNAVAILABLE`, nunca PASS/SKIP ambiguo;
- la evidencia externa debe registrar plataforma, executor, timestamps y exit code.

## Fases

### Fase 0 — contrato

Inventariar consumidores externos y definir schema, estados, exit codes y ownership sin implementar ejecución.

### Fase 1 — command specification

Reutilizar o extender el `command_spec` neutral de TestKit para representar argv/env/cwd y requisitos de plataforma.

### Fase 2 — adapter externo

Agregar un executor que invoque un adapter host explícito. El core no conoce AutoCAD ni hardware.

### Fase 3 — resultados y artifacts

Validar JSON versionado, persistir metadata y registrar artifacts sin asumir su semántica de dominio.

### Fase 4 — consumidor piloto

Validar con un host que ejecute una estación Windows/AutoCAD y demostrar que el mismo contrato puede representar luego otro runtime externo distinto.

## Criterio PASS

```text
suite declarada
-> schema válido
-> requisitos resueltos
-> adapter host autorizado
-> ejecución externa
-> exit code capturado
-> result JSON validado
-> artifacts registrados
-> reporte TestKit distingue ejecución real de no ejecutada
```

Además:

- TestKit no contiene nombres, imports ni lógica específicos de AutoCAD;
- el proyecto bajo prueba no necesita agregar TestKit a su repositorio;
- Linux y Windows pueden leer el mismo descriptor aunque solo la plataforma declarada pueda ejecutar el runtime;
- un runtime no disponible nunca aparece como verde.

## Criterio FAIL

- incorporar AutoCAD como suite hardcodeada del core;
- requerir que el submódulo conozca TestKit;
- ejecutar mediante un comando de shell libre sin contrato tipado;
- tratar un `SKIP` o dependencia ausente como validación real;
- copiar artifacts sin metadata de origen;
- mezclar aceptación visual/manual con PASS técnico sin estados separados.

## Consumidor inicial conocido

`Pruebas` integrando `AgenteCAD`:

```text
portable contracts/fake COM -> TestKit actual
AutoCAD real                -> wrapper externo de Pruebas en Windows
future                      -> external-runtime
```

La existencia de este pendiente no cambia la matriz de soporte vigente de TestKit hasta que runtime, tests y documentación sean implementados y verificados.
