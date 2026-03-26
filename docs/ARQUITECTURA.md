# Arquitectura de TestKit

## 1) Criterio estructural

Alineado con `docs/Estructura.md` y `docs/Reestructura.md`:

- archivos chicos
- responsabilidades separadas
- dependencias explicitas
- convenciones claras
- complejidad util, no cosmetica

## 2) Estructura interna

```text
testkit/
├─ bin/
├─ compose*.yaml
├─ core/php/
│  ├─ common/        # paths/env/bootstrap
│  ├─ config/        # lectura de config/thresholds
│  ├─ discovery/     # discovery + tags + filtros
│  ├─ execution/     # procesos, pool, resultados
│  ├─ reporting/     # consola, historial, json reports
│  ├─ coverage/      # merge + diagnostico de coverage
│  └─ suites/        # back_php, back_python, front_php, meta
├─ runners/          # entrypoints finos
├─ scripts/          # utilidades operativas/reportes
├─ templates/        # esqueletos genericos
└─ utils/            # helpers compartidos para tests
```

## 3) Responsabilidades por capa

- `common`: rutas y entorno base.
- `config`: parametros de ejecucion y thresholds.
- `discovery`: identifica tests y metadata (scope/tags/categoria).
- `execution`: corre tests (secuencial/paralelo) y clasifica resultados.
- `reporting`: reportes utiles para decidir acciones.
- `coverage`: cobertura por archivo/modulo + zonas criticas sin cobertura.
- `suites`: orquestacion por tecnologia/suite.
- `scripts`: lifecycle operativo del entorno y de los stores.

## 3.1) Frontera con el proyecto

- `testkit` decide entorno, workers, naming de DB/store y pipeline estructural de seeds.
- `test/seeds` define solo `schema`, `base`, migraciones y validaciones estructurales.
- `test/_support` queda para builders, helpers, asserts y composición de escenarios del proyecto.
- Los escenarios de negocio no entran por el lifecycle de `testkit`.

## 4) Entry points

- `runTest.php` -> meta runner
- `runners/runTest.php` -> seleccion de targets
- `runners/runTestBack.php` -> suite `back_php`
- `runners/runTestBackPython.php` -> suite `back_python`
- `runners/runFrontTest.php` -> suite `front_php`
- `runners/runFrontTest.mjs` -> suite `front_js`

## 5) Contratos de salida

- Exit codes:
  - `0`: pass
  - `1`: fail
  - `2`: skip
  - `3`: error de runner/config

- Artefactos:
  - `testkit/_out/reports/*.json`
  - `testkit/_out/history/*.json`
  - `testkit/_out/coverage/*`

## 6) Extensibilidad

Para agregar una suite nueva:

1. Crear clase en `core/php/suites/`.
2. Reusar `SuiteOrchestrator` + `TestDiscovery` + `SuiteExecutor`.
3. Registrar target en `MetaRunner`.
4. Documentar comando/variables en `docs/USO.md`.

Para agregar una categoria:

1. Usar tag en nombre/ruta o metadata `TAGS:`.
2. Ejecutar con `TEST_CATEGORY=<tag>` o target dedicado.

## 7) Decisiones importantes

- El meta-runner soporta PHP, Python y JS sin mezclar logica de dominio.
- Coverage se usa como diagnostico accionable.
- Fragilidad se detecta por historial local, no por una sola corrida.
- Runners y scripts se mantienen finos; la logica vive en `core/php`.
- El pipeline layered de seeds vive en `testkit`, no en `_support`.
