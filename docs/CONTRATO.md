# Contrato de adopción de testkit

## 1) Qué responde este documento

Este documento fija el contrato mínimo entre `testkit` y el proyecto integrador.

Usarlo para responder estas preguntas:

- qué necesita un proyecto para adoptar `testkit`
- qué controla `testkit`
- qué sigue siendo responsabilidad del proyecto
- qué motores y servicios están realmente soportados
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
- [`../SUPPORT_MATRIX.md`](../SUPPORT_MATRIX.md)

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
- `test/seeds/<driver>/migrations.disabled` permite desactivar el catálogo runtime y reconstruir solo desde `schema + base + validations`

Para baseline `snapshot`:

- en esta fase la ruta cerrada es MySQL
- el proyecto debe declarar un dump lógico resoluble
- la fuente admitida es:
  - `TEST_BASELINE_SNAPSHOT_FILE`
  - o metadata/report JSON compatible usado para resolver ese artefacto
- `snapshot` no reemplaza el catálogo estructural del proyecto; solo cambia el punto de partida del bootstrap

### 2.4) Contrato de referencias PHP

`reference-contract` es una suite técnica estática. No ejecuta tests de dominio y no toca DB/store.

Contrato vigente:

- target principal: `php runTest.php reference-contract`
- aliases: `references`, `php-references`
- suite interna: `reference_contract`
- alcance actual: solo includes PHP (`require`, `require_once`, `include`, `include_once`)
- resolución estática: literales simples y concatenaciones simples con `__DIR__`
- dinámicos: warning por defecto, ignorables o convertibles en error por env
- salida: `.testkit/reports/reference_contract/`

El root no es todo el repo por default. Se resuelve así:

1. `TESTKIT_REFERENCE_ROOT`, si existe.
2. `TESTKIT_REFERENCE_SCOPE=back` usa `TK_BACK_DIR`.
3. `TESTKIT_REFERENCE_SCOPE=front` usa `TK_FRONT_DIR`; si falta, usa `TK_PUBLIC_DIR`.
4. Sin scope explícito, default `back`.

No forman parte de este corte:

- imports JS
- assets HTML/CSS
- Markdown
- rutas HTTP
- inferencia semántica de factories, autoloaders o constantes de negocio

## 3) Qué controla testkit

`testkit` es dueño de la plataforma de ejecución. Controla:

- selección de target y suite
- discovery y filtros compartidos
- lifecycle de bootstrap estructural
- naming de DB/store derivado para workers y baseline
- restricciones operativas de estrategia (`shared`, `per_worker`, `clean`)
- formato y ubicación de artefactos operativos del framework
- reportes técnicos y diagnósticos del framework
- scanner técnico de referencias estáticas cuando se usa `reference-contract`

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
- corregir referencias PHP rotas detectadas por `reference-contract`

`testkit` no inventa fixtures de negocio ni corrige automáticamente tests frágiles.

## 5) Strict vs configurable

| Tipo | Qué entra |
|---|---|
| Estricto | root `test/`, env dentro del repo, semántica de `TEST_DB_STRATEGY`, restricciones de `migration-contract`, ownership del lifecycle de bootstrap |
| Configurable | rutas base del proyecto, tagging por path/metadata, cantidad de workers, provisionado `managed|external`, sufijo de workers, root de artefactos, root/scope del scanner de referencias |
| Fuera de contrato | reglas de negocio, builders del proyecto, fixtures funcionales, políticas de datos del dominio |

Regla práctica: lo configurable puede cambiar la forma de resolver o ejecutar; no cambia quién es dueño de cada responsabilidad.

## 6) Matriz de soporte por motor/servicio

Esta tabla es el contrato vigente. No debe leerse como roadmap.

| Componente | Estado | Contrato actual | Límites |
|---|---|---|---|
| MySQL | cerrado / principal | provision, reset, baseline layered, snapshot restore, clone para `per_worker`, `migration-contract` | requiere env DB válido; `per_worker` no habilita múltiples runners top-level |
| PostgreSQL | parcial / experimental | adapter runtime con provision/reset básico cuando el env está completo | sin snapshot restore cerrado; sin clone cerrado; no es ruta cerrada de `migration-contract` |
| Redis | auxiliar | servicio disponible si el stack lo levanta | sin lifecycle estructural en core PHP; no participa en baseline/snapshot/clone |
| Influx | auxiliar / perfilado | profiling/reporting si está habilitado | no es store driver principal; no participa en seed/bootstrap estructural |
| `reference-contract` | técnico / estático | scanner PHP de includes resolubles | no analiza JS/CSS/HTML/HTTP ni expresiones dinámicas de negocio |

Semántica operativa:

- MySQL es la única ruta principal cerrada en esta fase.
- PostgreSQL puede existir como infraestructura parcial, pero no debe venderse como equivalente a MySQL.
- Redis no tiene lifecycle estructural equivalente dentro del core PHP.
- Influx funciona como servicio auxiliar/perfilado, no como store driver principal.
- `reference-contract` es independiente del store.

## 7) Límites vigentes

Estos límites forman parte del contrato actual y no deben maquillarse como soporte general.

### 7.1) Paralelismo

- `per_worker` aísla workers dentro de una misma suite.
- No vuelve seguro correr varios runners top-level en paralelo sobre el mismo proyecto/store.
- Si una suite usa DB real con `TEST_JOBS > 1`, la ruta cerrada es `TEST_DB_STRATEGY=per_worker`.
- `reference-contract` no toca store y declara top-level parallel safe.

