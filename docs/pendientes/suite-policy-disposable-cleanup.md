# Pendiente — Suite policy para cleanup de runtimes `disposable`

## Estado

`PENDIENTE`

## Owner

TestKit suite-policy / host-suite runner.

## Evidencia

La policy v1 actual distingue:

```text
safe
disposable
persistent
hardware
```

pero sólo exige metadata de cleanup para suites `persistent` con comandos. Una suite `disposable` puede declarar su lifecycle de forma voluntaria, pero TestKit no ofrece hoy un contrato genérico que permita a un orquestador remoto demostrar, antes de ejecutar, que el runtime aislado será destruido incluso en fallo.

La validación remota real de Locker hizo visible esta diferencia. Para poder ejecutar de forma fail-closed suites como:

```text
mysql_schema
mysql_destructive
plc_offline
```

el host tuvo que imponer una regla adicional propia (`cleanup.guaranteed=true`) y, donde no existía un teardown suficientemente explícito, añadir wrappers con `trap` que destruyen el stack TestKit y eliminan archivos de entorno temporales.

Ese endurecimiento es correcto en Locker, pero duplicar la misma semántica en cada host reduce la reutilización del catálogo declarativo TestKit.

## Problema

`risk=disposable` expresa **dónde puede mutar** la suite, pero no expresa de manera suficientemente contractual **quién posee el teardown ni si está garantizado**.

No deben confundirse estos conceptos:

```text
risk
  naturaleza/alcance de la mutación

cleanup lifecycle
  ownership y garantía de restauración/destrucción
```

Una suite disposable sin cleanup verificable no debe convertirse automáticamente en `persistent`: sigue operando sobre un runtime aislado, pero su ciclo de vida está incompleto desde el punto de vista de una ejecución remota unattended.

## Objetivo

Extender la policy genérica de TestKit —mediante una policy futura o un opt-in backward-compatible— para que el lifecycle de suites `disposable` sea machine-readable y validable antes de ejecutar.

La solución debe permitir que un host-agent/orquestador pueda responder de forma genérica:

```text
¿esta suite disposable posee cleanup contractual?
¿quién lo ejecuta?
¿está garantizado ante fallo/señal razonablemente manejable?
```

sin conocer comandos de dominio del consumidor.

## Contrato deseado

No fijar prematuramente una única forma de implementación. Como mínimo evaluar metadata que permita distinguir estrategias como:

```text
self
  la suite/runner del host destruye su propio runtime en finally/trap

testkit-managed
  el lifecycle está enteramente delegado a una primitive TestKit con teardown contractual
```

Los nombres finales pueden cambiar durante diseño. Lo importante es que:

- TestKit valide metadata, no ejecute cleanup de dominio arbitrario;
- el host siga siendo dueño de fixtures y reglas de negocio;
- una estrategia declarada tenga semántica verificable y tests de framework;
- composites propaguen correctamente la exigencia de sus hijos;
- `safe` no adquiera una obligación artificial de cleanup;
- `hardware` no se fuerce dentro de este mismo contrato: un HIL pasivo/read-only tiene lifecycle distinto de un runtime disposable;
- la policy v1 existente siga siendo backward compatible.

## Compatibilidad

No endurecer silenciosamente policy v1 de forma que consumidores existentes dejen de ejecutar.

Opciones aceptables para diseño futuro:

1. introducir `suite_policy_version=2`;
2. añadir una capability/flag explícita de catálogo que active el contrato reforzado;
3. otra evolución equivalente que preserve semántica v1 documentada.

La elección debe apoyarse en tests de compatibilidad, no sólo en documentación.

## Criterio de aceptación

La fase puede cerrarse cuando exista un contrato reusable que demuestre como mínimo:

- una suite disposable optada al contrato reforzado sin metadata de lifecycle falla policy **antes** de ejecutar comandos;
- metadata malformada falla con exit contractual de configuración/policy;
- una suite disposable con lifecycle válido puede ejecutarse normalmente;
- un composite hereda/propaga correctamente la exigencia de lifecycle de sus hijos;
- una suite `persistent` conserva el comportamiento contractual existente;
- catálogos policy v1 existentes continúan funcionando sin migración forzada;
- TestKit no necesita conocer SQL, Docker Compose ni cleanup específico del dominio consumidor;
- el metadata resultante es suficiente para que un remote host-agent decida de forma genérica si admite la suite unattended.

## Tests mínimos esperados

Agregar cobertura focal al framework para, como mínimo:

```text
disposable sin lifecycle bajo contrato reforzado -> reject

disposable con lifecycle válido -> accept

composite con hijo disposable inválido -> reject

policy v1 legacy -> comportamiento histórico

persistent -> comportamiento histórico preservado
```

Los nombres exactos de fixtures/tests quedan abiertos a la implementación.

## Fuera de alcance

Este pendiente **no** debe absorber:

- `snapshot|host_live` de Locker u otros hosts;
- lectura/copia de `.env` de consumidores;
- Git fetch/checkout/worktrees;
- systemd, polling o publicación GitHub;
- lifecycle de Docker Compose específico de Locker;
- reglas SQL/PLC/HIL del consumidor;
- comandos de cleanup enviados desde requests remotos.

Esas responsabilidades siguen fuera del motor TestKit salvo que una primitive genérica independiente sea diseñada y demostrada por más de un consumidor.

## Criterio de cierre documental

Cuando el contrato y sus tests existan, mover cualquier integración pendiente de consumidores a `docs/verificaciones/` y eliminar este archivo del inventario activo. No declarar cierre sólo porque Locker ya posea wrappers propios.
