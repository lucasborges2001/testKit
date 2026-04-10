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
│  └─ suites/        # back_php, back_python, front_php, front_js, migration_contract, meta
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
- `php runTest.php migration-contract` -> suite `migration_contract`

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


## 3.2) Baseline reutilizable

- `testkit` puede materializar una baseline por `layered` o por `snapshot`.
- La baseline activa puede persistirse como artefacto derivado en `.testkit/baselines/<driver>/<db>.manifest.json`.
- Ese manifest sirve para reuse/diagnóstico; no redefine el catálogo estructural de migraciones.
- En `per_worker` con `TEST_BASELINE_CLONE_PER_WORKER=1`, primero se prepara/reutiliza la baseline y luego se clona a cada worker.
- La invalidación explícita (`TEST_BASELINE_INVALIDATE=1`) borra manifest y obliga a reconstruir.


## 3.3) Suite de contrato de migración

- `migration_contract` no descubre tests de dominio.
- Usa `ContractWorldBootstrap` como chequeo técnico del baseline restaurado.
- Su salida principal es un reporte suite-level con estado del bootstrap, snapshot usado y manifest resultante.
- Está pensada como gate de infraestructura antes de correr suites funcionales sobre un baseline recién restaurado.


## 3.2) Integración explícita con backupkit

Cuando `TEST_BASELINE_MODE=snapshot`, `testkit` ya no depende únicamente de un path hardcodeado al dump. Puede resolver el baseline desde tres fuentes ordenadas por precedencia:

1. snapshot explícito (`TEST_BASELINE_SNAPSHOT_FILE`)
2. metadata sidecar de `backupkit`
3. reporte JSON de `backupkit`

La resolución vive en `core/php/seeding/BackupkitArtifactResolver.php`.

El objetivo no es duplicar a `backupkit`, sino consumir sus artefactos verificados como input del lifecycle de testing. `backupkit` sigue siendo dueño de:

- generación del dump
- hash/metadata del artefacto
- verify-artifact
- restore-test declarativo

`testkit` consume ese resultado para construir la baseline de pruebas y escribir un manifest local con el origen resuelto.


## Estado de migración

Para baseline `snapshot`, `testkit` incorpora una capa explícita de resolución de estado:

- `MigrationStateResolver` detecta migraciones `available/applied/pending`
- `SeedPipeline` decide si usa cálculo incremental o una lista explícita
- `MigrationContractSuite` reporta el estado observado y las pendientes aplicadas

La idea es separar:
- origen del baseline
- detección del punto de partida
- selección de migraciones pendientes
- validación estructural posterior
