# Contrato de adopción de testkit

## 1) Qué responde este documento

Este documento fija el contrato mínimo entre `testkit` y el proyecto integrador.

Usarlo para responder estas preguntas:

- qué necesita un proyecto para adoptar `testkit`
- qué controla `testkit`
- qué sigue siendo responsabilidad del proyecto
- qué casos no están soportados o no están garantizados

No usarlo para:

- quick start operativo
- troubleshooting paso a paso
- arquitectura interna detallada
- lectura de reportes o coverage

Para eso, leer:

- [`USO.md`](USO.md)
- [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md)
- [`ARQUITECTURA.md`](ARQUITECTURA.md)
- [`REPORTING_COVERAGE.md`](REPORTING_COVERAGE.md)

## 2) Adopción mínima

Un proyecto puede adoptar `testkit` si cumple estas condiciones mínimas.

### 2.1) Repositorio y env

Requisitos estrictos:

- `TESTKIT_PROJECT_ROOT` debe apuntar al repo del proyecto probado.
- El env de tests debe existir en una de estas rutas:
  - `<project>/test/.env.test` (preferido)
  - `<project>/.env.test`
- Ese archivo debe vivir dentro del repo montado. `doctor` falla si queda afuera.

### 2.2) Layout de tests

Requisitos estrictos:

- `testkit` espera un root contractual `test/`.
- Las suites usan tests bajo `test/back/` y `test/front/` según corresponda.
- Si el proyecto no usa una suite, no necesita poblar su árbol asociado.

Convenciones configurables:

- algunas rutas y convenciones de discovery pueden ajustarse por configuración
- esos ajustes cambian la resolución de archivos, no el ownership del lifecycle

### 2.3) Contrato mínimo de store

Solo aplica a suites que tocan DB/store real.

Requisitos estrictos:

- el proyecto debe declarar credenciales runtime válidas para el store activo
- si `TEST_STORE_PROVISION=managed`, también debe declarar credenciales admin
- si usa bootstrap estructural, debe proveer la estructura de `test/seeds/<driver>/`

Para baseline `layered`:

- `test/seeds/<driver>/schema/`
- `test/seeds/<driver>/base/`
- `test/seeds/<driver>/validations/` es opcional
- `test/seeds/<driver>/migrations/<id>/` aplica solo si el proyecto decide usar migraciones explícitas

Para baseline `snapshot`:

- el proyecto debe declarar un dump lógico resoluble
- la fuente admitida es:
  - `TEST_BASELINE_SNAPSHOT_FILE`
  - o metadata/report JSON compatible usado para resolver ese artefacto
- `snapshot` no reemplaza el catálogo estructural del proyecto; solo cambia el punto de partida del bootstrap

## 3) Qué controla testkit

`testkit` es dueño de la plataforma de ejecución. Controla:

- selección de target y suite
- discovery y filtros compartidos
- lifecycle de bootstrap estructural
- naming de DB/store derivado para workers y baseline
- restricciones operativas de estrategia (`shared`, `per_worker`, `clean`)
- formato y ubicación de artefactos operativos del framework
- reportes técnicos y diagnósticos del framework

En otras palabras: `testkit` decide cómo se corre la plataforma.

## 4) Qué sigue siendo responsabilidad del proyecto

El proyecto integrador sigue siendo dueño de:

- los tests de dominio
- builders, helpers y asserts en `test/_support`
- el contenido SQL de `schema`, `base`, `migrations` y `validations`
- la definición de escenarios de negocio
- la semántica funcional que los tests deben validar
- la elección y mantenimiento de servicios externos que el proyecto necesite
- la calidad y determinismo de sus propios tests

`testkit` no inventa fixtures de negocio ni corrige automáticamente tests frágiles.

## 5) Strict vs configurable

| Tipo | Qué entra |
|---|---|
| Estricto | root `test/`, env dentro del repo, semántica de `TEST_DB_STRATEGY`, restricciones de `migration-contract`, ownership del lifecycle de bootstrap |
| Configurable | rutas base del proyecto, tagging por path/metadata, cantidad de workers, provisionado `managed|external`, sufijo de workers, root de artefactos |
| Fuera de contrato | reglas de negocio, builders del proyecto, fixtures funcionales, políticas de datos del dominio |

Regla práctica: lo configurable puede cambiar la forma de resolver o ejecutar; no cambia quién es dueño de cada responsabilidad.

## 6) Límites vigentes

Estos límites forman parte del contrato actual y no deben maquillarse como soporte general.

### 6.1) Paralelismo

- `per_worker` aísla workers dentro de una misma suite.
- No vuelve seguro correr varios runners top-level en paralelo sobre el mismo proyecto/store.
- Si una suite usa DB real con `TEST_JOBS > 1`, la ruta cerrada es `TEST_DB_STRATEGY=per_worker`.

### 6.2) Estrategias de store

- `shared` está soportado.
- `per_worker` está soportado con naming derivado por worker.
- `clean` no está implementado como modo operativo. Intentar usarlo debe fallar explícitamente.

### 6.3) Motores

- MySQL es la ruta principal cerrada para bootstrap, snapshot restore y clone por worker.
- PostgreSQL puede existir como infraestructura de test, pero snapshot/clone no forman parte del contrato cerrado de esta fase.
- Redis no tiene lifecycle estructural equivalente dentro del core PHP.

### 6.4) migration-contract

`migration-contract` no es una suite funcional general. Su contrato es más chico:

- valida bootstrap técnico de una baseline restaurada
- exige `TEST_BASELINE_MODE=snapshot`
- exige una fuente de snapshot resoluble
- exige `TEST_DB_STRATEGY=shared`
- en esta fase está cerrado solo para MySQL

No reemplaza tests funcionales del proyecto.

### 6.5) Heurísticas

No están garantizados como verdad semántica:

- fragility hints
- agrupación de familias de fallo
- señales de triage derivadas de historial

Sirven para priorizar análisis, no para cerrar diagnóstico.

### 6.6) Capability doctor

`doctor` puede emitir una sección de capability basada en la config visible del wrapper.

Eso **no** cambia este contrato:

- no convierte `UNKNOWN` en soporte faltante
- no vuelve seguro un path runtime que no fue ejecutado
- no reemplaza una corrida real
- no autoriza varios runners top-level en paralelo

Sirve para detectar contradicciones visibles y para no vender compatibilidad que el wrapper no puede demostrar todavía.

## 7) Qué no soporta o no garantiza hoy

- throughput normal basado en varios runners top-level concurrentes sobre la misma DB
- soporte general de snapshot/clone para motores no MySQL
- lifecycle de negocio dentro del seed de infraestructura
- inferencia automática de reglas funcionales del proyecto
- convertir tests no deterministas en tests seguros por configuración

## 8) Criterio de lectura

Si una necesidad del proyecto contradice este documento, no hay que reinterpretar el contrato.

Hay dos opciones válidas:

- adaptar el proyecto al contrato actual
- registrar la diferencia como deuda o feature futura, sin venderla como soportada hoy
