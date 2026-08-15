# Contrato de adopción de testkit

## Autoridad y alcance

Este documento fija el contrato público mínimo entre `testkit` y un proyecto integrador.

Para selectores, nombres de suites, grupos y categorías, la autoridad canónica es [`CONTRACT_REGISTRY.md`](CONTRACT_REGISTRY.md), generado desde `Testkit\Core\Config\ContractRegistry`.

Si un ejemplo histórico contradice ese registro o el parser de `RunRequest`, el ejemplo histórico está desactualizado.

## Selector público

Toda ejecución de tests declara exactamente uno de:

```text
--suite <nombre>
--group <nombre>
--category <nombre>
```

No forman parte del contrato público:

- targets posicionales;
- aliases de suites o grupos;
- `TEST_TARGET`;
- `TESTKIT_TARGET_*`;
- inferencia de selector desde otras variables.

Ejemplos válidos:

```bash
php runTest.php --suite back-php
php runTest.php --group all --list
php runTest.php --category smoke
php runTest.php --suite reference-contract
php runTest.php --suite infra-php
```

## Selección explícita de archivos

La selección pública adicional es:

```text
--test <repo-relative>          # repetible
--selection-file <repo-relative>
```

Reglas:

- `--test` y `--selection-file` son mutuamente excluyentes;
- las rutas deben ser repo-relative;
- rutas absolutas y traversal con `..` se rechazan;
- no existe selección pública implícita por substring.

Ejemplos:

```bash
php runTest.php --suite back-php \
  --test test/back/auth/login.test.php

php runTest.php --suite front-php \
  --selection-file .testkit/selection.front_php.txt
```

Las variables internas `TEST_MATCH*` que todavía puedan existir en el runtime son bridges de transición y no deben documentarse como API pública nueva. Su eliminación está registrada en `docs/pendientes/normalizacion-contratos/pendiente-interno-testkit.md`.

## Adopción mínima

### Proyecto y env

- `TESTKIT_PROJECT_ROOT` apunta al repositorio probado.
- El env de tests vive dentro del proyecto, normalmente en `test/.env.test` o `.env.test`.
- `TESTKIT_ENV_FILE` puede seleccionar explícitamente el archivo admitido.
- Un env fuera del proyecto montado debe fallar.

### Layout

El root contractual de tests es `test/`.

Las suites funcionales usan, según corresponda:

```text
test/back/
test/front/
test/infra/
```

Los tests, fixtures y reglas de dominio pertenecen al proyecto consumidor, no a `testkit`.

## Store estructural

`TEST_STORE_DRIVER` es el único selector del store estructural.

Valores exactos:

```text
mysql
pgsql
none
```

No seleccionan store:

```text
DB_DRIVER
TEST_DB_DRIVER
TEST_DB_DSN
nombres de DB
credenciales
TESTKIT_STACK
```

Semántica general:

| Driver | Estado contractual |
|---|---|
| `mysql` | ruta principal cerrada |
| `pgsql` | parcial/experimental |
| `none` | proyecto sin store runtime |

Para proyectos sin store:

```env
TEST_STORE_DRIVER=none
TEST_STORE_PROVISION=external
```

## Estrategias de DB

| Estrategia | Estado |
|---|---|
| `shared` | soportada |
| `per_worker` | soportada dentro de una suite; no habilita múltiples runners top-level concurrentes |
| `clean` | reconocida pero no implementada; debe rechazarse |

`per_worker` no convierte una DB compartida en segura para varios procesos top-level independientes.

## Baseline y bootstrap

Para suites con store real, el proyecto es dueño de sus seeds y estructura bajo `test/seeds/<driver>/`.

Baseline `layered` puede usar:

```text
test/seeds/<driver>/schema/
test/seeds/<driver>/base/
test/seeds/<driver>/validations/
test/seeds/<driver>/migrations/<id>/
```

Baseline `snapshot` está cerrado actualmente en la ruta MySQL y requiere una fuente resoluble.

`migration-contract` es una suite técnica, no una suite funcional general. El contrato cerrado exige MySQL, snapshot resoluble, estrategia `shared` y `TEST_JOBS=1`.

## Suite `reference-contract`

Selector público:

```bash
php runTest.php --suite reference-contract
```

No existen aliases públicos equivalentes.

Alcance actual:

- `require`;
- `require_once`;
- `include`;
- `include_once`;
- literales y concatenaciones simples resolubles con `__DIR__`.

No es un analizador general de JS, CSS, HTML, Markdown, rutas HTTP ni reglas semánticas de autoloaders.

El root se resuelve mediante `TESTKIT_REFERENCE_ROOT` o el scope configurado. El scanner no debe interpretarse como test funcional de dominio.

## Suite `infra-php`

Selector público:

```bash
php runTest.php --suite infra-php
```

No existen `infra`, `http` u otros aliases públicos equivalentes.

`infra-php` pertenece a pruebas operacionales del host: HTTP real, Docker, seguridad operacional, cookies, límites de autenticación y validaciones de infraestructura.

No reemplaza `back-php` para dominio funcional PHP.

Convención recomendada:

```env
TK_INFRA_PHP_TEST_ROOTS=test/infra
TK_INFRA_PHP_TEST_PATTERNS=*.test.php
```

## Stack de servicios

`TESTKIT_STACK` describe servicios a levantar; no selecciona el store estructural.

Nombres canónicos documentados:

```text
mysql
pg
redis
influx
```

Los aliases que el runtime todavía normaliza son deuda interna de I3 y no deben usarse en configuración nueva.

## Coverage

La raíz canónica es:

```env
TEST_COVERAGE_ROOT=.testkit/coverage
```

Los artifacts se organizan por `suite_id`, por ejemplo:

```text
.testkit/coverage/back_php
.testkit/coverage/front_php
```

`TEST_COVERAGE_DIR` y rutas legacy bajo `test/coverage/` todavía pueden existir por compatibilidad interna, pero no forman parte de la configuración pública recomendada. Su eliminación está pendiente en I5.

## Ownership

### testkit controla

- parsing del selector público;
- discovery y ejecución;
- lifecycle técnico de bootstrap/store;
- restricciones de concurrencia;
- ubicación y formato de artifacts técnicos;
- reporting y evidencia del framework.

### el proyecto consumidor controla

- tests de dominio;
- helpers, builders y asserts propios;
- contenido de seeds y fixtures;
- reglas funcionales;
- dependencias externas de sus pruebas;
- determinismo de sus escenarios.

`testkit` no debe incorporar reglas específicas del dominio consumidor.

## Evidencia y estados

La consola es presentación humana. Para automatización y agentes, la evidencia contractual debe provenir de JSON/reportes persistidos cuando esa superficie exista.

No interpretar:

```text
UNKNOWN = PASS
WARN = soporte cerrado
SKIP = validación real
BLOCKED = PASS
```

## Límites vigentes

No se garantiza:

- varios runners top-level concurrentes sobre el mismo store;
- snapshot/clone cerrado para motores distintos de MySQL;
- lifecycle estructural de Redis o Influx;
- runtime Docker Desktop real en Windows sólo por pasar validaciones estáticas;
- terminación nativa completa de `ProcessRunner` en Windows mientras siga abierto su pendiente específico;
- inferencia automática de reglas de negocio.

## Regla de lectura

Una necesidad que contradiga este contrato debe resolverse de una de dos formas:

1. adaptar el consumidor al contrato vigente; o
2. registrar una deuda/feature nueva sin presentar soporte inexistente como vigente.
