# Pendiente — Captura acotada de fallos en `runSuiteConfig`

## Estado

Activo.

## Evidencia verificada

Baseline funcional observado:

```text
4d6bb194278dd7de9145390f5ef637a4e162cf74
perf(runner): avoid loading hidden successful output
```

En ese baseline quedó resuelto el consumo innecesario de memoria para comandos exitosos ejecutados con `output=failures`:

- `success_stderr=hide`: no se materializan `stdout` ni `stderr` en memoria;
- `success_stderr=show`: sólo se materializa `stderr`;
- `output=live`: no cambia.

El gap restante está en los comandos fallidos. `runners/runSuiteConfig.php` todavía carga completos los archivos temporales de `stdout` y `stderr` mediante `file_get_contents()` antes de imprimir el diagnóstico. Un fallo con salida muy grande puede, por lo tanto, presionar o agotar el `memory_limit` del proceso runner.

### Evidencia adicional — Locker remoto / 2026-09-03

La validación remota real de Locker aportó un segundo problema, independiente del consumo de memoria: un excerpt acotado puede ser técnicamente válido pero poco accionable si dedica todo su presupuesto al **prefijo** de una salida larga.

Durante la depuración de `notification_operator_resend_http` y `runtime_env`, el fragmento publicado conservó preámbulos de preparación (`doctor`, build/up, checks PASS) mientras una causa tardía quedaba fuera del excerpt. El host tuvo que añadir instrumentación temporal propia para hacer visible el tail y recuperar la línea accionable.

Por tanto, este pendiente no debe cerrarse implementando solamente «primeros N bytes/líneas». La política de fallo debe conservar información útil de ambos extremos o aplicar una estrategia equivalente orientada a la causa, sin dejar de ser bounded.

Como mínimo, cuando exista truncado:

- no gastar todo el presupuesto en el prefijo si el proceso terminó en fallo;
- conservar un tail acotado o una selección equivalente `failure-biased`;
- indicar explícitamente que hubo truncado;
- mantener `stdout` y `stderr` distinguibles;
- preservar redacción de secretos y límites deterministas;
- no obligar a los hosts a reimplementar una segunda política de excerpts para obtener la causa tardía.

Esta evidencia no mueve a TestKit la orquestación Git/systemd del host. El owner de este pendiente sigue siendo exclusivamente el reporting/capture genérico de TestKit.

## Objetivo

Hacer que un fallo de gran volumen mantenga diagnóstico accionable en consola sin requerir cargar la salida completa en memoria y sin perder la posibilidad de recuperar la evidencia completa.

## Invariantes

La solución no debe modificar:

- el exit code contractual del runner;
- la semántica de `output=live|failures`;
- `success_stderr=hide|show`;
- el orden lógico de `stdout` y `stderr` en el diagnóstico;
- la identificación del comando fallido;
- el comportamiento de los PASS ya cerrado en `4d6bb19`;
- `NO_COLOR` / `FORCE_COLOR`;
- selección, composición ni `fail_fast` de suites.

No se acepta silenciar un fallo ni descartar la salida completa sin una ruta recuperable.

## Contrato objetivo

Para `output=failures` y un comando no exitoso:

```text
proceso
-> stdout/stderr escritos a temporales
-> lector acotado para consola
-> selección de excerpt útil para diagnóstico (head+tail o equivalente)
-> indicador explícito de truncado cuando corresponda
-> evidencia completa persistida mediante el reporting canónico
-> temporales eliminados sólo después de persistir/consumir la evidencia
-> exit code original preservado
```

La consola debe mostrar un excerpt acotado y accionable. El volumen máximo debe ser determinista y estar cubierto por tests. La implementación exacta puede usar reparto head/tail u otra estrategia equivalente, pero debe demostrar que una causa ubicada al final no desaparece sistemáticamente por priorizar el comienzo.

## Decisión abierta

La ruta y ownership del artifact completo deben integrarse con el reporting canónico de TestKit. No crear un árbol paralelo de artifacts dentro de `runSuiteConfig.php`.

