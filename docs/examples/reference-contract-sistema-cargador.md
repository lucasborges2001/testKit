# `reference-contract` en `sistemaCargador`

Este ejemplo documenta cómo usar la suite técnica `reference-contract` contra `lucasborges2001/sistemaCargador` sin acoplar TestKit a ese proyecto.

## Contrato público

La suite se invoca mediante selector tipado:

```bash
php runTest.php --suite reference-contract
```

No existen aliases públicos equivalentes ni target posicional para este contrato.

## Root contractual del proyecto

`sistemaCargador/test/.env.test.example` declara:

```env
TK_BACK_DIR=web_cargadores/PHP
TK_PUBLIC_DIR=web_cargadores/public
TK_BACK_AUTOLOAD=web_cargadores/vendor/autoload.php
TK_PUBLIC_AUTOLOAD=web_cargadores/vendor/autoload.php
```

Para `reference-contract` importan los roots de código PHP:

- back: `web_cargadores/PHP`
- front/public: `web_cargadores/public`

## Correr back

```bash
TESTKIT_PROJECT_ROOT=/path/to/sistemaCargador \
TESTKIT_REFERENCE_SCOPE=back \
php runTest.php --suite reference-contract
```

Con `TESTKIT_REFERENCE_SCOPE=back`, la suite resuelve `TK_BACK_DIR`. En este proyecto debe quedar en:

```text
web_cargadores/PHP
```

## Correr front/public

```bash
TESTKIT_PROJECT_ROOT=/path/to/sistemaCargador \
TESTKIT_REFERENCE_SCOPE=front \
php runTest.php --suite reference-contract
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
php runTest.php --suite reference-contract
```

```bash
TESTKIT_PROJECT_ROOT=/path/to/sistemaCargador \
TESTKIT_REFERENCE_ROOT=web_cargadores/public \
php runTest.php --suite reference-contract
```

`TESTKIT_REFERENCE_ROOT` debe apuntar a un directorio dentro del repo. Un root fuera del proyecto debe fallar explícitamente.

## Warnings dinámicos

Un include dinámico como este no puede validarse estáticamente:

```php
require_once $path;
```

Por default se reporta como warning y suma `dynamic_references`:

```bash
TESTKIT_REFERENCE_DYNAMIC_SEVERITY=warn \
php runTest.php --suite reference-contract
```

Opciones:

```bash
TESTKIT_REFERENCE_DYNAMIC_SEVERITY=ignore php runTest.php --suite reference-contract
TESTKIT_REFERENCE_DYNAMIC_SEVERITY=warn php runTest.php --suite reference-contract
TESTKIT_REFERENCE_DYNAMIC_SEVERITY=error php runTest.php --suite reference-contract
```

Usar `error` solo cuando el árbol ya esté limpio y se quiera bloquear nuevos includes dinámicos.

## Ignorar referencias puntuales

```bash
TESTKIT_REFERENCE_IGNORE_REFS=vendor/autoload.php \
php runTest.php --suite reference-contract
```

```bash
TESTKIT_REFERENCE_IGNORE_REF_REGEX='~/legacy/.*\.php$~' \
php runTest.php --suite reference-contract
```

Si un archivo tiene tres includes y uno se ignora, los otros dos se siguen validando. Los ignores suman en `ignored_references`.

Para ignorar archivos fuente completos:

```bash
TESTKIT_REFERENCE_IGNORE_FILES=web_cargadores/PHP/legacy/local.php \
php runTest.php --suite reference-contract
```

```bash
TESTKIT_REFERENCE_IGNORE_FILE_REGEX='~/(legacy|tmp)/~' \
php runTest.php --suite reference-contract
```

Los archivos ignorados suman `skipped_files`.

## Leer evidencia

El reporte persistido pertenece a la superficie JSON de TestKit. Para automatización, consumir los artefactos del reporte y no parsear texto de consola.

Campos específicos útiles del scanner incluyen:

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

Cada referencia incluida en detalle puede traer un estado como:

```text
ok | missing | dynamic | ignored
```

Los archivos omitidos quedan registrados como evidencia de skip cuando el reporte de la suite lo soporta.

## Qué hacer ante un include roto

1. Revisar `file` y `line` en el JSON/reporte.
2. Comparar `reference` con `resolved_as`.
3. Confirmar si el archivo destino existe realmente en el repo.
4. Si el include está mal, corregir la ruta en el proyecto.
5. Si el include es intencional pero externo/no versionado, usar un ignore puntual documentado.

No usar ignores de archivo completo como primera respuesta: reducen la superficie validada.

## Patrones calibrados

Estos patrones de `sistemaCargador` deben resolverse sin falsos positivos cuando los archivos destino existen:

```php
require_once __DIR__ . '/authConfig.php';
require_once __DIR__ . '/../utils/logs.php';
require_once __DIR__ . '/../../../PHP/utils/http.php';
require_once __DIR__ . '/service/runtime/eventoCriticoService.php';
```

Este ejemplo documenta una integración consumidora; no convierte reglas de `sistemaCargador` en lógica de dominio de TestKit.