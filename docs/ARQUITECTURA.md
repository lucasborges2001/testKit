# Arquitectura de testkit

## 1) Qué responde este documento

Usar este documento para entender el modelo interno de `testkit`:

- qué capas existen
- cómo fluye una corrida
- dónde viven bootstrap, baseline, reporting y locks
- qué frontera técnica hay entre plataforma y proyecto

No usarlo para:

- quick start
- troubleshooting paso a paso
- decidir si una combinación está soportada solo por intuición
- interpretar el contrato de reportes

Para eso, leer:

- [`USO.md`](USO.md)
- [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md)
- [`CONTRATO.md`](CONTRATO.md)
- [`REPORTING_COVERAGE.md`](REPORTING_COVERAGE.md)

## 2) Capas que no conviene mezclar

`testkit` separa cuatro capas:

- selección de ejecución
- lifecycle estructural del store
- ejecución de suites
- reporting técnico

La plataforma decide cómo se corre y cómo se materializa el baseline.
El proyecto decide qué SQL, qué tests y qué escenarios de dominio existen.

## 3) Componentes principales

```text
testkit/
├─ core/php/suites/
│  ├─ MetaRunner.php              # target top-level -> suites
│  ├─ ContractWorldBootstrap.php  # política operativa de bootstrap
│  └─ *Suite.php                  # suites concretas
├─ core/php/discovery/
│  ├─ TestDiscovery.php           # discovery + filtros
│  └─ TestTagger.php              # tags por path/nombre/metadata
├─ core/php/seeding/
│  ├─ SeedPipeline.php            # baseline layered/snapshot
│  ├─ MigrationCatalog.php        # catálogo de migraciones ejecutables
│  ├─ MigrationStateResolver.php  # estado applied/pending
│  └─ BaselineManifest.php        # manifest reusable del baseline
├─ core/php/store/
│  ├─ StoreRegistry.php           # selección de adapter por driver
│  ├─ StoreMaintenance.php        # provision/reset/clone/restore
│  ├─ MysqlStoreAdapter.php       # ruta cerrada principal
│  └─ PgsqlStoreAdapter.php       # soporte parcial
├─ core/php/execution/
│  └─ ParallelGuard.php           # admisión y locks de concurrencia
└─ core/php/reporting/
   ├─ ReportSummary.php           # enriquecimiento y diagnósticos
   ├─ ResultWriter.php            # persistencia de reportes
   └─ CanonicalReport.php         # sobre canónico derivado
```

## 4) Flujo real de una corrida

Pipeline de alto nivel:

1. `bin/testkit` o `bin/testkit.ps1` resuelven repo, env y compose.
2. `runTest.php` entrega el target al `MetaRunner`.
3. `MetaRunner` traduce target a una lista de suites.
4. cada suite resuelve discovery y filtros.
5. la suite llama a `ContractWorldBootstrap::prepare()`.
6. `ContractWorldBootstrap` decide estrategia de store.
7. `StoreMaintenance` delega en el adapter del driver.
8. `SeedPipeline` materializa el baseline.
9. la suite ejecuta los tests resultantes.
10. reporting escribe artefactos bajo el repo del proyecto.

## 5) Frontera con el proyecto

### 5.1) Lo que pertenece a testkit

`testkit` es dueño de:

- la política de bootstrap
- el naming de DB base, baseline y worker
- provision, reset, restore y clone cuando el adapter los soporta
- el orden del seed pipeline
- los locks de concurrencia
- el manifest del baseline materializado
- el formato técnico de reportes

### 5.2) Lo que pertenece al proyecto

El proyecto es dueño de:

- `test/seeds/<driver>/schema`
- `test/seeds/<driver>/base`
- `test/seeds/<driver>/migrations`
- `test/seeds/<driver>/validations`
- `test/_support`
- tests y escenarios de negocio

El proyecto provee el contenido del baseline. `testkit` provee el lifecycle que lo materializa.

## 6) Selección de ejecución

La cadena conceptual es esta:

```text
target -> suites -> discovery -> tags -> scope/category/match -> selección final
```

- `MetaRunner` resuelve targets top-level a suites concretas.
- cada suite usa `TestDiscovery` y `TestTagger`.
- `scope`, `category` y `match` filtran la selección dentro de la suite.
- recién después de esa selección la suite entra a ejecución.