Antes de implementar, verificar qué componente debe registrar el artifact bajo `.testkit/reports/` y qué metadata mínima necesita para ser consumible por hosts y agentes.

`FailureExcerpt` existente opera sobre `string` completo; puede reutilizarse para reglas de presentación, pero por sí solo no resuelve el problema de memoria. La lectura acotada debe ocurrir antes de materializar el archivo completo.

## Dependencias

1. Resolver ownership y ruta canónica del artifact completo.
2. Definir un lector de archivo acotado o streaming reusable, sin parsear stdout como contrato.
3. Definir una política de selección que no priorice siempre el prefijo de una salida fallida.
4. Mantener el contrato actual de `runSuiteConfig.php` para hosts existentes.

## Criterio de aceptación

### PASS funcional

- un comando fallido que produzca al menos 24 MiB de stdout no provoca `Allowed memory size exhausted` con un `memory_limit` de prueba acotado;
- equivalente para stderr de gran volumen;
- el exit code del runner sigue indicando fallo requerido;
- consola contiene identificación de suite, comando y exit code;
- consola no contiene la salida completa cuando supera el límite;
- aparece una marca explícita de truncado;
- un fixture con causa sólo al final conserva esa causa en el excerpt o en una selección equivalente claramente accionable;
- el artifact completo conserva la evidencia que no se mostró en consola;
- stdout y stderr siguen siendo distinguibles;
- un fallo pequeño conserva diagnóstico útil sin degradación innecesaria;
- `output=live` mantiene comportamiento histórico.

Si el machine result expone excerpts, debe ser posible saber que fueron truncados mediante metadata explícita o un marcador contractual equivalente; no depender de inferirlo por longitud.

### PASS de regresión

Como mínimo:

```bash
php -l runners/runSuiteConfig.php
php tests/framework/test_run_suite_config_output_contract.php
php tests/framework/test_run_suite_config_compact_contract.php
php tests/framework/run.php
php scripts/static_checks.php php
git diff --check
```

El test focal debe incluir fixtures de salida grande bajo `memory_limit` acotado, una causa ubicada al final de la salida y comprobación del artifact completo; no limitarse a buscar una cadena en consola.

## FAIL

La fase no cierra si ocurre cualquiera de estos casos:

- se sigue usando `file_get_contents()` completo sobre salidas fallidas grandes antes de acotar;
- el runner sobrevive sólo aumentando `memory_limit`;
- la consola se trunca sin avisar;
- una causa tardía desaparece sistemáticamente porque el excerpt conserva sólo el prefijo;
- se pierde la salida completa;
- se inventa un path de artifacts incompatible con reporting existente;
- cambia el exit code o la semántica `live|failures`;
- la solución requiere cambios en `Base`, `Pruebas` u otros consumidores.

## Riesgos

- duplicar infraestructura de artifacts ya existente;
- dejar temporales huérfanos ante excepciones o terminación temprana;
- truncar el comienzo o final que contiene la causa real del error;
- mezclar stdout/stderr de forma que el diagnóstico sea ambiguo;
- introducir una segunda política de excerpts distinta de reporting.

## Rollback

La implementación futura debe poder revertirse restaurando el comportamiento de captura de fallos sin afectar el contrato de PASS introducido en `4d6bb19`.

## Criterio de cierre

Eliminar este documento sólo cuando:

1. el bounded capture esté implementado;
2. la selección de excerpt preserve causas tardías de forma verificable;
3. el artifact completo tenga ownership canónico verificado;
4. los contratos focalizados y framework estén verdes;
5. no quede trabajo funcional pendiente de esta fase.

Si sólo falta ejecutar evidencia en un entorno específico, mover esa evidencia a `docs/verificaciones/` según la frontera documental vigente.

## Acciones excluidas

- modificar `Base` o `Pruebas` para resolver este pendiente;
- cambiar gitlinks como parte de la implementación funcional;
- añadir aliases o nuevas variantes de `output`;
- ejecutar workflows manuales o declarar CI verde sin evidencia;
- desplegar o tocar servicios reales.
