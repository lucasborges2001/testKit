# Pendiente — Entrypoint público para catálogos de suites de hosts

## Estado

`IMPLEMENTED_PENDING_RUNNER`

Baseline de implementación: `2b49da63580b642c9e4afaf8edb403e7a4281af7`.

## Problema

Los hosts que consumen TestKit a través de Base dependen hoy de un internal profundo:

```text
Base/testkit/runners/runSuiteConfig.php
```

Eso acopla cada host a la organización interna de TestKit y obliga a repetir wrappers equivalentes.

## Objetivo

Exponer una interfaz pública estable para ejecutar un catálogo declarativo propiedad del host sin mover al core lógica de dominio ni orquestación Git/remota.

## Implementación

- `bin/testkit-suite-config`: entrypoint público;
- conserva los argumentos y códigos de salida de `runSuiteConfig.php`;
- usa `TESTKIT_PROJECT_ROOT` explícito o, para uso interactivo, el directorio actual;
- no requiere `.env.test` porque el runner declarativo ya opera sin ese requisito;
- no incorpora GitHub, systemd, publicación documental ni reglas de un host;
- `tests/framework/test_suite_config_entrypoint.sh`: smoke de referencia;
- `docs/HOST_SUITE_ENTRYPOINT.md`: contrato público.

## Verificación pendiente

La implementación no se considera cerrada hasta observar la cadena integrada:

```text
repo padre
-> Base/bin/base-testkit
-> Base/testkit/bin/testkit-suite-config
-> catálogo del repo padre
-> result-json válido
```

El piloto es `Pruebas`; la ejecución real queda delegada al runner remoto.

## PASS

- `bash tests/framework/test_suite_config_entrypoint.sh` pasa;
- Base consume sólo el entrypoint público de TestKit;
- Pruebas consume sólo el entrypoint público de Base;
- `--list`, suite, `--result-json` y exits 0/1/2 conservan semántica;
- evidencia remota del SHA integrado confirma pipeline/producto sin parsear consola.

## FAIL

- un host sigue necesitando `runners/runSuiteConfig.php` para la ruta migrada;
- se introduce un segundo schema de resultado;
- el wrapper cambia exits o interpreta el output de consola;
- TestKit adquiere conocimiento de Pruebas, GitHub, systemd o gitlinks.

## Criterio de cierre

Tras evidencia integrada PASS en Pruebas, actualizar documentación canónica si hiciera falta y eliminar este pendiente si no queda deuda específica de TestKit.