### 7.2) Estrategias de store

- `shared` está soportado.
- `per_worker` está soportado con naming derivado por worker.
- `clean` no está implementado como modo operativo. Intentar usarlo debe fallar explícitamente.

### 7.3) Motores

- MySQL es la ruta principal cerrada para bootstrap, snapshot restore y clone por worker.
- PostgreSQL puede existir como infraestructura de test, pero snapshot/clone no forman parte del contrato cerrado de esta fase.
- Redis no tiene lifecycle estructural equivalente dentro del core PHP.
- Influx no es un store driver principal; su contrato se limita a servicio auxiliar/perfilado.

### 7.4) migration-contract

`migration-contract` no es una suite funcional general. Su contrato es más chico:

- valida bootstrap técnico de una baseline restaurada
- exige `TEST_BASELINE_MODE=snapshot`
- exige una fuente de snapshot resoluble
- exige `TEST_DB_STRATEGY=shared`
- en esta fase está cerrado solo para MySQL

No reemplaza tests funcionales del proyecto.

### 7.5) reference-contract

`reference-contract` no es un linter completo ni un autoloader analyzer.

En este corte:

- no escanea todo el repo por default
- solo procesa archivos `.php`
- solo falla rutas literales/resolubles que apuntan a archivos inexistentes
- registra dinámicos según `TESTKIT_REFERENCE_DYNAMIC_SEVERITY`
- corta por timeout, cantidad máxima de archivos, tamaño por archivo y cantidad máxima de violaciones

### 7.6) Heurísticas

No están garantizados como verdad semántica:

- fragility hints
- agrupación de familias de fallo
- señales de triage derivadas de historial
- clasificación de un include dinámico como seguro o inseguro

Sirven para priorizar análisis, no para cerrar diagnóstico.

### 7.7) Capability doctor

`doctor` puede emitir una sección de capability basada en la config visible del wrapper.

Eso **no** cambia este contrato:

- no convierte `UNKNOWN` en `PASS`
- no vuelve seguro un path runtime que no fue ejecutado
- no reemplaza una corrida real
- no autoriza varios runners top-level en paralelo
- no convierte PostgreSQL, Redis o Influx en rutas estructurales cerradas

Sirve para detectar contradicciones visibles y para no vender compatibilidad que el wrapper no puede demostrar todavía.

Semántica de capability:

- `PASS`: ruta visible cerrada o declaración auxiliar correctamente clasificada
- `WARN`: señal visible degradada, parcial o poco confiable
- `UNKNOWN`: no hay evidencia suficiente para afirmar compatibilidad
- `FAIL`: contradicción visible con el contrato

`UNKNOWN` no es `PASS` disfrazado y `WARN` no vuelve soportada una ruta no cerrada.

## 8) Qué no soporta o no garantiza hoy

- throughput normal basado en varios runners top-level concurrentes sobre la misma DB
- soporte general de snapshot/clone para motores no MySQL
- lifecycle estructural Redis o Influx dentro del core PHP
- lifecycle de negocio dentro del seed de infraestructura
- inferencia automática de reglas funcionales del proyecto
- convertir tests no deterministas en tests seguros por configuración
- contrato general de assets/imports fuera de includes PHP

## 9) Criterio de lectura

Si una necesidad del proyecto contradice este documento, no hay que reinterpretar el contrato.

Hay dos opciones válidas:

- adaptar el proyecto al contrato actual
- registrar la diferencia como deuda o feature futura, sin venderla como soportada hoy

## 10) `reference-contract`

`reference-contract` es una suite técnica de análisis estático para includes PHP. Su alcance actual es cerrado y no debe reinterpretarse como analizador general de assets.

Incluye únicamente:

- `require`
- `require_once`
- `include`
- `include_once`

Queda fuera de contrato en esta fase:

- JS imports
- CSS `url()`
- HTML `href/src`
- Markdown links
- rutas HTTP
- autofix de includes
- integración con Composer autoload más allá de no romperlo

Reglas de root:

1. `TESTKIT_REFERENCE_ROOT` si está definido.
2. `TESTKIT_REFERENCE_SCOPE=back` usa `TK_BACK_DIR`.
3. `TESTKIT_REFERENCE_SCOPE=front` usa `TK_FRONT_DIR`; si no existe, usa `TK_PUBLIC_DIR`.
4. Default de scope: `back`.
5. `TESTKIT_REFERENCE_ROOT=.` es la única forma explícita de pedir escaneo del repo completo.

La suite no toca DB/store y no participa en bootstrap estructural.

## Anexo: `reference-contract`

`reference-contract` es una suite técnica de consistencia referencial. Su contrato actual está deliberadamente cerrado a includes PHP estáticos.

Alcance soportado:

- `require`
- `require_once`
- `include`
- `include_once`
- expresiones resolubles con strings literales y `__DIR__`

Fuera de contrato en esta fase:

- imports JS
- assets CSS/HTML
- links Markdown
- rutas HTTP
- autoload avanzado de Composer
- autofix

Esta suite no debe tomar lock de store ni disparar bootstrap estructural: no muta DB, no ejecuta tests de dominio y no reemplaza coverage ni smoke tests funcionales.