La taxonomía pública de ejecución vive en [`USO.md`](USO.md). Acá importa el orden interno, no la ergonomía de CLI.

## 7) Lifecycle de store y baseline

### 7.1) `ContractWorldBootstrap`

`ContractWorldBootstrap` es el punto de entrada canónico del lifecycle de store.

Responsabilidades:

- normalizar `TEST_DB_STRATEGY`
- rechazar `clean`
- decidir si el bootstrap corre una vez o por worker
- decidir si se usa baseline + clone-per-worker
- mutar temporalmente el nombre de DB por worker cuando aplica

Pseudoflujo:

```text
prepare()
├─ normaliza strategy
├─ strategy=clean          -> error explícito
├─ strategy=shared         -> bootstrapStore(base)
└─ strategy=per_worker
   ├─ clone-per-worker=0   -> bootstrapStore(worker_db_1..N)
   └─ clone-per-worker=1   -> bootstrap baseline -> clone a workers
```

Observación importante:

- `per_worker` sigue siendo una política intra-suite
- no es un scheduler de múltiples corridas top-level independientes

### 7.2) `StoreMaintenance` y adapters

`StoreMaintenance` es un façade fino. El comportamiento real vive en el adapter del driver.

- MySQL implementa la ruta principal cerrada: provision, reset, clean, clone, restore
- PostgreSQL tiene soporte parcial y no cierra snapshot/clone en esta versión

El detalle de qué está soportado o no soportado pertenece al contrato. No hay que leer esta capa como promesa de soporte futuro.

### 7.3) `SeedPipeline`

`SeedPipeline` decide cómo se materializa la DB final antes de ejecutar tests.

Tiene dos modos cerrados:

- `layered`
- `snapshot`

`layered` construye el baseline desde el árbol estructural del proyecto.
`snapshot` restaura un dump y luego resuelve/aplica migraciones según el estado declarado u observado.

### 7.4) Catálogo y estado de migraciones

La capa estructural usa:

- `MigrationCatalog` para clasificar migraciones ejecutables
- `MigrationStateResolver` para producir `available`, `applied`, `pending` y `target`

Lectura correcta:

- `layered` decide el baseline desde catálogo + selección explícita
- `snapshot` decide los pendientes desde el estado observado o declarado del dump restaurado

### 7.5) `migration-contract`

`migration_contract` es una suite técnica cuyo trabajo es auditar el baseline restaurado y migrado.

Su flujo es deliberadamente chico:

1. validar precondiciones contractuales
2. resolver snapshot
3. ejecutar `ContractWorldBootstrap::prepare()` en modo compartido
4. cargar el manifest escrito por `SeedPipeline`
5. escribir un reporte suite-level técnico

Verifica consistencia técnica del bootstrap y de la evidencia estructural. No valida negocio.

## 8) Concurrencia y admisión

`ParallelGuard` modela dos problemas distintos.

### 8.1) Seguridad intra-suite

Pregunta:

- ¿esta suite puede usar `TEST_JOBS>1` con la estrategia actual?

Regla:

- si hay tests DB-sensibles y runtime DB real, `TEST_JOBS>1` exige `per_worker`
- si la suite declara política secuencial, se rechaza el paralelismo

### 8.2) Exclusividad top-level

Pregunta:

- ¿puedo lanzar otra corrida top-level sobre el mismo store base?

Regla:

- si la corrida muta el store compartido, se toma lock por `driver/db`
- una segunda corrida concurrente sobre el mismo recurso se rechaza

Consecuencia importante:

- `per_worker` aísla workers dentro de una suite
- no habilita multi-runner top-level sobre el mismo proyecto/store

## 9) Reporting dentro del diseño

La ejecución produce dos capas:

- datos top-level del reporte suite/meta
- `canonical_report`, que es una envoltura normalizada derivada

`ResultWriter` persiste los JSON latest/timestamped.
`ReportSummary` enriquece estados, diagnósticos y agregados.
`CanonicalReport` construye una vista normalizada para consumo más uniforme.

El contrato de esos reportes no se define acá. Se define en [`REPORTING_COVERAGE.md`](REPORTING_COVERAGE.md).

## 10) Borde de la arquitectura

Aunque la DB quede aislada por worker, siguen quedando afuera:

- archivos compartidos
- sockets y puertos
- APIs externas
- colas o brokers
- cron, tiempo y relojes
- orden global entre procesos

Ese borde no es un bug documental: es el límite real del diseño.
