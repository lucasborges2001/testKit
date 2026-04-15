# Arquitectura de testkit

## 1) Propósito

`testkit` separa tres capas que no conviene mezclar:

- selección de ejecución
- lifecycle estructural del store
- tests y reglas de negocio del proyecto

La plataforma decide cómo se corre y cómo se materializa el baseline.
El proyecto decide qué SQL, qué tests y qué escenarios de dominio existen.

## 2) Componentes que importan en esta fase

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
└─ core/php/execution/
   └─ ParallelGuard.php           # admisión y locks de concurrencia
```

## 3) Flujo real de una corrida

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

La frontera relevante de esta fase empieza en el paso 5.

## 4) Frontera con el proyecto

### 4.1) Lo que pertenece a testkit

`testkit` es dueño de:

- la política de bootstrap
- el naming de DB base, baseline y worker
- provision, reset, restore y clone cuando el adapter los soporta
- el orden del seed pipeline
- los locks de concurrencia
- el manifest del baseline materializado

### 4.2) Lo que pertenece al proyecto

El proyecto es dueño de:

- `test/seeds/<driver>/schema`
- `test/seeds/<driver>/base`
- `test/seeds/<driver>/migrations`
- `test/seeds/<driver>/validations`
- `test/_support`
- tests y escenarios de negocio

El proyecto provee el contenido del baseline. `testkit` provee el lifecycle que lo materializa.

## 5) `ContractWorldBootstrap`: owner del bootstrap

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

## 6) Provision del store

`StoreMaintenance` es un façade fino. El comportamiento real vive en el adapter del driver.

## 6.1) MySQL

Ruta principal cerrada:

- `provision()`
- `reset()`
- `clean()`
- `databaseExists()`
- `dropDatabase()`
- `cloneDatabase()`
- `restoreSnapshot()`

Además, MySQL implementa una distinción explícita entre:

- credenciales runtime
- credenciales admin para flows `managed`

## 6.2) PostgreSQL

Ruta parcial:

- `provision()`
- `reset()`
- `clean()`
- `databaseExists()`
- `dropDatabase()`

No implementa:

- `cloneDatabase()`
- `restoreSnapshot()`

Por eso, el lifecycle cerrado de baseline snapshot y clone-per-worker no se extiende a PostgreSQL en esta versión.

## 7) `TEST_STORE_PROVISION` dentro de la arquitectura

El contrato `managed|external` hoy debe leerse como parte del camino cerrado MySQL.

### `managed`

Habilita a `testkit` a:

- crear la DB cuando falta
- invalidar una baseline materializada
- recrear DBs auxiliares
- clonar baseline a workers

### `external`

Le dice al framework:

- la DB ya existe
- no asumas create/drop como parte del contrato

Consecuencia arquitectónica:

- `external` puede convivir con `shared`
- `external` no cierra el camino de `clone-per-worker`
- `per_worker` sin clone solo es viable si el entorno externo ya proveyó las DB derivadas que el naming del framework espera

Eso último puede funcionar en entornos muy controlados, pero no es la ruta operativa cerrada que documenta `testkit`.

## 8) `SeedPipeline`: owner del baseline

`SeedPipeline` decide cómo se materializa la DB final antes de ejecutar tests.

Tiene dos modos cerrados:

- `layered`
- `snapshot`

## 8.1) `layered`

El baseline se construye desde el árbol estructural del proyecto.

Flujo interno:

1. resolver driver, DB y manifest plan
2. opcionalmente reutilizar baseline si el manifest coincide y la DB existe
3. abrir conexión
4. resolver plan de migraciones para baseline layered
5. resetear la DB
6. aplicar `schema/`
7. aplicar `base/`
8. aplicar migraciones ejecutables del baseline
9. aplicar `validations/`
10. escribir manifest

Punto fino importante:

- en `layered`, el baseline final no se deduce del estado previo de la DB
- se deduce del catálogo estructural y de las migraciones pedidas para esa corrida

## 8.2) `snapshot`

El baseline se construye a partir de un dump restaurado.

Flujo interno:

1. resolver artifact snapshot
2. opcionalmente reutilizar baseline si el manifest coincide y la DB existe
3. abrir conexión
4. resetear la DB
5. restaurar dump lógico
6. resolver estado de migraciones del snapshot
7. aplicar migraciones explícitas o pendientes calculadas
8. aplicar `validations/`
9. escribir manifest

Punto fino importante:

- en `snapshot`, inferir pendientes sin fuente confiable de estado sería peligroso
- por eso `MigrationStateResolver` falla de forma explícita si intentás auto-pending sin fuente válida

## 9) Catálogo de migraciones y estado

El baseline no mira “carpetas sueltas” sin contrato.

La capa estructural usa:

- `MigrationCatalog` para clasificar migraciones como:
  - `active_migration`
  - `optional_migration`
  - `historical_absorbed_change`
- `MigrationStateResolver` para producir:
  - `available`
  - `applied`
  - `pending`
  - `target`

Lectura correcta:

- `layered` decide el baseline desde catálogo + selección explícita
- `snapshot` decide los pendientes desde el estado observado o declarado del dump restaurado

## 10) Clone-per-worker dentro del diseño

Cuando `TEST_DB_STRATEGY=per_worker` y `TEST_BASELINE_CLONE_PER_WORKER=1`:

1. se resuelve un nombre de DB baseline
2. opcionalmente se invalida el baseline previo
3. se materializa una sola DB baseline
4. se clona esa DB a `w01`, `w02`, etc.

Eso reduce costo de bootstrap repetido.

No cambia el modelo de concurrencia.

No cambia el ownership.

No cambia la semántica de los tests.

## 11) `migration-contract` dentro del diseño

`migration_contract` es una suite técnica cuyo trabajo es auditar el baseline restaurado y migrado.

Su flujo es deliberadamente chico:

1. validar precondiciones contractuales
2. resolver snapshot
3. ejecutar `ContractWorldBootstrap::prepare()` en modo compartido
4. cargar el manifest escrito por `SeedPipeline`
5. escribir un reporte suite-level técnico

Lo que verifica no es “si el producto funciona”, sino “si el baseline restaurado puede bootstrapearse, migrarse y dejar evidencia estructural consistente”.

Por eso:

- requiere `snapshot`
- requiere `shared`
- requiere MySQL
- no acepta `per_worker`

## 12) Concurrencia y admisión

`ParallelGuard` modela dos problemas distintos.

## 12.1) Seguridad intra-suite

Pregunta:

- ¿esta suite puede usar `TEST_JOBS>1` con la estrategia actual?

Regla:

- si hay tests DB-sensibles y runtime DB real, `TEST_JOBS>1` exige `per_worker`
- si la suite declara política secuencial, se rechaza el paralelismo

## 12.2) Exclusividad top-level

Pregunta:

- ¿puedo lanzar otra corrida top-level sobre el mismo store base?

Regla:

- si la corrida muta el store compartido, se toma lock por `driver/db`
- una segunda corrida concurrente sobre el mismo recurso se rechaza

Consecuencia importante:

- `per_worker` aísla workers dentro de una suite
- no habilita multi-runner top-level sobre el mismo proyecto/store

## 13) Qué garantiza y qué no garantiza el lifecycle

## 13.1) Sí garantiza

- un orden estructural explícito para bootstrap
- admisión temprana de combinaciones peligrosas de `TEST_JOBS` + `TEST_DB_STRATEGY`
- restore y clone cuando el adapter los implementa
- evidencia técnica del baseline materializado mediante manifest y reportes

## 13.2) No garantiza

- aislamiento fuera de la DB
- corrección de tests frágiles
- compatibilidad de snapshot/clone en motores no implementados
- throughput con varios top-level runners sobre el mismo recurso
- diagnóstico funcional de negocio

## 14) Riesgos que quedan afuera del control de la plataforma

Aunque la DB quede aislada por worker, siguen quedando afuera:

- archivos compartidos
- sockets y puertos
- APIs externas
- colas o brokers
- cron, tiempo y relojes
- orden global entre procesos

Ese borde no es un bug documental: es el límite real del diseño.

## 15) Decisiones vigentes

- `shared` es el camino simple y secuencial.
- `clean` no existe como estrategia soportada.
- `per_worker` es la única ruta cerrada para paralelismo intra-suite con DB sensible.
- `clone-per-worker` es una optimización de baseline, no un modelo nuevo de concurrencia.
- `snapshot` existe para validar restore + migraciones reales, no para reemplazar el baseline normal del proyecto.
- `migration-contract` es un gate técnico de infraestructura, no una suite funcional.
- la ruta cerrada completa de baseline/clone/migration-contract hoy es MySQL.
