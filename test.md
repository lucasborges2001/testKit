# Tests

Este directorio contiene los tests del proyecto, organizados por módulo.

## Estructura

- `test/back/<modulo>/...`        → tests de backend (PHP)
- `test/front/<modulo>/...` → tests de frontend (JS/PHP)

Runners:

- `test/back/runTestBack.php`             → runner BACK (PHP)
- `test/front/runTestFront.php`      → runner FRONT (PHP)
- `test/front/runTestFront.mjs`      → runner FRONT (JS)
- `test/runTest.php`                  → **meta-runner** (orquesta los anteriores, sin mezclar discovery)

Helpers compartidos:

- `test/utils/` → helpers y harness centralizados para tests (PHP/JS)

Dentro de cada módulo se sugiere:

- `unit/`         (sin IO)
- `integration/`  (DB / archivos / includes reales)
- `e2e/`          (HTTP real, flujos completos)

## Metadata recomendada (para que sea autoexplicativo)

Cada archivo de test debería arrancar con un header que explique **qué prueba**.

Templates listos:

- `test/templates/TEST_TEMPLATE_BACK.php`
- `test/templates/TEST_TEMPLATE_FRONT.mjs`

Formato sugerido:

- `TEST:` nombre corto
- `SCOPE:` unit|integration|e2e
- `QUÉ PRUEBA:` bullets verificables
- `DEPENDE DE:` DB/servicios/endpoints
- `DATOS:` seeds necesarias

## Ejecutar todo con un comando

Desde el root del proyecto:

```bash
php test/runTest.php
```

Para correr solo un subset:

```bash
php test/runTest.php back
php test/runTest.php frontTestFront
php test/runTest.php frontTestFront-php
php test/runTest.php frontTestFront-js
```

(Alternativa con env: `TEST_TARGET=back|front|front-php|front-js`).

## Ejecución por runner

BACK (PHP):

```bash
php test/back/runTest.php
```

FRONT (PHP):

```bash
# Preferred wrapper (compat):
php test/front/run.php
# Original runner file:
php test/front/runTestFront.php
```

FRONT (JS):

```bash
# Preferred wrapper (compat):
node test/front/run.mjs
# Original runner file:
node test/front/runTestFront.mjs
```

## Variables soportadas (comunes)

- `TEST_SCOPE=unit|integration|e2e|all` (default: `all`)
- `TEST_FAIL_FAST=1|0` (default: `1`)
- `TEST_MATCH=<substring>` (filtra por ruta/nombre)

## Entorno de configuración (PHP)

Los runners PHP setean por defecto:

- `DB_ENV_PATH=<root>/env.test` si existe (si no, `env.debug` y luego `.env`)
- `APP_ENV=test`
- `APP_DEBUG=1`

Si querés forzar otro `.env`, exportá `DB_ENV_PATH` antes de correr.
