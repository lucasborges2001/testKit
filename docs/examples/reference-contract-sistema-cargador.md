# `reference-contract` en `sistemaCargador`

Este ejemplo documenta cómo usar la suite técnica `reference-contract` contra `lucasborges2001/sistemaCargador` sin acoplar `testKit` a ese proyecto.

## Root contractual del proyecto

`sistemaCargador/test/.env.test.example` declara:

```env
TK_BACK_DIR=web_cargadores/PHP
TK_PUBLIC_DIR=web_cargadores/public
TK_BACK_AUTOLOAD=web_cargadores/vendor/autoload.php
TK_PUBLIC_AUTOLOAD=web_cargadores/vendor/autoload.php
```

Para `reference-contract` importan los dos roots de código PHP:

- back: `web_cargadores/PHP`
- front/public: `web_cargadores/public`

## Correr back

```bash
TESTKIT_PROJECT_ROOT=/path/to/sistemaCargador \
TESTKIT_REFERENCE_SCOPE=back \
php runTest.php reference-contract
```

Con `TESTKIT_REFERENCE_SCOPE=back`, la suite resuelve `TK_BACK_DIR`. En este proyecto debe quedar en:

```text
web_cargadores/PHP
```

## Correr front/public

```bash
TESTKIT_PROJECT_ROOT=/path/to/sistemaCargador \
TESTKIT_REFERENCE_SCOPE=front \
php runTest.php reference-contract
```

Con `TESTKIT_REFERENCE_SCOPE=front`, la suite usa `TK_FRONT_DIR` si existe. Si no existe, usa `TK_PUBLIC_DIR`. En `sistemaCargador`, ese fallback debe quedar en:

```text
web_cargadores/public
```

## Limitar scope manualmente

Para aislar un árbol concreto sin depender de `TK_BACK_DIR`/`TK_PUBLIC_DIR`:

```bash
TESTKIT_PROJECT_ROOT=/path/to/sistemaCargador \
TESTKIT_REFERENCE_ROOT=web_cargadores/PHP \
php runTest.php reference-contract

TESTKIT_PROJECT_ROOT=/path/to/sistemaCargador \
TESTKIT_REFERENCE_ROOT=web_cargadores/public \
php runTest.php reference-contract
```

`TESTKIT_REFERENCE_ROOT` siempre debe apuntar a un directorio dentro del repo. Un root fuera del repo falla con `reference_root_outside_repo`.

## Warnings dinámicos

Un include dinámico como este no puede validarse estáticamente:

```php
require_once $path;
```

Por default se reporta como warning y suma `dynamic_references`:

```bash
TESTKIT_REFERENCE_DYNAMIC_SEVERITY=warn php runTest.php reference-contract
```

Opciones:

```bash
TESTKIT_REFERENCE_DYNAMIC_SEVERITY=ignore php runTest.php reference-contract
TESTKIT_REFERENCE_DYNAMIC_SEVERITY=warn php runTest.php reference-contract
TESTKIT_REFERENCE_DYNAMIC_SEVERITY=error php runTest.php reference-contract
```

Usá `error` solo cuando el árbol ya esté limpio y quieras bloquear nuevos includes dinámicos.

## Ignorar referencias puntuales sin ocultar todo el archivo

Ejemplos:

```bash
TESTKIT_REFERENCE_IGNORE_REFS=vendor/autoload.php php runTest.php reference-contract
TESTKIT_REFERENCE_IGNORE_REF_REGEX='~/legacy/.*\.php$~' php runTest.php reference-contract
```

Regla operativa: si un archivo tiene tres includes y uno se ignora, los otros dos se siguen validando. Los ignores suman en `ignored_references`, no desaparecen silenciosamente.

Para ignorar archivos fuente completos:

```bash
TESTKIT_REFERENCE_IGNORE_FILES=web_cargadores/PHP/legacy/local.php php runTest.php reference-contract
TESTKIT_REFERENCE_IGNORE_FILE_REGEX='~/(legacy|tmp)/~' php runTest.php reference-contract
```

Los archivos ignorados suman `skipped_files`.

## Leer el JSON latest

El último reporte queda en:

```text
.testkit/reports/reference_contract/reference_contract_latest.json
```

Campos útiles:

```json
{
  "ok_references": 0,
  "broken_references": 0,
  "dynamic_references": 0,
  "ignored_references": 0,
  "skipped_files": 0,
  "references": []
}
```

Cada referencia incluida en detalle trae `status`:

```text
ok | missing | dynamic | ignored
```

Los archivos omitidos quedan en `skipped_file_details` con `status: skipped`.

## Qué hacer ante un include roto

Si aparece una referencia rota:

1. Mirá `file` y `line` en consola o JSON.
2. Compará `reference` con `resolved_as`.
3. Confirmá si el archivo destino existe realmente en el repo.
4. Si el include está mal, corregí la ruta en el proyecto.
5. Si el include es intencional pero externo/no versionado, agregá un ignore puntual con `TESTKIT_REFERENCE_IGNORE_REFS` o `TESTKIT_REFERENCE_IGNORE_REF_REGEX`.

No uses `IGNORE_FILES` como primera respuesta: eso oculta todo el archivo origen y reduce el valor del check.

## Patrones reales calibrados

Estos patrones de `sistemaCargador` deben resolverse sin falsos positivos cuando los archivos destino existen:

```php
require_once __DIR__ . '/authConfig.php';
require_once __DIR__ . '/../utils/logs.php';
require_once __DIR__ . '/../../../PHP/utils/http.php';
require_once __DIR__ . '/service/runtime/eventoCriticoService.php';
```
