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
│  ├─ seeding/       # baseline planning + layered/snapshot orchestration
│  ├─ store/         # adapters + maintenance + clone/restore ops
│  └─ suites/        # back_php, back_python, front_php, meta
├─ runners/          # entrypoints finos
├─ scripts/          # utilidades operativas/reportes
├─ templates/        # esqueletos genericos
└─ utils/            # helpers compartidos para tests
```

## 3) Responsabilidades por capa

- `common`: rutas y entorno base.
- `config`: parametros de ejecucion y thresholds.
- `discovery`: identifica tests y metadata (scope/tags/categoria). `front_js` consume la selección precomputada por esta misma capa cuando corre vía `FrontJsSuite`.
- `execution`: corre tests (secuencial/paralelo) y clasifica resultados.
- `reporting`: reportes utiles para decidir acciones.
- `coverage`: cobertura por archivo/modulo + zonas criticas sin cobertura.
- `seeding`: resuelve el baseline (`layered` o `snapshot`) y arma el pipeline estructural.
- `store`: contratos por motor, provision, reset, clean, restore y clone.
- `suites`: orquestacion por tecnologia/suite.
- `scripts`: lifecycle operativo del entorno y de los stores.

## 3.1) Frontera con el proyecto

- `testkit` decide entorno, workers, naming de DB/store y pipeline estructural de seeds.
- `testkit` puede materializar una baseline desde `schema/base` o desde un snapshot restaurado.
- `test/seeds` define `schema`, `base`, migraciones y validaciones estructurales; opcionalmente puede referenciar un artefacto snapshot si el proyecto quiere probar upgrades reales.
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

- Artefactos (propiedad del proyecto anfitrión):
  - `test/reports/*.json`
  - `test/<side>/<module>/report/*.json` cuando la selección pertenece a un único módulo funcional
  - `test/history/*.json`
  - `test/coverage/*`
  - `test/querylog.jsonl`

## 5.1) Contrato de reporte por suite

Además de los contadores históricos (`pass/fail/skip`), cada suite expone:

- `report_contract_version`
- `suite_status`
- `no_tests_reason`
- `runner_capabilities`

El objetivo es que el consumidor no tenga que inferir estados semánticos solo desde `exit_code`.

## 6) Extensibilidad

Para agregar una suite nueva:

1. Crear clase en `core/php/suites/`.
2. Reusar `SuiteOrchestrator` + `TestDiscovery` + `SuiteExecutor`.
3. Registrar target en `MetaRunner`.
4. Documentar comando/variables en `docs/USO.md`.

Para agregar una categoria:

1. Usar tag en nombre/ruta o metadata `TAGS:`.
2. Ejecutar con `TEST_CATEGORY=<tag>` o target dedicado.

Para agregar una estrategia de baseline:

1. Extender `SeedPipeline` con un nuevo modo explícito.
2. Mantener `layered` como comportamiento por defecto.
3. Evitar meter lógica de negocio dentro de adapters o del bootstrap de suite.

## 7) Decisiones importantes

- El meta-runner soporta PHP, Python y JS bajo convenciones de layout estándar.
- El sistema es una plataforma de opinión fuerte (opinionated) que requiere adherencia a su estructura de `test/`.
- Coverage se usa como diagnostico accionable.
- Fragilidad se detecta por historial local, no por una sola corrida.
- Runners y scripts se mantienen finos; la logica vive en `core/php`.
- El pipeline layered de seeds vive en `testkit`, no en `_support`.
- La validación de migraciones contra estado realista no debe mezclar escenarios de negocio con bootstrap estructural.
- El clone-per-worker desde una baseline es una optimización controlada; no reemplaza el aislamiento lógico de los tests.
